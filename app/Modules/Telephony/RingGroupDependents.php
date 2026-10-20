<?php

namespace App\Modules\Telephony;

use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keeps ring groups right when what they point at changes: a member that is disabled or deleted,
 * or a failover target that is deleted (the group then hangs up instead). Called inside the
 * transaction of that change, before a delete, so the memberships are still there to be found.
 */
class RingGroupDependents
{
    public function __construct(private RequestProvisioning $provisioning) {}

    public function extensionChanged(Extension $extension, bool $deleted): void
    {
        $groups = RingGroup::withoutTenantScope()
            ->where('tenant_id', $extension->tenant_id)
            ->where(fn ($query) => $query
                ->whereHas('members', fn ($members) => $members->whereKey($extension->id))
                ->orWhere(fn ($failover) => $failover->whereIn('failover_type', [FailoverType::Extension, FailoverType::Voicemail])->where('failover_id', $extension->id)))
            ->get();

        $this->reprovision($groups, $deleted ? [FailoverType::Extension, FailoverType::Voicemail] : [], $extension->id);
    }

    public function ringGroupDeleted(RingGroup $group): void
    {
        $groups = RingGroup::withoutTenantScope()
            ->where('tenant_id', $group->tenant_id)
            ->where('failover_type', FailoverType::RingGroup)
            ->where('failover_id', $group->id)
            ->get();

        $this->reprovision($groups, [FailoverType::RingGroup], $group->id);
    }

    /**
     * @param  Collection<int, RingGroup>  $groups
     * @param  list<FailoverType>  $goneTypes  failovers that pointed at the deleted thing
     */
    private function reprovision(Collection $groups, array $goneTypes, int $goneId): void
    {
        foreach ($groups as $group) {
            if (in_array($group->failover_type, $goneTypes, true) && $group->failover_id === $goneId) {
                $group->update(['failover_type' => FailoverType::Hangup, 'failover_id' => null, 'version' => $group->version + 1]);
            }

            $this->provisioning->forNumber($group->pbx_node_id, $group->tenant_id, $group->number, OperationType::DependencyChanged, RingGroup::MORPH, $group->id);
        }
    }
}
