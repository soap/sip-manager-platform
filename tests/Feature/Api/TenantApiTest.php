<?php

use App\Models\IdempotencyKey;
use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\SipCredential;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TenantMembership;

it('creates an extension, answers 202 with the SIP account once and records the user', function () {
    $client = apiClient();

    $response = $this->withToken($client['token'])
        ->postJson('/api/v1/extensions', ['extension_number' => '20101', 'display_name' => 'Reception'])
        ->assertAccepted()
        ->assertHeader('ETag', '"1"')
        ->assertJsonPath('data.extension_number', '20101')
        ->assertJsonPath('data.provisioning.status', 'pending')
        ->assertJsonPath('sip_credential.username', '20101')
        ->assertJsonPath('sip_credential.domain', $client['assignment']->tenant->sip_domain)
        ->assertJsonPath('sip_credential.proxy', $client['assignment']->node->sip_domain);

    $extension = Extension::withoutTenantScope()->sole();
    expect($response->json('sip_credential.password'))->toBe(SipCredential::withoutTenantScope()->sole()->secret)
        ->and($response->json('operation_id'))->toBe(ProvisioningOperation::query()->sole()->id)
        ->and(AuditLog::query()->where('action', 'extension.created')->sole()->actor_id)->toBe($client['user']->id);

    $this->withToken($client['token'])
        ->getJson("/api/v1/extensions/{$extension->id}")
        ->assertOk()
        ->assertJsonMissingPath('sip_credential')
        ->assertJsonPath('data.provisioning.operation_id', $response->json('operation_id'));

    $this->withToken($client['token'])
        ->getJson('/api/v1/operations/'.$response->json('operation_id'))
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.type', 'extension.created')
        ->assertJsonPath('data.target', ['type' => 'extension', 'id' => $extension->id]);
});

