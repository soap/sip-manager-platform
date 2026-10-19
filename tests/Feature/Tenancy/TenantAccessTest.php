<?php

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login page', function () {
    $tenant = Tenant::factory()->create();

    $this->get(route('tenant.dashboard', $tenant))->assertRedirect(route('login'));
});

it('shows the tenant to its members with their role', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->withMember($user, TenantRole::Admin)->create();

    $this->actingAs($user)
        ->get(route('tenant.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/Dashboard')
            ->where('tenant.slug', $tenant->slug)
            ->where('tenant.role', 'admin')
            ->where('tenant.canManageMembers', true)
            ->where('membersCount', 1));
});

it('hides a tenant from users who are not members', function () {
    $tenant = Tenant::factory()->withMember(User::factory()->create())->create();
    $member = Tenant::factory()->withMember($user = User::factory()->create())->create();

    signIn($user);

    $this->get(route('tenant.dashboard', $tenant))->assertNotFound();
    $this->get(route('tenant.members.index', $tenant))->assertNotFound();
    $this->get(route('tenant.dashboard', $member))->assertOk();
});

it('answers 404 for a tenant that does not exist', function () {
    signIn();

    $this->get('/t/no-such-tenant')->assertNotFound();
});

it('opens a suspended tenant to platform admins only', function () {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->suspended()->withMember($member, TenantRole::Owner)->create();

    $this->actingAs($member)->get(route('tenant.dashboard', $tenant))->assertForbidden();
    $this->actingAs(User::factory()->platformAdmin()->create())->get(route('tenant.dashboard', $tenant))->assertOk();
});

it('lists only the tenants the user belongs to on the dashboard', function () {
    $user = User::factory()->create();
    $mine = Tenant::factory()->withMember($user, TenantRole::Member)->create();
    Tenant::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('tenants', 1)
            ->where('tenants.0.slug', $mine->slug)
            ->where('tenants.0.role', 'member'));
});

it('lets only managers see the audit log', function (TenantRole $role, int $status) {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->withMember($user, $role)->create();

    $this->actingAs($user)->get(route('tenant.audit-log.index', $tenant))->assertStatus($status);
})->with([
    'owner' => [TenantRole::Owner, 200],
    'admin' => [TenantRole::Admin, 200],
    'member' => [TenantRole::Member, 403],
]);
