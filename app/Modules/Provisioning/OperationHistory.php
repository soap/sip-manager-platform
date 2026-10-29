<?php

namespace App\Modules\Provisioning;

use App\Modules\Provisioning\Actions\RetryOperation;
use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * The newest provisioning operations of one tenant, or of every tenant for the platform admin, as
 * the Operations pages list them.
 */
class OperationHistory
{
    public const LIMIT = 100;

    /** The filters of the pages: everything, what is still on its way, or what failed. */
    public const FILTERS = ['all', 'active', 'failed'];

    public function __construct(private RetryOperation $retry) {}

    /**
     * @param  value-of<self::FILTERS>  $filter
     * @return Collection<int, array{id: string, type: value-of<OperationType>, status: value-of<OperationStatus>, subject: array{kind: 'number'|'domain'|'calling'|'other', number: string|null}, tenant: array{name: string, slug: string}|null, node: string|null, attempts: int, error_code: string|null, error_message: string|null, next_attempt_at: string|null, created_at: string|null, updated_at: string|null, retryable: bool}>
     */
    public function latest(?string $tenantId, string $filter): Collection
    {
        $operations = ProvisioningOperation::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($filter === 'active', fn ($query) => $query->whereIn('status', [OperationStatus::Pending, OperationStatus::Running, OperationStatus::Retrying]))
            ->when($filter === 'failed', fn ($query) => $query->where('status', OperationStatus::Failed))
            ->with(['revision.node:id,name', 'tenant:id,name,slug'])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $retryable = $this->retry->retryable($operations);

        return $operations->map(fn (ProvisioningOperation $operation): array => [
            'id' => $operation->id,
            'type' => $operation->type->value,
            'status' => $operation->status->value,
            'subject' => $this->subject($operation->revision),
            'tenant' => $operation->tenant?->only('name', 'slug'),
            'node' => $operation->revision?->node?->name,
            'attempts' => $operation->attempts,
            'error_code' => $operation->error_code,
            'error_message' => $operation->error_message,
            'next_attempt_at' => $operation->next_attempt_at?->toIso8601String(),
            'created_at' => $operation->created_at?->toIso8601String(),
            'updated_at' => $operation->updated_at?->toIso8601String(),
            'retryable' => $retryable->contains($operation->id),
        ]);
    }

    /**
     * What the operation writes: a number of the tenant (without its code), its SIP domain or its
     * calling dialplan.
     *
     * @return array{kind: 'number'|'domain'|'calling'|'other', number: string|null}
     */
    private function subject(?ConfigRevision $revision): array
    {
        $number = $revision?->targetNumber();

        return match (true) {
            $number !== null => ['kind' => 'number', 'number' => substr($number, Tenant::CODE_DIGITS)],
            $revision?->targetDomainCode() !== null => ['kind' => 'domain', 'number' => null],
            $revision?->targetCallingCode() !== null => ['kind' => 'calling', 'number' => null],
            default => ['kind' => 'other', 'number' => null],
        };
    }
}
