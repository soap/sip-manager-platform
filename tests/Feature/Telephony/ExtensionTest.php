<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A tenant on a node with prefix 20 (or the assignment given), and the signed-in user in the role.
 *
 * @return array{Tenant, TenantPbxAssignment}
 */
function tenantOnNode(TenantRole $role = TenantRole::Admin, ?TenantPbxAssignment $assignment = null): array
{
    $user = signIn();
    $assignment ??= TenantPbxAssignment::factory()->prefix('20')->create();
    $tenant = $assignment->tenant;
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return [$tenant, $assignment];
}

it('creates an extension with the tenant prefix on its node', function () {
    [$tenant, $assignment] = tenantOnNode();

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $extension = Extension::withoutTenantScope()->sole();

    expect($extension->tenant_id)->toBe($tenant->id)
        ->and($extension->pbx_node_id)->toBe($assignment->pbx_node_id)
        ->and($extension->extension_number)->toBe('20101')
        ->and($extension->status)->toBe(ExtensionStatus::Active);

    $entry = AuditLog::query()->where('action', 'extension.created')->sole();
    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->summary)->toBe(['number' => '20101', 'display_name' => 'Reception']);
});

it('refuses a number without the tenant prefix or of the wrong length', function (string $number, string $message) {
    [$tenant] = tenantOnNode();

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => $number, 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => $message]);
})->with([
    'another prefix' => ['21001', 'Use a number from 20000–20999.'],
    'three digits' => ['101', 'Use five digits: the tenant\'s prefix and three more.'],
    'six digits' => ['201010', 'Use five digits: the tenant\'s prefix and three more.'],
    'emergency range' => ['19100', 'Use five digits: the tenant\'s prefix and three more.'],
    'not digits' => ['2010a', 'Use five digits: the tenant\'s prefix and three more.'],
]);

it('keeps two tenants on one node apart by prefix', function () {
    $first = TenantPbxAssignment::factory()->prefix('20')->create();
    $second = TenantPbxAssignment::factory()->prefix('21')->create(['pbx_node_id' => $first->pbx_node_id]);
    Extension::factory()->on($first)->create(['extension_number' => '20101']);
    [$tenant] = tenantOnNode(assignment: $second);

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'Use a number from 21000–21999.']);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '21101', 'display_name' => 'X'])
        ->assertSessionHasNoErrors();
});

it('refuses a number the tenant already uses', function () {
    [$tenant, $assignment] = tenantOnNode();
    Extension::factory()->on($assignment)->create(['extension_number' => '20101']);

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'This number is already in use.']);
});

it('needs a node and active numbers', function () {
    $user = signIn();
    $tenant = Tenant::factory()->withMember($user, TenantRole::Owner)->create();

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'This tenant has no PBX node yet. A platform admin assigns one.']);

    [$suspended] = tenantOnNode(assignment: TenantPbxAssignment::factory()->prefix('20')->suspended()->create());

    $this->post(route('tenant.extensions.store', $suspended), ['extension_number' => '20101', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'This tenant\'s numbers are suspended.']);
});

it('lists the extensions with the prefix and the next free number', function () {
    [$tenant, $assignment] = tenantOnNode(TenantRole::Member);
    Extension::factory()->on($assignment)->create(['extension_number' => '20001']);
    Extension::factory()->on($assignment)->create(['extension_number' => '20003']);
    Extension::factory()->on(TenantPbxAssignment::factory()->create())->create();

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tenants/extensions/Index')
            ->has('extensions', 2)
            ->where('extensions.0.extension_number', '20001')
            ->where('assignment.prefix', '20')
            ->where('assignment.numbers', '20000–20999')
            ->where('nextNumber', '20002')
            ->where('canManage', false));
});

it('lets only owners and admins change extensions', function () {
    [$tenant, $assignment] = tenantOnNode(TenantRole::Member);
    $extension = Extension::factory()->on($assignment)->create();

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'X'])->assertForbidden();
    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => 'X', 'status' => 'active'])->assertForbidden();
    $this->delete(route('tenant.extensions.destroy', [$tenant, $extension->id]))->assertForbidden();
});

it('renames, disables and deletes an extension', function () {
    [$tenant, $assignment] = tenantOnNode();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '20101']);

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
