<?php

namespace App\Modules\Telephony;

use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\PickupGroup;
use App\Modules\Telephony\Models\RingGroup;

/**
 * Keeps the pickup extens in the tenant's calling dialplan right when what they are built from
 * changes: a member extension that is deleted, or a ring group whose members a pickup group may
 * share (its mark is then in that group's Pickup()). Called inside the transaction of that change,
 * before a delete, so the memberships are still there to be found.
 */
class PickupGroupDependents
{
    public function __construct(private RequestProvisioning $provisioning) {}

    public function extensionDeleted(Extension $extension): void
    {
        $group = $extension->pickupGroups()->first();

        if ($group !== null) {
            $this->provisioning->forCalling($extension->pbx_node_id, $extension->tenant_id, OperationType::DependencyChanged, PickupGroup::MORPH, $group->id);
        }
    }

    /**
     * A ring group was created, changed or deleted.
     */
    public function ringGroupChanged(RingGroup $group): void
    {
        $hasPickup = PickupGroup::withoutTenantScope()->where('tenant_id', $group->tenant_id)->has('members')->exists();

        if ($hasPickup) {
            $this->provisioning->forCalling($group->pbx_node_id, $group->tenant_id, OperationType::DependencyChanged, RingGroup::MORPH, $group->id);
        }
    }
}
