<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Exceptions\StaleVersion;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\PickupGroup;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes the pickup groups of the tenant in the context. Members must be
 * extensions of the tenant, each in at most one group. A change writes the tenant's calling
 * dialplan (the pickup extens) and every extension that joined or left a group, with the ring
 * groups it is in, since those set the pickup mark on a ringing phone.
 */
class ManagePickupGroups
{
    public function __construct(
        private Audit $audit,
        private RequestProvisioning $provisioning,
        private TenantContext $context,
    ) {}

    /**
     * @param  list<int>  $memberIds
     */
    public function create(string $name, array $memberIds): PickupGroup
    {
        if ($this->context->tenant()?->pbxAssignment === null) {
            throw ValidationException::withMessages(['name' => 'This tenant has no PBX node yet. A platform admin assigns one.']);
        }

        $this->ensureValidMembers(null, $memberIds);

        try {
            return DB::transaction(function () use ($name, $memberIds): PickupGroup {
                $group = PickupGroup::query()->create(['name' => $name]);
                $group->members()->sync($memberIds);

                $this->provision($group, OperationType::PickupGroupCreated, $memberIds);

                $this->audit->record('pickup_group.created', $group, ['name' => $name, 'members' => count($memberIds)]);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['members' => 'An extension was just added to another pickup group. Try again.']);
        }
    }

    /**
     * With a version, refuses a change made against an older copy of the group (optimistic lock).
     *
     * @param  list<int>  $memberIds
     *
     * @throws StaleVersion
     */
    public function update(PickupGroup $group, string $name, array $memberIds, ?int $expectedVersion = null): void
    {
        $this->ensureValidMembers($group, $memberIds);

        try {
            DB::transaction(function () use ($group, $name, $memberIds, $expectedVersion): void {
                $updated = PickupGroup::query()
                    ->whereKey($group->id)
                    ->when($expectedVersion !== null, fn ($query) => $query->where('version', $expectedVersion))
                    ->update(['name' => $name, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

                if ($updated === 0) {
                    throw StaleVersion::of('pickup_group', 'pickup group');
                }

                $group->refresh();
                $changes = $group->members()->sync($memberIds);

                $this->provision($group, OperationType::PickupGroupUpdated, [...$changes['attached'], ...$changes['detached']]);

                $this->audit->record('pickup_group.updated', $group, ['name' => $name, 'members' => count($memberIds)]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['members' => 'An extension was just added to another pickup group. Try again.']);
        }
    }

    public function delete(PickupGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $memberIds = $group->members()->pluck('extensions.id')->all();

            $group->delete();

            $this->provision($group, OperationType::PickupGroupDeleted, $memberIds);

            $this->audit->record('pickup_group.deleted', $group, ['name' => $group->name]);
        });
    }

    public function reapply(PickupGroup $group): ?ProvisioningOperation
    {
        return DB::transaction(fn (): ?ProvisioningOperation => $this->provision($group, OperationType::Reapplied, []));
    }

    /**
     * @param  list<int>  $memberIds
     */
    private function ensureValidMembers(?PickupGroup $group, array $memberIds): void
    {
        if (count($memberIds) !== count(array_unique($memberIds))) {
            throw ValidationException::withMessages(['members' => 'An extension can be in the group only once.']);
        }

        // tenant-scoped: an extension of another tenant is simply not found
        if (Extension::query()->whereKey($memberIds)->count() !== count($memberIds)) {
            throw ValidationException::withMessages(['members' => 'Members must be extensions of this tenant.']);
        }

        $taken = Extension::query()
            ->whereKey($memberIds)
            ->whereHas('pickupGroups', fn ($groups) => $groups->when($group !== null, fn ($query) => $query->whereKeyNot($group?->id)))
            ->with('pickupGroups:id,name')
            ->orderBy('extension_number')
            ->first();

        if ($taken !== null) {
            throw ValidationException::withMessages(['members' => "Extension {$taken->extension_number} is already in pickup group {$taken->pickupGroups->first()?->name}."]);
        }
    }

    /**
     * The tenant's calling dialplan, then each extension that joined or left and its ring groups.
     * Nothing to write while the tenant has no node.
     *
     * @param  list<int>  $changedIds  extensions that joined or left the group
     */
    private function provision(PickupGroup $group, OperationType $type, array $changedIds): ?ProvisioningOperation
    {
        $assignment = TenantPbxAssignment::query()->where('tenant_id', $group->tenant_id)->first();

        if ($assignment === null) {
            return null;
        }

        $operation = $this->provisioning->forCalling($assignment->pbx_node_id, $group->tenant_id, $type, PickupGroup::MORPH, $group->id);

        if ($changedIds === []) {
            return $operation;
        }

        foreach (Extension::withoutTenantScope()->where('tenant_id', $group->tenant_id)->whereKey($changedIds)->get() as $extension) {
            $this->provisioning->forNumber($extension->pbx_node_id, $group->tenant_id, $extension->extension_number, OperationType::DependencyChanged, Extension::MORPH, $extension->id);
        }

        $ringGroups = RingGroup::withoutTenantScope()
            ->where('tenant_id', $group->tenant_id)
            ->whereHas('members', fn ($members) => $members->whereKey($changedIds))
            ->get();

        foreach ($ringGroups as $ringGroup) {
            $this->provisioning->forNumber($ringGroup->pbx_node_id, $group->tenant_id, $ringGroup->number, OperationType::DependencyChanged, RingGroup::MORPH, $ringGroup->id);
        }

        return $operation;
    }
}
