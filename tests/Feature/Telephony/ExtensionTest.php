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
 * A tenant with code 1020 on a node (or the assignment given), and the signed-in user in the role.
 *
 * @return array{Tenant, TenantPbxAssignment}
 */
function tenantOnNode(TenantRole $role = TenantRole::Admin, ?TenantPbxAssignment $assignment = null): array
{
    $user = signIn();
    $assignment ??= TenantPbxAssignment::factory()->code('1020')->create();
    $tenant = $assignment->tenant;
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return [$tenant, $assignment];
}

it('creates an extension of the tenant on its node', function () {
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

it('takes numbers of 2 to 5 digits that cannot be confused with outbound, other tenants or emergency numbers', function (string $number, bool $accepted) {
    [$tenant] = tenantOnNode();

    $response = $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => $number, 'display_name' => 'X']);

    $accepted
        ? $response->assertSessionHasNoErrors()
        : $response->assertSessionHasErrors(['extension_number' => 'Use 2 to 5 digits, not starting with 0, 8 or 9, and not a three-digit number starting with 1.']);
})->with([
    'two digits' => ['20', true],
    'three digits' => ['201', true],
    'four digits from 1' => ['1001', true],
    'five digits' => ['20101', true],
    'one digit' => ['2', false],
    'six digits' => ['201010', false],
    'outbound' => ['0812', false],
    'other tenants' => ['81042', false],
    'outbound prefix' => ['9001', false],
    'emergency 191' => ['191', false],
    'three digits from 1' => ['101', false],
    'emergency 1669' => ['1669', false],
    'not digits' => ['2010a', false],
    'feature code' => ['*97', false],
]);

it('lets every tenant on a node have the same numbers', function () {
    $first = TenantPbxAssignment::factory()->code('1020')->create();
    $second = TenantPbxAssignment::factory()->code('1021')->create(['pbx_node_id' => $first->pbx_node_id]);
    Extension::factory()->on($first)->create(['extension_number' => '201']);
    [$tenant] = tenantOnNode(assignment: $second);

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'X'])
        ->assertSessionHasNoErrors();

    expect(Extension::withoutTenantScope()->where('extension_number', '201')->count())->toBe(2);
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

    [$suspended] = tenantOnNode(assignment: TenantPbxAssignment::factory()->code('1020')->suspended()->create());

    $this->post(route('tenant.extensions.store', $suspended), ['extension_number' => '20101', 'display_name' => 'X'])
        ->assertSessionHasErrors(['extension_number' => 'This tenant\'s numbers are suspended.']);
});

it('lists the extensions with the tenant code, its SIP domain and the next free number', function () {
    [$tenant, $assignment] = tenantOnNode(TenantRole::Member);
    Extension::factory()->on($assignment)->create(['extension_number' => '201']);
    Extension::factory()->on($assignment)->create(['extension_number' => '203']);
    Extension::factory()->on(TenantPbxAssignment::factory()->create())->create(['extension_number' => '202']);

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tenants/extensions/Index')
            ->has('extensions', 2)
            ->where('extensions.0.extension_number', '201')
            ->where('assignment.code', '1020')
            ->where('assignment.server.domain', $tenant->sip_domain)
            ->where('assignment.server.proxy', $assignment->node->sip_domain)
            ->where('nextNumber', '202')
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