it('answers a repeated Idempotency-Key with the first answer, without the password', function () {
    $client = apiClient();
    $body = ['extension_number' => '20101', 'display_name' => 'Reception'];

    $first = $this->withToken($client['token'])->withHeader('Idempotency-Key', 'create-20101')->postJson('/api/v1/extensions', $body)->assertAccepted();
    $again = $this->withToken($client['token'])->withHeader('Idempotency-Key', 'create-20101')->postJson('/api/v1/extensions', $body)
        ->assertAccepted()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('operation_id'))->toBe($first->json('operation_id'))
        ->and($again->json('data'))->toBe($first->json('data'))
        ->and($again->json('sip_credential'))->toBe('[redacted]')
        ->and(Extension::withoutTenantScope()->count())->toBe(1)
        ->and(json_encode(IdempotencyKey::query()->sole()->response_body))->not->toContain($first->json('sip_credential.password'));

    $this->withToken($client['token'])->withHeader('Idempotency-Key', 'create-20101')
        ->postJson('/api/v1/extensions', [...$body, 'display_name' => 'Lobby'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This Idempotency-Key was used with a different request.');
});

it('forgets the key of a refused request so the client can fix it and try again', function () {
    $client = apiClient();

    $this->withToken($client['token'])->withHeader('Idempotency-Key', 'k1')
        ->postJson('/api/v1/extensions', ['extension_number' => '0812', 'display_name' => 'Reception'])
        ->assertUnprocessable();

    expect(IdempotencyKey::query()->count())->toBe(0);
});

it('keeps each token in its own tenant', function () {
    $mine = apiClient();
    $other = TenantPbxAssignment::factory()->code('1021')->create(['pbx_node_id' => $mine['assignment']->pbx_node_id]);
    $theirs = Extension::factory()->on($other)->create(['extension_number' => '21101']);
    $theirOperation = apiClient(assignment: $other);
    $operation = $this->withToken($theirOperation['token'])->deleteJson("/api/v1/extensions/{$theirs->id}")->json('operation_id');
    app('auth')->forgetGuards();

    $this->withToken($mine['token'])->getJson("/api/v1/extensions/{$theirs->id}")->assertNotFound();
    $this->withToken($mine['token'])->getJson("/api/v1/operations/{$operation}")->assertNotFound();
    $this->withToken($mine['token'])->getJson('/api/v1/extensions')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($mine['token'])->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.id', $mine['tenant']->id);
});

it('lets read tokens and members only look', function (TenantRole $role, array $abilities) {
    $client = apiClient($role, $abilities);

    $this->withToken($client['token'])->getJson('/api/v1/extensions')->assertOk();
    $this->withToken($client['token'])->postJson('/api/v1/extensions', ['extension_number' => '20101', 'display_name' => 'Reception'])->assertForbidden();

    expect(Extension::withoutTenantScope()->count())->toBe(0);
})->with([
    'read token of an admin' => [TenantRole::Admin, ['read']],
    'write token of a member' => [TenantRole::Member, ['read', 'write']],
]);

it('refuses requests without a tenant token, of a former member or of a suspended tenant', function () {
    $this->getJson('/api/v1/tenant')->assertUnauthorized();

    $user = User::factory()->create();
    $this->withToken($user->createToken('no tenant', ['read'])->plainTextToken)->getJson('/api/v1/tenant')->assertForbidden();
    app('auth')->forgetGuards();

    $former = apiClient();
    TenantMembership::withoutTenantScope()->where('user_id', $former['user']->id)->delete();
    $this->withToken($former['token'])->getJson('/api/v1/tenant')->assertForbidden();
    app('auth')->forgetGuards();

    $suspended = apiClient();
    $suspended['tenant']->update(['status' => TenantStatus::Suspended]);
    $this->withToken($suspended['token'])->getJson('/api/v1/tenant')->assertForbidden();
});

it('does not take the session of the web pages', function () {
    $client = apiClient();

    $this->actingAs($client['user'])->getJson('/api/v1/tenant')->assertUnauthorized();
});

it('describes the tenant, the role and where phones register', function () {
    $client = apiClient(TenantRole::Owner, ['read']);

    $this->withToken($client['token'])->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.token_abilities', ['read'])
        ->assertJsonPath('data.code', $client['assignment']->tenant->code)
        ->assertJsonPath('data.sip_domain', $client['assignment']->tenant->sip_domain)
        ->assertJsonPath('data.pbx.sip_server.domain', $client['assignment']->tenant->sip_domain)
        ->assertJsonPath('data.pbx.sip_server.proxy', $client['assignment']->node->sip_domain);
});

it('changes an extension only against the version the client saw', function () {
    $client = apiClient();
    $extension = Extension::factory()->on($client['assignment'])->create(['extension_number' => '20101', 'display_name' => 'Reception']);

    $this->withToken($client['token'])->patchJson("/api/v1/extensions/{$extension->id}", ['display_name' => 'Lobby'])->assertStatus(428);
    $this->withToken($client['token'])->patchJson("/api/v1/extensions/{$extension->id}", ['display_name' => 'Lobby', 'version' => 7])
        ->assertConflict()
        ->assertJsonPath('data.version', 1);

    // a rename does not touch the PBX
    $this->withToken($client['token'])->withHeader('If-Match', '"1"')
        ->patchJson("/api/v1/extensions/{$extension->id}", ['display_name' => 'Lobby'])
        ->assertOk()
        ->assertHeader('ETag', '"2"')
        ->assertJsonPath('data.display_name', 'Lobby')
        ->assertJsonPath('data.status', 'active');

    $this->withToken($client['token'])
        ->patchJson("/api/v1/extensions/{$extension->id}", ['status' => 'disabled', 'version' => 2])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'disabled')
        ->assertJsonPath('data.version', 3)
        ->assertJsonPath('data.provisioning.status', 'pending');

    expect(ProvisioningOperation::query()->count())->toBe(1);
});

it('rotates the SIP password and deletes an extension, each with an operation', function () {
    $client = apiClient();
    $extension = Extension::factory()->on($client['assignment'])->create(['extension_number' => '20101']);
    $before = SipCredential::withoutTenantScope()->sole()->secret;

    $rotated = $this->withToken($client['token'])->postJson("/api/v1/extensions/{$extension->id}/rotate-credential")->assertAccepted();

    expect($rotated->json('sip_credential.password'))->not->toBe($before)
        ->toBe(SipCredential::withoutTenantScope()->sole()->secret);

    $this->withToken($client['token'])->deleteJson("/api/v1/extensions/{$extension->id}")
        ->assertAccepted()
        ->assertJsonStructure(['operation_id']);

    expect(Extension::withoutTenantScope()->count())->toBe(0)
        ->and(ProvisioningOperation::query()->count())->toBe(2);
});

it('lists extensions page by page with their provisioning state', function () {
    $client = apiClient();
    Extension::factory()->on($client['assignment'])->create(['extension_number' => '20102']);
    Extension::factory()->on($client['assignment'])->create(['extension_number' => '20101', 'status' => 'disabled']);
    Extension::factory()->on($client['assignment'])->create(['extension_number' => '20103']);

    $this->withToken($client['token'])->getJson('/api/v1/extensions?per_page=2')
        ->assertOk()
        ->assertJsonPath('data.0.extension_number', '20101')
        ->assertJsonPath('data.0.provisioning', null)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(2, 'data');

    $this->withToken($client['token'])->getJson('/api/v1/extensions?status=disabled')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken($client['token'])->getJson('/api/v1/extensions?per_page=500')->assertUnprocessable();
});
