<?php

use App\Models\IdempotencyKey;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Telephony\Enums\CallPermission;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Enums\CrossTenantInbound;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;

it('creates a calling profile with categories by name and lists it', function () {
    $client = apiClient();
    Tenant::factory()->create(['code' => '1042']);

    $created = $this->withToken($client['token'])
        ->postJson('/api/v1/calling-profiles', [
            'name' => 'Partners',
            'categories' => ['cross_tenant' => 'allow', 'mobile' => 'code'],
            'cross_tenant_scope' => 'selected',
            'destinations' => ['1042'],
        ])
        ->assertCreated()
        ->assertHeader('ETag', '"1"')
        ->assertJsonPath('data.categories.mobile', 'code')
        ->assertJsonPath('data.categories.international', 'deny')
        ->assertJsonPath('data.cross_tenant_scope', ['scope' => 'selected', 'tenants' => ['1042']]);

    $this->withToken($client['token'])
        ->getJson('/api/v1/calling-profiles')
        ->assertOk()
        ->assertJsonPath('data.0.id', $created->json('data.id'))
        ->assertJsonPath('data.0.extensions_count', 0);
});

it('refuses unknown categories, permissions and tenant codes', function () {
    $client = apiClient();

    $this->withToken($client['token'])
        ->postJson('/api/v1/calling-profiles', ['name' => 'X', 'categories' => ['satellite' => 'allow', 'mobile' => 'maybe']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['categories', 'categories.mobile']);

    $this->withToken($client['token'])
        ->postJson('/api/v1/calling-profiles', ['name' => 'X', 'categories' => [], 'cross_tenant_scope' => 'selected', 'destinations' => ['9999']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destinations');
});

it('updates a profile with the version, answers 202 with an operation per extension, and 409 when stale', function () {
    $client = apiClient();
    $profile = CallingProfile::factory()->for($client['tenant'])->create();
    Extension::factory()->on($client['assignment'])->count(2)->create(['calling_profile_id' => $profile->id]);

    $this->withToken($client['token'])->patchJson("/api/v1/calling-profiles/{$profile->id}", ['categories' => ['landline' => 'allow']])
        ->assertStatus(428);

    $response = $this->withToken($client['token'])
        ->withHeader('If-Match', '"1"')
        ->patchJson("/api/v1/calling-profiles/{$profile->id}", ['categories' => ['landline' => 'allow']])
        ->assertAccepted()
        ->assertHeader('ETag', '"2"')
        ->assertJsonPath('data.categories.landline', 'allow')
        ->assertJsonPath('data.name', $profile->name);

    expect($response->json('operation_ids'))->toHaveCount(2);

    $this->withToken($client['token'])->patchJson("/api/v1/calling-profiles/{$profile->id}", ['name' => 'Late', 'version' => 1])
        ->assertConflict()
        ->assertJsonPath('data.version', 2);
});

it('answers 409 when deleting a profile in use, and 204 otherwise', function () {
    $client = apiClient();
    $used = CallingProfile::factory()->for($client['tenant'])->create();
    $unused = CallingProfile::factory()->for($client['tenant'])->create();
    Extension::factory()->on($client['assignment'])->create(['calling_profile_id' => $used->id]);

    $this->withToken($client['token'])->deleteJson("/api/v1/calling-profiles/{$used->id}")->assertConflict();
    $this->withToken($client['token'])->deleteJson("/api/v1/calling-profiles/{$unused->id}")->assertNoContent();
});

it('gives an extension a calling profile with PATCH, and filters by it', function () {
    $client = apiClient();
    $profile = CallingProfile::factory()->for($client['tenant'])->crossTenant()->create();
    $extension = Extension::factory()->on($client['assignment'])->create();
    Extension::factory()->on($client['assignment'])->create();

    $this->withToken($client['token'])
        ->patchJson("/api/v1/extensions/{$extension->id}", ['calling_profile_id' => $profile->id, 'version' => 1])
        ->assertAccepted()
        ->assertJsonPath('data.calling_profile_id', $profile->id)
        ->assertJsonPath('data.provisioning.status', 'pending');

    $this->withToken($client['token'])
        ->getJson("/api/v1/extensions?calling_profile_id={$profile->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken($client['token'])
        ->patchJson("/api/v1/extensions/{$extension->id}", ['calling_profile_id' => CallingProfile::factory()->create()->id, 'version' => 2])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('calling_profile_id');
});

it('creates an authorization code with its digits in that answer only, also when replayed', function () {
    $client = apiClient();

    $response = $this->withToken($client['token'])
        ->withHeader('Idempotency-Key', 'code-anna')
        ->postJson('/api/v1/authorization-codes', ['label' => 'Anna', 'categories' => ['mobile', 'cross_tenant'], 'code' => '24681357'])
        ->assertAccepted()
        ->assertJsonPath('authorization_code', '24681357')
        ->assertJsonPath('data.categories', ['cross_tenant', 'mobile']);

    $id = $response->json('data.id');

    expect($response->json('operation_id'))->not->toBeNull()
        ->and(ConfigRevision::query()->sole()->target)->toBe('calling:'.$client['tenant']->code)
        ->and(IdempotencyKey::query()->sole()->toJson())->not->toContain('24681357');

    $this->withToken($client['token'])
        ->withHeader('Idempotency-Key', 'code-anna')
        ->postJson('/api/v1/authorization-codes', ['label' => 'Anna', 'categories' => ['mobile', 'cross_tenant'], 'code' => '24681357'])
        ->assertJsonPath('authorization_code', '[redacted]');

    $this->flushHeaders()
        ->withToken($client['token'])
        ->getJson("/api/v1/authorization-codes/{$id}")
        ->assertOk()
        ->assertJsonMissingPath('authorization_code')
        ->assertJsonMissingPath('data.node_hash');

    $this->withToken($client['token'])
        ->postJson("/api/v1/authorization-codes/{$id}/reset")
        ->assertAccepted()
        ->assertJsonPath('authorization_code', fn (string $code): bool => preg_match('/^\d{8}$/', $code) === 1);
});

it('updates and deletes a code, refusing a past expiry', function () {
    $client = apiClient();
    $code = AuthorizationCode::factory()->for($client['tenant'])->create();

    $this->withToken($client['token'])
        ->patchJson("/api/v1/authorization-codes/{$code->id}", ['status' => 'active', 'expires_at' => now()->subDay()->toIso8601String(), 'version' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expires_at');

    $this->withToken($client['token'])
        ->patchJson("/api/v1/authorization-codes/{$code->id}", ['status' => 'disabled', 'version' => 1])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'disabled');

    $this->withToken($client['token'])->deleteJson("/api/v1/authorization-codes/{$code->id}")->assertAccepted();

    expect(AuthorizationCode::withoutTenantScope()->count())->toBe(0);
});

it('sets who may call the tenant, and lists only the tenants that take our calls', function () {
    $client = apiClient();
    $node = $client['assignment']->pbx_node_id;
    $open = Tenant::factory()->create(['code' => '1042', 'name' => 'Acme', 'cross_tenant_inbound' => CrossTenantInbound::All]);
    $closed = Tenant::factory()->create(['code' => '1050', 'name' => 'Hidden', 'cross_tenant_inbound' => CrossTenantInbound::None]);
    TenantPbxAssignment::factory()->create(['tenant_id' => $open->id, 'pbx_node_id' => $node]);
    TenantPbxAssignment::factory()->create(['tenant_id' => $closed->id, 'pbx_node_id' => $node]);

    $this->withToken($client['token'])
        ->getJson('/api/v1/directory')
        ->assertOk()
        ->assertExactJson(['data' => [['name' => 'Acme', 'tenant_code' => '1042', 'dial_prefix' => '81042']]]);

    $this->withToken($client['token'])
        ->patchJson('/api/v1/cross-tenant', ['inbound' => 'selected', 'allowed_tenants' => ['1050']])
        ->assertAccepted()
        ->assertJsonPath('data', ['inbound' => 'selected', 'allowed_tenants' => ['1050']])
        ->assertJsonCount(1, 'operation_ids');

    $this->withToken($client['token'])
        ->patchJson('/api/v1/cross-tenant', ['inbound' => 'all', 'allowed_tenants' => ['0000']])
        ->assertUnprocessable();
});

it('lets read tokens and members look but not change', function () {
    $reader = apiClient(abilities: ['read']);
    $member = apiClient(TenantRole::Member);

    $this->withToken($reader['token'])->getJson('/api/v1/calling-profiles')->assertOk();
    $this->withToken($reader['token'])->postJson('/api/v1/calling-profiles', ['name' => 'X', 'categories' => []])->assertForbidden();
    $this->withToken($member['token'])->postJson('/api/v1/authorization-codes', ['label' => 'X', 'categories' => ['mobile']])->assertForbidden();
    $this->withToken($member['token'])->patchJson('/api/v1/cross-tenant', ['inbound' => 'all'])->assertForbidden();
});

it('keeps other tenants\' profiles and codes out of reach', function () {
    $client = apiClient();
    $foreignProfile = CallingProfile::factory()->create(['categories' => [CallPermission::Allow->value]]);
    $foreignCode = AuthorizationCode::factory()->create();

    $this->withToken($client['token'])->getJson("/api/v1/calling-profiles/{$foreignProfile->id}")->assertNotFound();
    $this->withToken($client['token'])->postJson("/api/v1/authorization-codes/{$foreignCode->id}/reset")->assertNotFound();
});
