<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Enums\RingStrategy;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Telephony\NumberAllocator;
use App\Modules\Telephony\RingGroupDependents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes the ring groups of the tenant in the context (plan v0.2 D5). Members
 * and failover targets must belong to the tenant, and a chain of failovers may not come back to
 * the group it started from, or an unanswered call would ring around for ever.
 */
class ManageRingGroups
{
    /** How far a failover chain is followed before it counts as a loop. */
    private const MAX_FAILOVER_HOPS = 20;

    public function __construct(
        private Audit $audit,
        private RequestProvisioning $provisioning,
        private NumberAllocator $numbers,
        private RingGroupDependents $dependents,
    ) {}

    /**
     * @param  list<int>  $memberIds  extension ids in ringing order
     */
    public function create(string $number, string $name, RingStrategy $strategy, int $ringSeconds, array $memberIds, FailoverType $failoverType, ?int $failoverId): RingGroup
    {
        $assignment = $this->numbers->ensureAvailable($number, 'number');
        $this->ensureValidMembers($memberIds);
        $failoverId = $this->ensureValidFailover(null, $failoverType, $failoverId);

        try {
            return DB::transaction(function () use ($assignment, $number, $name, $strategy, $ringSeconds, $memberIds, $failoverType, $failoverId): RingGroup {
                $group = RingGroup::query()->create([
                    'pbx_node_id' => $assignment->pbx_node_id,
                    'number' => $number,
                    'name' => $name,
                    'strategy' => $strategy,
                    'ring_seconds' => $ringSeconds,
                    'failover_type' => $failoverType,
                    'failover_id' => $failoverId,
                ]);

                DialNumber::query()->create([
                    'pbx_node_id' => $assignment->pbx_node_id,
                    'number' => $number,
                    'owner_type' => RingGroup::MORPH,
                    'owner_id' => $group->id,
                ]);

                $this->syncMembers($group, $memberIds);
                $this->provision($group, OperationType::RingGroupCreated);

                $this->audit->record('ring_group.created', $group, ['number' => $number, 'name' => $name, 'members' => count($memberIds)]);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['number' => 'This number is already in use.']);
        }
    }

    /**
     * Changes everything but the number. With a version, refuses a change made against an older
     * copy of the group (optimistic lock).
     *
     * @param  list<int>  $memberIds
     */
    public function update(RingGroup $group, string $name, RingStrategy $strategy, int $ringSeconds, array $memberIds, FailoverType $failoverType, ?int $failoverId, ExtensionStatus $status, ?int $expectedVersion = null): void
    {
        $this->ensureValidMembers($memberIds);
        $failoverId = $this->ensureValidFailover($group, $failoverType, $failoverId);

        DB::transaction(function () use ($group, $name, $strategy, $ringSeconds, $memberIds, $failoverType, $failoverId, $status, $expectedVersion): void {
            $updated = RingGroup::query()
                ->whereKey($group->id)
                ->when($expectedVersion !== null, fn ($query) => $query->where('version', $expectedVersion))
                ->update([
                    'name' => $name,
                    'strategy' => $strategy,
                    'ring_seconds' => $ringSeconds,
                    'failover_type' => $failoverType,
                    'failover_id' => $failoverId,
                    'status' => $status,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw ValidationException::withMessages(['ring_group' => 'Someone else changed this ring group. Reload and try again.']);
            }

            $group->refresh();
            $this->syncMembers($group, $memberIds);
            $this->provision($group, OperationType::RingGroupUpdated);

            $this->audit->record('ring_group.updated', $group, [
                'number' => $group->number,
                'name' => $name,
                'strategy' => $strategy->value,
                'members' => count($memberIds),
                'status' => $status->value,
            ]);
        });
    }

    public function delete(RingGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            // groups that fail over to this one hang up instead
            $this->dependents->ringGroupDeleted($group);

            DialNumber::query()->where('owner_type', RingGroup::MORPH)->where('owner_id', $group->id)->delete();
            $group->delete();

            $this->provision($group, OperationType::RingGroupDeleted);

            $this->audit->record('ring_group.deleted', $group, ['number' => $group->number, 'name' => $group->name]);
        });
    }

    public function reapply(RingGroup $group): ProvisioningOperation
    {
        return DB::transaction(fn (): ProvisioningOperation => $this->provision($group, OperationType::Reapplied));
    }

    /**
     * Ring groups start at PP500, leaving the low numbers to extensions.
     */
    public function nextFreeNumber(): ?string
    {
        return $this->numbers->nextFree(500);
    }

    /**
     * @param  list<int>  $memberIds
     */
    private function ensureValidMembers(array $memberIds): void
    {
        if (count($memberIds) !== count(array_unique($memberIds))) {
            throw ValidationException::withMessages(['members' => 'An extension can be in the group only once.']);
        }

        if (count($memberIds) > RingGroup::MAX_MEMBERS) {
            throw ValidationException::withMessages(['members' => 'A ring group has at most '.RingGroup::MAX_MEMBERS.' members.']);
        }

        // tenant-scoped: an extension of another tenant is simply not found
        if (Extension::query()->whereKey($memberIds)->count() !== count($memberIds)) {
            throw ValidationException::withMessages(['members' => 'Members must be extensions of this tenant.']);
        }
    }

    /**
     * @return int|null the failover id to store (null for hangup)
     */
    private function ensureValidFailover(?RingGroup $group, FailoverType $type, ?int $id): ?int
    {
        if ($type === FailoverType::Hangup) {
            return null;
        }

        $found = match ($type) {
            FailoverType::Extension, FailoverType::Voicemail => Extension::query()->whereKey($id)->exists(),
            FailoverType::RingGroup => RingGroup::query()->whereKey($id)->exists(),
        };

        if ($id === null || ! $found) {
            throw ValidationException::withMessages(['failover_id' => 'Choose an extension or ring group of this tenant.']);
        }

        if ($type === FailoverType::Voicemail && ! Extension::query()->whereKey($id)->where('voicemail_enabled', true)->exists()) {
            throw ValidationException::withMessages(['failover_id' => 'This extension has no voicemail.']);
        }

        if ($type === FailoverType::RingGroup && $group !== null && $this->leadsBackTo($group->id, $id)) {
            throw ValidationException::withMessages(['failover_id' => 'This failover leads back to this ring group.']);
        }

        return $id;
    }

    /**
     * Whether following failovers from the ring group $nextId ends up at $groupId.
     */
    private function leadsBackTo(int $groupId, int $nextId): bool
    {
        for ($hop = 0; $hop < self::MAX_FAILOVER_HOPS; $hop++) {
            if ($nextId === $groupId) {
                return true;
            }

            $next = RingGroup::query()->find($nextId);

            if ($next === null || $next->failover_type !== FailoverType::RingGroup || $next->failover_id === null) {
                return false;
            }

            $nextId = $next->failover_id;
        }

        return true;
    }

    /**
     * @param  list<int>  $memberIds
     */
    private function syncMembers(RingGroup $group, array $memberIds): void
    {
        $group->members()->sync(collect($memberIds)->values()->mapWithKeys(fn (int $id, int $index): array => [$id => ['position' => $index + 1]])->all());
    }

    private function provision(RingGroup $group, OperationType $type): ProvisioningOperation
    {
        return $this->provisioning->forNumber($group->pbx_node_id, $group->tenant_id, $group->number, $type, RingGroup::MORPH, $group->id);
    }
}
