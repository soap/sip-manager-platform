<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\PickupGroup;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\TenantMembership;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Extensions 201, 202 and 203 of the provisioning tenant (code 1020).
 *
 * @return array{Extension, Extension, Extension}
 */
function pickupExtensions(TenantPbxAssignment $assignment): array
{
    return array_map(
        fn (string $number): Extension => Extension::factory()->on($assignment)->create(['extension_number' => $number, 'display_name' => "Desk {$number}"]),
        ['201', '202', '203'],
    );
}

it('creates a pickup group and writes the calling dialplan and its members', function () {
    [$tenant, $assignment] = provisioningTenant();
    [$first, $second] = pickupExtensions($assignment);

    $this->post(route('tenant.pickup-groups.store', $tenant), ['name' => 'Sales', 'members' => [$first->id, $second->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tenant.pickup-groups.index', $tenant));

    $group = PickupGroup::withoutTenantScope()->sole();

    expect($group->members->pluck('extension_number')->all())->toBe(['201', '202'])
        ->and(ConfigRevision::query()->pluck('target')->sort()->values()->all())->toBe(['calling:1020', 'number:1020201', 'number:1020202'])
        ->and(ProvisioningOperation::query()->where('aggregate_type', PickupGroup::MORPH)->value('type'))->toBe(OperationType::PickupGroupCreated)
        ->and(AuditLog::query()->where('action', 'pickup_group.created')->value('tenant_id'))->toBe($tenant->id);
});

it('keeps an extension in one pickup group and members within the tenant', function () {
    [$tenant, $assignment] = provisioningTenant();
    [$first, $second] = pickupExtensions($assignment);
    PickupGroup::factory()->on($assignment)->withMembers([$first])->create(['name' => 'Sales']);
    $stranger = Extension::factory()->on(TenantPbxAssignment::factory()->code('1030')->create())->create(['extension_number' => '301']);

    $this->post(route('tenant.pickup-groups.store', $tenant), ['name' => 'Support', 'members' => [$second->id, $first->id]])
        ->assertSessionHasErrors(['members' => 'Extension 201 is already in pickup group Sales.']);
    $this->post(route('tenant.pickup-groups.store', $tenant), ['name' => 'Support', 'members' => [$stranger->id]])
        ->assertSessionHasErrors(['members' => 'Members must be extensions of this tenant.']);
    $this->post(route('tenant.pickup-groups.store', $tenant), ['name' => 'Sales', 'members' => []])
        ->assertSessionHasErrors(['name' => 'There is already a pickup group with this name.']);

    expect(PickupGroup::withoutTenantScope()->count())->toBe(1);
});

it('lets a member pick up a ringing colleague and a call to a ring group they share', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    [$first, $second, $outsider] = pickupExtensions($assignment);
    RingGroup::factory()->on($assignment)->withMembers([$second, $outsider])->create(['number' => '500']);
    RingGroup::factory()->on($assignment)->withMembers([$outsider])->create(['number' => '501']);

    $this->post(route('tenant.pickup-groups.store', $tenant), ['name' => 'Sales', 'members' => [$first->id, $second->id]]);
    applyPendingRevisions();

    $group = PickupGroup::withoutTenantScope()->sole();
    $pickup = [
        ['priority' => 1, 'app' => 'Pickup', 'appdata' => "1020g{$group->id}@PICKUPMARK&1020r500@PICKUPMARK"],
        ['priority' => 2, 'app' => 'Hangup', 'appdata' => ''],
    ];

    // a ringing member carries the group's mark
    expect($node->extens['1020201'])->toBe([
        ['priority' => 1, 'app' => 'Set', 'appdata' => "_PICKUPMARK=1020g{$group->id}"],
        ['priority' => 2, 'app' => 'Dial', 'appdata' => 'PJSIP/1020201@opensips,30'],
        ['priority' => 3, 'app' => 'Hangup', 'appdata' => ''],
    ])
        // *8 goes to the caller's own pickup exten, or hangs up outside every group
        ->and($node->extens['1020*8'])->toBe([
            ['priority' => 1, 'app' => 'GotoIf', 'appdata' => '$[${DIALPLAN_EXISTS(${CONTEXT},1020k${CALLERID(num)},1)}]?3'],
            ['priority' => 2, 'app' => 'Hangup', 'appdata' => ''],
            ['priority' => 3, 'app' => 'Goto', 'appdata' => '1020k${CALLERID(num)},1'],
        ])
        ->and($node->extens['1020k201'])->toBe($pickup)
        ->and($node->extens['1020k202'])->toBe($pickup)
        ->and($node->extens)->not->toHaveKey('1020k203')
        // the ring group with a member in the group marks its calls; the other one does not
        ->and($node->extens['1020500'][0])->toBe(['priority' => 1, 'app' => 'Set', 'appdata' => '_PICKUPMARK=1020r500'])
        ->and($node->extens['1020501'] ?? [])->not->toContain(['priority' => 1, 'app' => 'Set', 'appdata' => '_PICKUPMARK=1020r501']);
});

it('takes a member that leaves off the pickup dialplan', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    [$first, $second] = pickupExtensions($assignment);
    $this->post(route('tenant.pickup-groups.store', $tenant), ['name' => 'Sales', 'members' => [$first->id, $second->id]]);
    applyPendingRevisions();
    $group = PickupGroup::withoutTenantScope()->sole();

    $this->patch(route('tenant.pickup-groups.update', [$tenant, $group->id]), ['name' => 'Sales desk', 'members' => [$first->id], 'version' => 1])
        ->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect($node->extens)->not->toHaveKey('1020k202')
        ->and($node->extens)->toHaveKey('1020k201')
        ->and(array_column($node->extens['1020202'], 'app'))->toBe(['Dial', 'Hangup'])
        ->and($group->fresh()->version)->toBe(2);

    $this->patch(route('tenant.pickup-groups.update', [$tenant, $group->id]), ['name' => 'Sales', 'members' => [], 'version' => 1])
        ->assertSessionHasErrors(['pickup_group' => 'Someone else changed this pickup group. Reload and try again.']);
});

it('removes the feature code with the last pickup group and with a deleted member', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    [$first, $second] = pickupExtensions($assignment);
    $this->post(route('tenant.pickup-groups.store', $tenant), ['name' => 'Sales', 'members' => [$first->id, $second->id]]);
    applyPendingRevisions();

    $this->delete(route('tenant.extensions.destroy', [$tenant, $second->id]))->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect($node->extens)->not->toHaveKey('1020k202')
        ->and($node->extens)->toHaveKey('1020*8');

    $this->delete(route('tenant.pickup-groups.destroy', [$tenant, PickupGroup::withoutTenantScope()->sole()->id]))->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect($node->extens)->not->toHaveKey('1020*8')
        ->and($node->extens)->not->toHaveKey('1020k201')
        ->and(array_column($node->extens['1020201'], 'app'))->toBe(['Dial', 'Hangup']);
});

it('lists pickup groups and lets only managers change them', function () {
    [$tenant, $assignment] = provisioningTenant();
    [$first] = pickupExtensions($assignment);
    PickupGroup::factory()->on($assignment)->withMembers([$first])->create(['name' => 'Sales']);

    $this->get(route('tenant.pickup-groups.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/pickup-groups/Index')
            ->where('featureCode', '*8')
            ->has('pickupGroups', 1)
            ->where('pickupGroups.0.members.0.number', '201')
            ->where('extensions.0.pickup_group', 'Sales'));

    $viewer = signIn();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'role' => TenantRole::Member]);

    $this->post(route('tenant.pickup-groups.store', $tenant), ['name' => 'Support', 'members' => []])->assertForbidden();
});
