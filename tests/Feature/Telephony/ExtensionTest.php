<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A tenant on a node with the numbers 1000–1999, and the signed-in user in the role.
 *
 * @return array{Tenant, TenantPbxAssignment}
 */
function tenantOnNode(TenantRole $role = TenantRole::Admin, ?TenantPbxAssignment $assignment = null): array
{
    $user = signIn();
    $assignment ??= TenantPbxAssignment::factory()->numbers(1000, 1999)->create();
    $tenant = $assignment->tenant;
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return [$tenant, $assignment];
}

it('creates an extension inside the tenant block on its node', function () {
    [$tenant, $assignment] = tenantOnNode();

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '1001', 'display_name' => 'Reception'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $extension = Extension::withoutTenantScope()->sole();

    expect($extension->tenant_id)->toBe($tenant->id)
        ->and($extension->sip_node_id)->toBe($assignment->sip_node_id)
        ->and($extension->status)->toBe(ExtensionStatus::Active);

    $entry = AuditLog::query()->where('action', 'extension.created')->sole();
    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->summary)->toBe(['number' => '1001', 'display_name' => 'Reception']);
});

it('refuses a number outside the tenant block', function (string $number, string $message) {
    [$tenant] = tenantOnNode();

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => $number, 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => $message]);
})->with([
    'below' => ['999', 'Use a number from 1000–1999.'],
    'above' => ['2000', 'Use a number from 1000–1999.'],
    'leading zero' => ['0100', 'Use two to six digits, not starting with 0.'],
    'not digits' => ['10a1', 'Use two to six digits, not starting with 0.'],
]);

it('keeps two tenants on one node from sharing a number', function () {
    $first = TenantPbxAssignment::factory()->numbers(1000, 1999)->create();
    $second = TenantPbxAssignment::factory()->numbers(2000, 2999)->create(['sip_node_id' => $first->sip_node_id]);
    Extension::factory()->on($first)->create(['extension_number' => '1001']);
    [$tenant] = tenantOnNode(assignment: $second);

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '1001', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'Use a number from 2000–2999.']);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '2001', 'display_name' => 'X'])
        ->assertSessionHasNoErrors();
});

it('refuses a number the tenant already uses', function () {
    [$tenant, $assignment] = tenantOnNode();
    Extension::factory()->on($assignment)->create(['extension_number' => '1001']);

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '1001', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'This number is already in use.']);
});

it('needs a node and active numbers', function () {
    $user = signIn();
    $tenant = Tenant::factory()->withMember($user, TenantRole::Owner)->create();

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '1001', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'This tenant has no PBX node yet. A platform admin assigns one.']);

    [$suspended] = tenantOnNode(assignment: TenantPbxAssignment::factory()->suspended()->create());

    $this->post(route('tenant.extensions.store', $suspended), ['extension_number' => '1001', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'This tenant\'s numbers are suspended.']);
});

it('lists the extensions with the block and the next free number', function () {
    [$tenant, $assignment] = tenantOnNode(TenantRole::Member);
    Extension::factory()->on($assignment)->create(['extension_number' => '1000']);
    Extension::factory()->on($assignment)->create(['extension_number' => '1002']);
    Extension::factory()->on(TenantPbxAssignment::factory()->create())->create();

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tenants/extensions/Index')
            ->has('extensions', 2)
            ->where('extensions.0.extension_number', '1000')
            ->where('assignment.number_range_end', 1999)
            ->where('nextNumber', '1001')
            ->where('canManage', false));
});

it('lets only owners and admins change extensions', function () {
    [$tenant, $assignment] = tenantOnNode(TenantRole::Member);
    $extension = Extension::factory()->on($assignment)->create();

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '1001', 'display_name' => 'X'])->assertForbidden();
    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => 'X', 'status' => 'active'])->assertForbidden();
    $this->delete(route('tenant.extensions.destroy', [$tenant, $extension->id]))->assertForbidden();
});

it('renames, disables and deletes an extension', function () {
    [$tenant, $assignment] = tenantOnNode();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '1001']);

    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => 'Sales', 'status' => 'disabled'])
        ->assertSessionHasNoErrors();

    expect($extension->fresh())
        ->display_name->toBe('Sales')
        ->status->toBe(ExtensionStatus::Disabled);

    $this->delete(route('tenant.extensions.destroy', [$tenant, $extension->id]))->assertSessionHasNoErrors();

    expect(Extension::withoutTenantScope()->count())->toBe(0)
        ->and(AuditLog::query()->pluck('action')->all())->toBe(['extension.updated', 'extension.deleted']);
});

it('does not reach the extensions of another tenant', function () {
    [$tenant] = tenantOnNode();
    $other = Extension::factory()->on(TenantPbxAssignment::factory()->create())->create();

    $this->patch(route('tenant.extensions.update', [$tenant, $other->id]), ['display_name' => 'Taken', 'status' => 'active'])->assertNotFound();
    $this->delete(route('tenant.extensions.destroy', [$tenant, $other->id]))->assertNotFound();

    expect($other->fresh()->display_name)->not->toBe('Taken');
});
