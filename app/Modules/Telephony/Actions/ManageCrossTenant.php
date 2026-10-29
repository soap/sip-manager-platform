<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Tenancy\Enums\CrossTenantInbound;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calls between tenants on the same node (plan D6): which tenants may call this one, and which it
 * can call. Each caller's tenant holds a marker exten for every tenant that takes its calls, so a
 * change here writes the calling dialplan of each tenant that gains or loses this one.
 */
class ManageCrossTenant
{
    /** Tenants a tenant can list as allowed callers. */
    public const MAX_ALLOWED = 200;

    public function __construct(private Audit $audit, private RequestProvisioning $provisioning) {}

    /**
     * @param  list<string>  $allowedCodes  tenant codes, for "selected"
     * @return list<ProvisioningOperation> one for each calling tenant gained or lost
     */
    public function setInbound(Tenant $tenant, CrossTenantInbound $inbound, array $allowedCodes): array
    {
        // kept only for "selected", but checked either way so a typo does not go unnoticed
        $allowedIds = $this->tenantIds($tenant, $allowedCodes);
        $allowedIds = $inbound === CrossTenantInbound::Selected ? $allowedIds : [];
        $before = $this->callerIds($tenant);
        $beforeSummary = $this->summary($tenant);

        return DB::transaction(function () use ($tenant, $inbound, $allowedIds, $before, $beforeSummary): array {
            $tenant->update(['cross_tenant_inbound' => $inbound]);
            $tenant->inboundAllowlist()->sync($allowedIds);

            $after = $this->callerIds($tenant);
            $changed = [...array_diff($before, $after), ...array_diff($after, $before)];

            $operations = array_values(TenantPbxAssignment::query()
                ->whereIn('tenant_id', $changed)
                ->orderBy('id')
                ->get()
                ->map(fn (TenantPbxAssignment $assignment): ProvisioningOperation => $this->provisioning->forCalling(
                    $assignment->pbx_node_id,
                    $assignment->tenant_id,
                    OperationType::CrossTenantChanged,
                    TenantPbxAssignment::MORPH,
                    $assignment->id,
                ))
                ->all());

            $this->audit->record('tenant.cross_tenant_inbound_updated', $tenant, ['from' => $beforeSummary, 'to' => $this->summary($tenant)], $tenant);

            return $operations;
        });
    }

    /**
     * Tenants on the tenant's node that take its calls, with what to dial: their callers' side
     * (calling profiles) is not considered.
     *
     * @return Collection<int, Tenant>
     */
    public function directory(Tenant $tenant): Collection
    {
        $nodeId = $tenant->pbxAssignment?->pbx_node_id;

        if ($nodeId === null) {
            return new Collection;
        }

        return Tenant::acceptingCallsFrom($tenant)
            ->whereHas('pbxAssignment', fn (Builder $query) => $query->where('pbx_node_id', $nodeId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * Ids of the tenants with a node that may call the tenant now.
     *
     * @return list<string>
     */
    private function callerIds(Tenant $tenant): array
    {
        $tenant->refresh();

        $callers = match ($tenant->cross_tenant_inbound) {
            CrossTenantInbound::None => null,
            CrossTenantInbound::All => Tenant::query()->whereKeyNot($tenant->id),
            CrossTenantInbound::Selected => $tenant->inboundAllowlist()->getQuery(),
        };

        return $callers === null ? [] : array_values($callers->whereHas('pbxAssignment')->pluck('tenants.id')->map(fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function tenantIds(Tenant $tenant, array $codes): array
    {
        $codes = array_values(array_unique($codes));

        if (count($codes) > self::MAX_ALLOWED) {
            throw ValidationException::withMessages(['allowed_tenants' => 'List at most '.self::MAX_ALLOWED.' tenants.']);
        }

        $tenants = Tenant::query()->whereIn('code', $codes)->whereKeyNot($tenant->id)->pluck('id', 'code');
        $unknown = array_diff($codes, $tenants->keys()->map(fn (mixed $code): string => (string) $code)->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['allowed_tenants' => 'No other tenant has the code '.implode(', ', $unknown).'.']);
        }

        return array_values($tenants->map(fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * @return array{inbound: string, allowed_tenants: list<string>}
     */
    private function summary(Tenant $tenant): array
    {
        return [
            'inbound' => $tenant->cross_tenant_inbound->value,
            'allowed_tenants' => array_values($tenant->inboundAllowlist()->orderBy('code')->pluck('code')->map(fn (mixed $code): string => (string) $code)->all()),
        ];
    }
}
