<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\ApiToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{User, Tenant}
 */
function tenantMember(TenantRole $role = TenantRole::Admin): array
{
    $tenant = Tenant::factory()->create();
    $user = signIn();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return [$user, $tenant];
}

it('issues a token for the tenant, shows it once and stores only its hash', function () {
    [$user, $tenant] = tenantMember();

    $this->post(route('tenant.api-tokens.store', $tenant), ['name' => 'CRM', 'ability' => 'write', 'expires_in_days' => 90])
        ->assertRedirect()
        ->assertInertiaFlash('apiToken.name', 'CRM');

    $plain = session()->get(SessionKey::FLASH_DATA)['apiToken']['token'];
    $token = ApiToken::query()->sole();

    expect($token->tenant_id)->toBe($tenant->id)
        ->and($token->tokenable_id)->toBe($user->id)
        ->and($token->abilities)->toBe(['read', 'write'])
        ->and($token->expires_at->isSameDay(now()->addDays(90)))->toBeTrue()
        ->and($token->token)->toBe(hash('sha256', explode('|', $plain)[1]))
        ->and(AuditLog::query()->where('action', 'api_token.created')->exists())->toBeTrue();

    $this->get(route('tenant.api-tokens.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/api-tokens/Index')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'CRM')
            ->where('tokens.0.user', $user->name)
            ->missing('tokens.0.token'));
});

it('revokes a token of the tenant only', function () {
    [$user, $tenant] = tenantMember();
    $mine = $user->createToken('CRM', ['read'])->accessToken;
    $mine->forceFill(['tenant_id' => $tenant->id])->save();
    $elsewhere = $user->createToken('Other', ['read'])->accessToken;
    $elsewhere->forceFill(['tenant_id' => Tenant::factory()->create()->id])->save();

    $this->delete(route('tenant.api-tokens.destroy', [$tenant, $elsewhere->id]))->assertNotFound();
    $this->delete(route('tenant.api-tokens.destroy', [$tenant, $mine->id]))->assertRedirect();

    expect(ApiToken::query()->pluck('id')->all())->toBe([$elsewhere->id]);
});

it('keeps members out of the API tokens page', function () {
    [, $tenant] = tenantMember(TenantRole::Member);

    $this->get(route('tenant.api-tokens.index', $tenant))->assertForbidden();
    $this->post(route('tenant.api-tokens.store', $tenant), ['name' => 'CRM', 'ability' => 'read'])->assertForbidden();
});

it('revokes the tokens of a removed member', function () {
    [, $tenant] = tenantMember(TenantRole::Owner);
    $member = User::factory()->create();
    $membership = TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $member->id, 'role' => TenantRole::Admin]);
    $member->createToken('CRM', ['read'])->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

    $this->delete(route('tenant.members.destroy', [$tenant, $membership->id]))->assertRedirect();

    expect(ApiToken::query()->count())->toBe(0);
});
