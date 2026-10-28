<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A tenant with code 1020 on a node, two extensions 20101 and 20102, and the signed-in user in the role.
 *
 * @return array{Tenant, TenantPbxAssignment, Extension, Extension}
 */
function ringGroupTenant(TenantRole $role = TenantRole::Admin): array
{
    $user = signIn();
    $assignment = TenantPbxAssignment::factory()->code('1020')->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $assignment->tenant_id, 'user_id' => $user->id, 'role' => $role]);
    $first = Extension::factory()->on($assignment)->create(['extension_number' => '20101', 'display_name' => 'Anna']);
    $second = Extension::factory()->on($assignment)->create(['extension_number' => '20102', 'display_name' => 'Ben']);

    return [$assignment->tenant, $assignment, $first, $second];
}

/**
 * @return array<string, mixed>
 */
function ringGroupInput(array $overrides = []): array
{
    return [
        'number' => '20500',
        'name' => 'Sales',
        'strategy' => 'ring_all',
        'ring_seconds' => 20,
        'members' => [],
        'failover_type' => 'hangup',
        'failover_id' => null,
        ...$overrides,
    ];
}

it('creates a ring group with ordered members and requests provisioning', function () {
    [$tenant, $assignment, $first, $second] = ringGroupTenant();

    $this->post(route('tenant.ring-groups.store', $tenant), ringGroupInput(['strategy' => 'sequential', 'members' => [$second->id, $first->id]]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tenant.ring-groups.index', $tenant));

    $group = RingGroup::withoutTenantScope()->sole();

    expect($group->members->pluck('extension_number')->all())->toBe(['20102', '20101'])
        ->and(DialNumber::withoutTenantScope()->where('number', '20500')->value('owner_type'))->toBe(RingGroup::MORPH)
        ->and(ConfigRevision::query()->where('target', 'number:102020500')->exists())->toBeTrue()
        ->and(ProvisioningOperation::query()->where('aggregate_type', RingGroup::MORPH)->value('type'))->toBe(OperationType::RingGroupCreated)
        ->and(AuditLog::query()->where('action', 'ring_group.created')->value('tenant_id'))->toBe($tenant->id);
});

it('shares the number space with extensions', function () {
    [$tenant] = ringGroupTenant();

    $this->post(route('tenant.ring-groups.store', $tenant), ringGroupInput(['number' => '20101']))
        ->assertSessionHasErrors(['number' => 'This number is already in use.']);
    $this->post(route('tenant.ring-groups.store', $tenant), ringGroupInput(['number' => '81500']))
        ->assertSessionHasErrors(['number' => 'Use 2 to 5 digits, not starting with 0, 8 or 9, and not a three-digit number starting with 1.']);

    $this->post(route('tenant.ring-groups.store', $tenant), ringGroupInput());
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20500', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'This number is already in use.']);
});

it('accepts only extensions and failovers of the tenant', function (Closure $input, string $field) {
    [$tenant] = ringGroupTenant();
    $other = Extension::factory()->on(TenantPbxAssignment::factory()->code('1021')->create())->create();

    $this->post(route('tenant.ring-groups.store', $tenant), ringGroupInput($input($other)))
        ->assertSessionHasErrors($field);
})->with([
    'member of another tenant' => [fn (Extension $other): array => ['members' => [$other->id]], 'members'],
    'failover to another tenant' => [fn (Extension $other): array => ['failover_type' => 'extension', 'failover_id' => $other->id], 'failover_id'],
    'failover without a target' => [fn (): array => ['failover_type' => 'extension'], 'failover_id'],
    'too many members' => [fn (): array => ['members' => range(1, RingGroup::MAX_MEMBERS + 1)], 'members'],
    'ring time too short' => [fn (): array => ['ring_seconds' => 2], 'ring_seconds'],
]);

it('refuses a failover chain that comes back to the group', function () {
    [$tenant, $assignment] = ringGroupTenant();
    $first = RingGroup::factory()->on($assignment)->create(['number' => '20500']);
    $second = RingGroup::factory()->on($assignment)->create(['number' => '20501', 'failover_type' => FailoverType::RingGroup, 'failover_id' => $first->id]);

    $this->patch(route('tenant.ring-groups.update', [$tenant, $first->id]), ringGroupInput([
        'number' => null, 'failover_type' => 'ring_group', 'failover_id' => $second->id, 'status' => 'active',
    ]))->assertSessionHasErrors(['failover_id' => 'This failover leads back to this ring group.']);

    $this->patch(route('tenant.ring-groups.update', [$tenant, $first->id]), ringGroupInput([
        'number' => null, 'failover_type' => 'ring_group', 'failover_id' => $first->id, 'status' => 'active',
    ]))->assertSessionHasErrors(['failover_id' => 'This failover leads back to this ring group.']);
});

it('updates a ring group with an optimistic lock', function () {
    [$tenant, $assignment, $first] = ringGroupTenant();
    $group = RingGroup::factory()->on($assignment)->create();

    $this->patch(route('tenant.ring-groups.update', [$tenant, $group->id]), [
        'name' => 'Support', 'strategy' => 'sequential', 'ring_seconds' => 15, 'members' => [$first->id],
        'failover_type' => 'hangup', 'status' => 'active', 'version' => 1,
    ])->assertSessionHasNoErrors();

    expect($group->fresh())
        ->name->toBe('Support')
        ->version->toBe(2)
        ->and($group->fresh()->members->pluck('id')->all())->toBe([$first->id]);

    $this->patch(route('tenant.ring-groups.update', [$tenant, $group->id]), [
        'name' => 'Old copy', 'strategy' => 'ring_all', 'ring_seconds' => 15, 'members' => [],
        'failover_type' => 'hangup', 'status' => 'active', 'version' => 1,
    ])->assertSessionHasErrors(['ring_group' => 'Someone else changed this ring group. Reload and try again.']);
});

it('recompiles the groups of an extension that is disabled or deleted', function () {
    [$tenant, $assignment, $first, $second] = ringGroupTenant();
    $member = RingGroup::factory()->on($assignment)->withMembers([$first])->create(['number' => '20500']);
    $fallback = RingGroup::factory()->on($assignment)->create(['number' => '20501', 'failover_type' => FailoverType::Extension, 'failover_id' => $first->id]);
    $unrelated = RingGroup::factory()->on($assignment)->withMembers([$second])->create(['number' => '20502']);

    $this->patch(route('tenant.extensions.update', [$tenant, $first->id]), ['display_name' => 'Anna', 'status' => 'disabled']);

    $dependencyTargets = fn (): array => ConfigRevision::query()
        ->whereHas('operations', fn ($query) => $query->where('type', OperationType::DependencyChanged))
        ->pluck('target')->sort()->values()->all();

    expect($dependencyTargets())->toBe(['number:102020500', 'number:102020501']);

    $this->delete(route('tenant.extensions.destroy', [$tenant, $first->id]));

    expect($member->fresh()->members)->toHaveCount(0)
        ->and($fallback->fresh()->failover_type)->toBe(FailoverType::Hangup)
        ->and($unrelated->fresh()->members)->toHaveCount(1);
});

it('sends groups that failed over to a deleted group to hang up', function () {
    [$tenant, $assignment] = ringGroupTenant();
    $target = RingGroup::factory()->on($assignment)->create(['number' => '20500']);
    $pointing = RingGroup::factory()->on($assignment)->create(['number' => '20501', 'failover_type' => FailoverType::RingGroup, 'failover_id' => $target->id]);

    $this->delete(route('tenant.ring-groups.destroy', [$tenant, $target->id]))->assertRedirect();

    expect(RingGroup::withoutTenantScope()->find($target->id))->toBeNull()
        ->and(DialNumber::withoutTenantScope()->where('number', '20500')->exists())->toBeFalse()
        ->and($pointing->fresh()->failover_type)->toBe(FailoverType::Hangup)
        ->and(ProvisioningOperation::query()->where('type', OperationType::RingGroupDeleted)->count())->toBe(1);
});

it('keeps the prefix fixed and the tenant on its node while it has ring groups', function () {
    [$tenant, $assignment] = ringGroupTenant();
    RingGroup::factory()->on($assignment)->create();
    Extension::withoutTenantScope()->each(function (Extension $extension): void {
        DialNumber::withoutTenantScope()->where('owner_type', Extension::MORPH)->where('owner_id', $extension->id)->delete();
        $extension->deleteQuietly();
    });
    signIn(User::factory()->platformAdmin()->create());

    $this->delete(route('admin.assignments.destroy', $assignment))
        ->assertSessionHasErrors(['assignment' => 'Delete the tenant\'s extensions and ring groups before taking it off the node.']);
});

it('lists ring groups with their members, failover and provisioning state', function () {
    [$tenant, $assignment, $first, $second] = ringGroupTenant(TenantRole::Member);
    RingGroup::factory()->on($assignment)->withMembers([$second, $first])->create(['number' => '20500', 'failover_type' => FailoverType::Extension, 'failover_id' => $first->id]);

    $this->get(route('tenant.ring-groups.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tenants/ring-groups/Index')
            ->where('ringGroups.0.members.0.number', '20102')
            ->where('ringGroups.0.failover', 'Extension 20101')
            ->where('nextNumber', '500')
            ->where('canManage', false)
            ->has('extensions', 2));
});

it('lets only owners and admins change ring groups', function () {
    [$tenant, $assignment] = ringGroupTenant(TenantRole::Member);
    $group = RingGroup::factory()->on($assignment)->create();

    $this->post(route('tenant.ring-groups.store', $tenant), ringGroupInput(['number' => '20600']))->assertForbidden();
    $this->get(route('tenant.ring-groups.edit', [$tenant, $group->id]))->assertForbidden();
    $this->delete(route('tenant.ring-groups.destroy', [$tenant, $group->id]))->assertForbidden();
});

it('does not reach the ring groups of another tenant', function () {
    [$tenant] = ringGroupTenant();
    $other = RingGroup::factory()->on(TenantPbxAssignment::factory()->code('1021')->create())->create(['number' => '21500']);

    $this->get(route('tenant.ring-groups.edit', [$tenant, $other->id]))->assertNotFound();
    $this->delete(route('tenant.ring-groups.destroy', [$tenant, $other->id]))->assertNotFound();
});
