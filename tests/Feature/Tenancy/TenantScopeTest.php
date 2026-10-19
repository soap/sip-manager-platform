<?php

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\TenantContext;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->withMember(User::factory()->create())->create();
    $this->tenantB = Tenant::factory()->withMember(User::factory()->create())->withMember(User::factory()->create())->create();
});

it('only sees the rows of the tenant in the context', function () {
    app(TenantContext::class)->set($this->tenantB);

    expect(TenantMembership::query()->pluck('tenant_id')->unique()->all())->toBe([$this->tenantB->id]);
});

it('refuses to query without a tenant in the context', function () {
    TenantMembership::query()->get();
})->throws(MissingTenantContext::class);

it('fills the tenant from the context when creating', function () {
    app(TenantContext::class)->set($this->tenantA);

    $membership = TenantMembership::query()->create(['user_id' => User::factory()->create()->id, 'role' => TenantRole::Member]);

    expect($membership->tenant_id)->toBe($this->tenantA->id);
});

it('refuses to create without a tenant in the context', function () {
    TenantMembership::query()->create(['user_id' => User::factory()->create()->id, 'role' => TenantRole::Member]);
})->throws(MissingTenantContext::class);

it('reaches every tenant only when asked to', function () {
    expect(TenantMembership::withoutTenantScope()->count())->toBe(3);
});

it('restores the previous tenant after working for another one', function () {
    $context = app(TenantContext::class);
    $context->set($this->tenantA);

    $count = $context->runFor($this->tenantB, fn (): int => TenantMembership::query()->count());

    expect($count)->toBe(2)->and($context->id())->toBe($this->tenantA->id);
});
