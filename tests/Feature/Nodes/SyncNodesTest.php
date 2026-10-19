<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Actions\SyncNodes;
use App\Modules\Nodes\Exceptions\SipDeployerUnavailable;
use App\Modules\Nodes\Models\NodeSecret;
use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.sip_deployer.url' => 'https://deployer.test', 'services.sip_deployer.token' => 'deployer-token']);
});

/**
 * A node manifest as SIP Deployer's API v1 sends it.
 *
 * @return array<string, mixed>
 */
function deployerManifest(int $id = 7, array $overrides = []): array
{
    return [
        'manifest_version' => 1,
        'id' => $id,
        'name' => "sip-{$id}",
        'hostname' => "sip{$id}.example.com",
        'sip_domain' => "sip{$id}.example.com",
        'environment' => 'lab',
        'status' => 'active',
        'region' => 'sgp1',
        'os' => 'ubuntu-24.04',
        'addresses' => ['public' => '203.0.113.20', 'public_ipv6' => null, 'private' => '10.104.0.5', 'management' => '10.104.0.5'],
        'services' => [
            'opensips' => ['release' => '3.6', 'version' => null, 'status' => 'installed', 'installed_at' => null, 'transports' => ['udp', 'tcp'], 'sip_port' => 5060],
            'asterisk' => ['release' => '22', 'version' => '22.11.0', 'status' => 'installed', 'installed_at' => null, 'features' => ['ami', 'ari', 'realtime']],
        ],
        'endpoints' => [
            'opensips_db' => ['host' => '10.104.0.5', 'port' => 3306, 'database' => 'opensips', 'username' => 'manager'],
            'ami' => ['host' => '10.104.0.5', 'port' => 5038, 'username' => 'manager'],
        ],
        'dialplan' => ['entry_context' => 'from-opensips', 'managed_context' => 'managed', 'opensips_endpoint' => 'opensips'],
        'credentials_version' => 'v1',
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function deployerCredentials(int $id = 7, string $version = 'v1', string $amiPassword = 'AmiSecret'): array
{
    return ['data' => [
        'id' => $id,
        'credentials_version' => $version,
        'opensips_db' => ['username' => 'manager', 'password' => 'OpenSipsManager'],
        'ami' => ['username' => 'manager', 'password' => $amiPassword],
    ]];
}

it('copies the manifests and fetches the credentials with the deployer token', function () {
    Http::fake([
        'deployer.test/api/v1/nodes' => Http::response(['data' => [deployerManifest()]]),
        'deployer.test/api/v1/nodes/7/credentials' => Http::response(deployerCredentials()),
    ]);

    $result = app(SyncNodes::class)->handle();

    $node = PbxNode::query()->where('deployer_server_id', 7)->sole();

    expect($result)->toMatchArray(['synced' => 1, 'added' => 1, 'removed' => 0, 'credentials_updated' => 1])
        ->and($node->sip_domain)->toBe('sip7.example.com')
        ->and($node->endpoints['opensips_db']['database'])->toBe('opensips')
        ->and($node->asteriskFeatures())->toBe(['ami', 'ari', 'realtime'])
        ->and($node->credentials()['ami']['password'])->toBe('AmiSecret')
        ->and($node->credentials_version)->toBe('v1');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer deployer-token')
        && $request->hasHeader('Accept', 'application/json'));
});

it('stores the credentials encrypted and keeps them out of the audit log', function () {
    Http::fake([
        'deployer.test/api/v1/nodes' => Http::response(['data' => [deployerManifest()]]),
        'deployer.test/api/v1/nodes/7/credentials' => Http::response(deployerCredentials()),
    ]);

    app(SyncNodes::class)->handle();

    expect(DB::table('node_secrets')->pluck('secret')->implode(' '))->not->toContain('AmiSecret')
        ->and(NodeSecret::query()->where('kind', 'ami')->sole())
        ->secret->toBe('AmiSecret')
        ->rotated_at->toBeNull()
        ->and(AuditLog::query()->pluck('action')->all())->toBe(['node.added', 'node.credentials_updated'])
        ->and(AuditLog::query()->get()->toJson())->not->toContain('AmiSecret');
});

it('fetches the credentials again only when their version changes', function () {
    Http::fakeSequence('deployer.test/api/v1/nodes')
        ->push(['data' => [deployerManifest()]])
        ->push(['data' => [deployerManifest()]])
        ->push(['data' => [deployerManifest(overrides: ['credentials_version' => 'v2'])]]);
    Http::fakeSequence('deployer.test/api/v1/nodes/7/credentials')
        ->push(deployerCredentials())
        ->push(deployerCredentials(version: 'v2', amiPassword: 'Rotated'));

    app(SyncNodes::class)->handle();
    expect(app(SyncNodes::class)->handle()['credentials_updated'])->toBe(0);
    expect(app(SyncNodes::class)->handle()['credentials_updated'])->toBe(1);

    $node = PbxNode::query()->sole();

    expect($node->credentials()['ami']['password'])->toBe('Rotated')
        ->and($node->credentials_version)->toBe('v2')
        ->and($node->secrets()->where('kind', 'ami')->value('rotated_at'))->not->toBeNull()
        ->and($node->secrets()->where('kind', 'opensips_db')->value('rotated_at'))->toBeNull();
    Http::assertSentCount(5);
});

it('drops the credentials when the node no longer has any', function () {
    $node = PbxNode::factory()->create(['deployer_server_id' => 7]);
    Http::fake(['deployer.test/api/v1/nodes' => Http::response(['data' => [deployerManifest(overrides: ['credentials_version' => null, 'endpoints' => []])]])]);

    app(SyncNodes::class)->handle();

    expect($node->fresh()->credentials_version)->toBeNull()
        ->and($node->secrets()->count())->toBe(0);
});

it('keeps the old credentials when the deployer refuses to hand out new ones', function () {
    $node = PbxNode::factory()->create(['deployer_server_id' => 7, 'credentials_version' => 'v1']);
    $credentials = $node->credentials();
    Http::fake([
        'deployer.test/api/v1/nodes' => Http::response(['data' => [deployerManifest(overrides: ['credentials_version' => 'v2'])]]),
        'deployer.test/api/v1/nodes/7/credentials' => Http::response(['message' => 'Invalid ability provided.'], 403),
    ]);

    $result = app(SyncNodes::class)->handle();

    expect($result['credential_errors'])->toHaveKey(7)
        ->and($result['credential_errors'][7])->toContain('abilities')
        ->and($node->fresh()->credentials())->toBe($credentials)
        ->and($node->fresh()->credentials_version)->toBe('v1');
});

it('marks a node the deployer no longer lists as removed and brings it back when listed again', function () {
    $node = PbxNode::factory()->create(['deployer_server_id' => 7, 'credentials_version' => 'v1']);
    $gone = PbxNode::factory()->create(['deployer_server_id' => 8]);
    Http::fakeSequence('deployer.test/api/v1/nodes')
        ->push(['data' => [deployerManifest()]])
        ->push(['data' => [deployerManifest(), deployerManifest(8, ['credentials_version' => null])]]);

    expect(app(SyncNodes::class)->handle()['removed'])->toBe(1)
        ->and($gone->fresh()->removed_at)->not->toBeNull()
        ->and($node->fresh()->removed_at)->toBeNull();

    app(SyncNodes::class)->handle();

    expect($gone->fresh()->removed_at)->toBeNull();
});

it('skips a node whose manifest version it does not understand', function () {
    Http::fake(['deployer.test/api/v1/nodes' => Http::response(['data' => [deployerManifest(overrides: ['manifest_version' => 2])]])]);

    $result = app(SyncNodes::class)->handle();

    expect($result['skipped'])->toBe([7])
        ->and(PbxNode::query()->count())->toBe(0);
});

it('retries a deployer that fails for a moment', function () {
    Http::fakeSequence('deployer.test/api/v1/nodes')
        ->pushStatus(503)
        ->push(['data' => []]);

    expect(app(SyncNodes::class)->handle()['synced'])->toBe(0);
    Http::assertSentCount(2);
});

it('fails clearly when the deployer cannot be asked', function (?string $token, int $status, string $message) {
    config(['services.sip_deployer.token' => $token]);
    Http::fake(['deployer.test/*' => Http::response([], $status)]);

    expect(fn () => app(SyncNodes::class)->handle())->toThrow(SipDeployerUnavailable::class, $message);
})->with([
    'not configured' => [null, 200, 'not configured'],
    'refused token' => ['deployer-token', 401, 'refused the token'],
    'wrong address' => ['deployer-token', 403, 'allowed addresses'],
]);

it('syncs from the command line', function () {
    Http::fake([
        'deployer.test/api/v1/nodes' => Http::response(['data' => [deployerManifest()]]),
        'deployer.test/api/v1/nodes/7/credentials' => Http::response(deployerCredentials()),
    ]);

    $this->artisan('nodes:sync')
        ->expectsOutputToContain('Synced 1 nodes: 1 new')
        ->assertSuccessful();
});

it('fails on the command line when the deployer is not configured', function () {
    config(['services.sip_deployer.url' => null]);

    $this->artisan('nodes:sync')
        ->expectsOutputToContain('not configured')
        ->assertFailed();
});
