<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps other users out of the platform pages', function () {
    signIn();

    $this->get(route('admin.tenants.index'))->assertForbidden();
    $this->post(route('admin.tenants.store'), [])->assertForbidden();
});

it('sends a platform admin without two-factor authentication to turn it on', function () {
    signIn(User::factory()->create(['is_platform_admin' => true]));

    $this->get(route('admin.tenants.index'))->assertRedirect(route('security.edit'));
});

it('lists every tenant for platform admins', function () {
    signIn(User::factory()->platformAdmin()->create());
    Tenant::factory()->count(2)->create();

    $this->get(route('admin.tenants.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/tenants/Index')->has('tenants', 2));
});

it('creates a tenant with an invited owner', function () {
    Notification::fake();
    signIn(User::factory()->platformAdmin()->create());

    $this->post(route('admin.tenants.store'), [
        'name' => 'Demo Company',
        'slug' => 'demo-company',
        'owner_name' => 'Olivia',
        'owner_email' => 'olivia@example.com',
    ])->assertRedirect(route('admin.tenants.index'));

    $tenant = Tenant::query()->where('slug', 'demo-company')->sole();
    $owner = User::query()->where('email', 'olivia@example.com')->sole();

    expect($owner->roleIn($tenant))->toBe(TenantRole::Owner)
        ->and(AuditLog::query()->where('action', 'tenant.created')->value('tenant_id'))->toBe($tenant->id);
    Notification::assertSentTo($owner, ResetPassword::class);
});

it('gives every new tenant the next code and its own SIP domain', function () {
    Notification::fake();
    config(['services.sip.tenant_domain_suffix' => 'sip.example.net']);
    signIn(User::factory()->platformAdmin()->create());
    Tenant::factory()->create(['code' => '1041']);

    $this->post(route('admin.tenants.store'), ['name' => 'Acme', 'slug' => 'acme', 'owner_name' => 'O', 'owner_email' => 'o@example.com'])
        ->assertSessionHasNoErrors();

    expect(Tenant::query()->where('slug', 'acme')->sole())
        ->code->toBe('1042')
        ->sip_domain->toBe('acme.sip.example.net')
        ->and(AuditLog::query()->where('action', 'tenant.created')->value('summary'))
        ->toMatchArray(['code' => '1042', 'sip_domain' => 'acme.sip.example.net']);
});

it('starts the tenant codes at 1000', function () {
    expect(Tenant::nextCode())->toBe('1000');

    Tenant::factory()->create(['code' => '9999']);

    expect(Tenant::nextCode())->toBeNull();
});

it('validates the slug of a new tenant', function (string $slug) {
    signIn(User::factory()->platformAdmin()->create());
    Tenant::factory()->create(['slug' => 'taken']);

    $this->post(route('admin.tenants.store'), ['name' => 'X', 'slug' => $slug, 'owner_name' => 'O', 'owner_email' => 'o@example.com'])
        ->assertSessionHasErrors('slug');
})->with(['taken', 'Upper-Case', 'two--dashes', '-leading', 'with space']);
