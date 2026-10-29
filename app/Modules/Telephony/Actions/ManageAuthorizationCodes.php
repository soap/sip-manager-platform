<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Exceptions\StaleVersion;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The authorization codes of the tenant in the context (plan D7). A code is shown once, when it
 * is made or reset; only its hash is kept. Every change writes the tenant's calling dialplan again,
 * once the tenant has a node.
 */
class ManageAuthorizationCodes
{
    public function __construct(
        private Audit $audit,
        private RequestProvisioning $provisioning,
        private TenantContext $context,
    ) {}

    /**
     * @param  list<CallCategory>  $categories
     * @param  string|null  $code  the digits, or null to make some up
     * @return array{authorization_code: AuthorizationCode, code: string, operation: ProvisioningOperation|null}
     */
    public function create(string $label, array $categories, ?CarbonInterface $expiresAt = null, ?int $userId = null, ?string $code = null): array
    {
        $tenant = $this->tenant();

        if (AuthorizationCode::query()->count() >= AuthorizationCode::MAX_PER_TENANT) {
            throw ValidationException::withMessages(['code' => 'A tenant has at most '.AuthorizationCode::MAX_PER_TENANT.' authorization codes.']);
        }

        $code ??= $this->unusedCode($tenant);

        try {
            return DB::transaction(function () use ($tenant, $label, $categories, $expiresAt, $userId, $code): array {
                $authorizationCode = AuthorizationCode::query()->create([
                    'label' => $label,
                    'user_id' => $userId,
                    'categories' => $this->values($categories),
                    'node_hash' => AuthorizationCode::nodeHash($tenant, $code),
                    'expires_at' => $expiresAt,
                ]);

                $operation = $this->provision($authorizationCode, OperationType::AuthorizationCodeCreated);

                $this->audit->record('authorization_code.created', $authorizationCode, $this->summary($authorizationCode));

                return ['authorization_code' => $authorizationCode, 'code' => $code, 'operation' => $operation];
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    /**
     * With a version, refuses a change made against an older copy (optimistic lock).
     *
     * @param  list<CallCategory>  $categories
     *
     * @throws StaleVersion
     */
    public function update(AuthorizationCode $authorizationCode, string $label, array $categories, AuthorizationCodeStatus $status, ?CarbonInterface $expiresAt, ?int $userId = null, ?int $expectedVersion = null): ?ProvisioningOperation
    {
        // only the scheduler expires a code; an expired one comes back as active with a new expiry
        if ($status === AuthorizationCodeStatus::Expired && $authorizationCode->status !== AuthorizationCodeStatus::Expired) {
            throw ValidationException::withMessages(['status' => 'A code expires by its expiry date.']);
        }

        if ($status === AuthorizationCodeStatus::Active && $expiresAt?->isPast() === true) {
            throw ValidationException::withMessages(['expires_at' => 'Choose an expiry in the future, or none.']);
        }

        $before = $this->summary($authorizationCode);

        return DB::transaction(function () use ($authorizationCode, $label, $categories, $status, $expiresAt, $userId, $expectedVersion, $before): ?ProvisioningOperation {
            $updated = AuthorizationCode::query()
                ->whereKey($authorizationCode->id)
                ->when($expectedVersion !== null, fn ($query) => $query->where('version', $expectedVersion))
                ->update([
                    'label' => $label,
                    'user_id' => $userId,
                    'categories' => json_encode($this->values($categories)),
                    'status' => $status,
                    'expires_at' => $expiresAt,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw StaleVersion::of('authorization_code', 'authorization code');
            }

            $authorizationCode->refresh();
            $after = $this->summary($authorizationCode);

            $this->audit->record('authorization_code.updated', $authorizationCode, ['from' => $before, 'to' => $after]);

            return $before === $after ? null : $this->provision($authorizationCode, OperationType::AuthorizationCodeUpdated);
        });
    }

    /**
     * New digits for the code, shown once; the old ones stop working once the node has the change.
     *
     * @return array{code: string, operation: ProvisioningOperation|null}
     */
    public function reset(AuthorizationCode $authorizationCode, ?string $code = null): array
    {
        $tenant = $this->tenant();
        $code ??= $this->unusedCode($tenant);

        try {
            return DB::transaction(function () use ($authorizationCode, $tenant, $code): array {
                $authorizationCode->update(['node_hash' => AuthorizationCode::nodeHash($tenant, $code), 'version' => $authorizationCode->version + 1]);

                $operation = $this->provision($authorizationCode, OperationType::AuthorizationCodeUpdated);

                $this->audit->record('authorization_code.reset', $authorizationCode, ['label' => $authorizationCode->label]);

                return ['code' => $code, 'operation' => $operation];
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->taken();
        }
    }

    public function delete(AuthorizationCode $authorizationCode): ?ProvisioningOperation
    {
        return DB::transaction(function () use ($authorizationCode): ?ProvisioningOperation {
            $authorizationCode->delete();

            $operation = $this->provision($authorizationCode, OperationType::AuthorizationCodeDeleted);

            $this->audit->record('authorization_code.deleted', $authorizationCode, ['label' => $authorizationCode->label]);

            return $operation;
        });
    }

    /**
     * Marks the codes past their expiry as expired and takes them off their tenants' nodes, for the
     * scheduler.
     *
     * @return int how many codes expired
     */
    public function expireDue(): int
    {
        $due = AuthorizationCode::withoutTenantScope()
            ->where('status', AuthorizationCodeStatus::Active)
            ->where('expires_at', '<=', now())
            ->with('tenant')
            ->get();

        foreach ($due as $authorizationCode) {
            $this->context->runFor($authorizationCode->tenant, fn () => DB::transaction(function () use ($authorizationCode): void {
                $authorizationCode->update(['status' => AuthorizationCodeStatus::Expired, 'version' => $authorizationCode->version + 1]);

                $this->provision($authorizationCode, OperationType::AuthorizationCodeUpdated);

                $this->audit->record('authorization_code.expired', $authorizationCode, ['label' => $authorizationCode->label], $authorizationCode->tenant);
            }));
        }

        return $due->count();
    }

    /**
     * The tenant's calling dialplan on its node; nothing to write before it has one.
     */
    private function provision(AuthorizationCode $authorizationCode, OperationType $type): ?ProvisioningOperation
    {
        $assignment = $authorizationCode->tenant()->first()?->pbxAssignment;

        return $assignment === null ? null : $this->provisioning->forCalling(
            $assignment->pbx_node_id,
            $authorizationCode->tenant_id,
            $type,
            AuthorizationCode::MORPH,
            $authorizationCode->id,
        );
    }

    private function unusedCode(Tenant $tenant): string
    {
        do {
            $code = AuthorizationCode::newCode();
        } while (AuthorizationCode::withoutTenantScope()->where('tenant_id', $tenant->id)->where('node_hash', AuthorizationCode::nodeHash($tenant, $code))->exists());

        return $code;
    }

    private function taken(): ValidationException
    {
        return ValidationException::withMessages(['code' => 'Another authorization code of this tenant has these digits.']);
    }

    private function tenant(): Tenant
    {
        return $this->context->tenant() ?? throw new LogicException('Authorization codes are managed within a tenant.');
    }

    /**
     * @param  list<CallCategory>  $categories
     * @return list<string> in the order of CallCategory
     */
    private function values(array $categories): array
    {
        return array_values(array_map(
            fn (CallCategory $category): string => $category->value,
            array_filter(CallCategory::cases(), fn (CallCategory $category): bool => in_array($category, $categories, true)),
        ));
    }

    /**
     * Never the code or its hash.
     *
     * @return array{label: string, categories: list<string>, status: string, expires_at: string|null, user_id: int|null}
     */
    private function summary(AuthorizationCode $authorizationCode): array
    {
        return [
            'label' => $authorizationCode->label,
            'categories' => $authorizationCode->categories,
            'status' => $authorizationCode->status->value,
            'expires_at' => $authorizationCode->expires_at?->toIso8601String(),
            'user_id' => $authorizationCode->user_id,
        ];
    }
}
