<?php

use App\Models\User;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;

it('decides who may manage members', function (?TenantRole $role, bool $platformAdmin, bool $allowed) {
    $user = $platformAdmin ? User::factory()->platformAdmin()->create() : User::factory()->create();
    $tenant = $role === null ? Tenant::factory()->create() : Tenant::factory()->withMember($user, $role)->create();

    expect($user->can('manageMembers', $tenant))->toBe($allowed)
        ->and($user->can('view', $tenant))->toBe($role !== null || $platformAdmin);
})->with([
    'owner' => [TenantRole::Owner, false, true],
    'admin' => [TenantRole::Admin, false, true],
    'member' => [TenantRole::Member, false, false],
    'not a member' => [null, false, false],
    'platform admin, not a member' => [null, true, true],
]);

it('lets only platform admins list and create tenants', function (bool $platformAdmin) {
    $user = $platformAdmin ? User::factory()->platformAdmin()->create() : User::factory()->create();

    expect($user->can('viewAny', Tenant::class))->toBe($platformAdmin)
        ->and($user->can('create', Tenant::class))->toBe($platformAdmin);
})->with([true, false]);
