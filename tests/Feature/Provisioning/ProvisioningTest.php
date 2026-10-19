<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Infrastructure\Asterisk\RealtimeAdapter;
use App\Modules\Infrastructure\OpenSips\OpenSipsDbAdapter;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Nodes\NodeConnections;
use App\Modules\Provisioning\Actions\ApplyRevision;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Enums\RevisionStatus;
use App\Modules\Provisioning\Jobs\ApplyConfigRevision;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\OutboxMessage;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\SipCredential;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The node's two databases in memory: what the adapters would read and write on MariaDB.
 */
class FakeNodeDatabases
{
    /** @var array<string, array{username: string, domain: string, ha1: string, ha1_sha256: string}> */
    public array $subscribers = [];

    /** @var array<string, list<array{priority: int, app: string, appdata: string}>> */
    public array $extens = [];

    public ?Throwable $failWith = null;

    public bool $corruptReadBack = false;
}

/**
 * Swaps the adapters for ones backed by FakeNodeDatabases.
 */
function fakeNode(): FakeNodeDatabases
{
    $store = new FakeNodeDatabases;
    $connections = app(NodeConnections::class);

    app()->instance(RealtimeAdapter::class, new class($connections, $store) extends RealtimeAdapter
    {
        public function __construct(NodeConnections $connections, private FakeNodeDatabases $store)
        {
            parent::__construct($connections);
        }

        public function replaceExten(PbxNode $node, string $exten, array $priorities): void
        {
            if ($this->store->failWith !== null) {
                throw $this->store->failWith;
            }

            $this->store->extens[$exten] = $priorities;

            if ($priorities === []) {
                unset($this->store->extens[$exten]);
            }
        }

        public function exten(PbxNode $node, string $exten): array
        {
            return $this->store->extens[$exten] ?? [];
        }
    });

    app()->instance(OpenSipsDbAdapter::class, new class($connections, $store) extends OpenSipsDbAdapter
    {
        public function __construct(NodeConnections $connections, private FakeNodeDatabases $store)
        {
            parent::__construct($connections);
        }

        public function replaceSubscriber(PbxNode $node, string $username, ?array $subscriber): void
        {
            unset($this->store->subscribers[$username]);

            if ($subscriber !== null) {
                $this->store->subscribers[$username] = $subscriber;
            }
        }

        public function subscribers(PbxNode $node, string $username): array
        {
            $row = $this->store->subscribers[$username] ?? null;

            if ($row !== null && $this->store->corruptReadBack) {
                $row['ha1'] = 'tampered';
            }

            return $row === null ? [] : [$row];
        }
    });

    return $store;
}

/**
 * Applies every revision waiting in the outbox, as the relay and the queue worker would.
 */
function applyPendingRevisions(): void
{
    OutboxMessage::query()->whereNull('dispatched_at')->orderBy('id')->get()->each(function (OutboxMessage $message): void {
        app(ApplyRevision::class)->handle($message->payload['config_revision_id']);
        $message->update(['dispatched_at' => now()]);
    });
}

/**
 * The signed-in admin of a tenant with prefix 20 on a node.
 *
 * @return array{Tenant, TenantPbxAssignment}
 */
function provisioningTenant(?TenantPbxAssignment $assignment = null): array
{
    $user = signIn();
    $assignment ??= TenantPbxAssignment::factory()->prefix('20')->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $assignment->tenant_id, 'user_id' => $user->id, 'role' => TenantRole::Admin]);

    return [$assignment->tenant, $assignment];
}

it('creates an extension with a SIP account shown once and requests provisioning', function () {
    [$tenant, $assignment] = provisioningTenant();

    $response = $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('sipCredential.username', '20101')
        ->assertInertiaFlash('sipCredential.domain', $assignment->node->sip_domain);

    $credential = SipCredential::withoutTenantScope()->sole();
    $password = $credential->secret;

    expect(strlen($password))->toBe(SipCredential::PASSWORD_LENGTH)
        ->and(DB::table('sip_credentials')->value('secret'))->not->toContain($password)
        ->and(AuditLog::query()->get()->toJson())->not->toContain($password);

    $revision = ConfigRevision::query()->sole();
    expect($revision)
        ->target->toBe('number:20101')
        ->status->toBe(RevisionStatus::Draft)
        ->revision_number->toBe(1)
        ->and(ProvisioningOperation::query()->sole())
        ->type->toBe(OperationType::ExtensionCreated)
        ->status->toBe(OperationStatus::Pending)
        ->and(OutboxMessage::query()->sole()->payload)->toBe(['config_revision_id' => $revision->id, 'pbx_node_id' => $assignment->pbx_node_id]);
});

it('makes SIP passwords without characters that look alike', function () {
    $passwords = implode('', array_map(fn (): string => SipCredential::newPassword(), range(1, 50)));

    expect(strlen($passwords))->toBe(50 * SipCredential::PASSWORD_LENGTH)
        ->and($passwords)->toMatch('/^[A-HJ-NP-Za-km-np-z2-9]+$/');
});

it('relays the outbox to the provisioning queue once', function () {
    Queue::fake();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);

    $this->artisan('provisioning:relay')->assertSuccessful();
    $this->artisan('provisioning:relay')->assertSuccessful();

    Queue::assertPushedOn(ApplyConfigRevision::QUEUE, ApplyConfigRevision::class);
    Queue::assertPushed(ApplyConfigRevision::class, 1);
    expect(OutboxMessage::query()->sole()->dispatched_at)->not->toBeNull();
});

it('writes an active extension to OpenSIPS and the dialplan, and reads it back', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);
    $password = SipCredential::withoutTenantScope()->sole()->secret;
    $domain = $assignment->node->sip_domain;

    applyPendingRevisions();

    expect($node->subscribers['20101'])->toBe([
        'username' => '20101',
        'domain' => $domain,
        'ha1' => md5("20101:{$domain}:{$password}"),
        'ha1_sha256' => hash('sha256', "20101:{$domain}:{$password}"),
    ])
        ->and($node->extens['20101'])->toBe([
            ['priority' => 1, 'app' => 'GotoIf', 'appdata' => '$["${CALLERID(num):0:2}" != "20"]?denied,1'],
            ['priority' => 2, 'app' => 'Dial', 'appdata' => 'PJSIP/20101@opensips,30'],
            ['priority' => 3, 'app' => 'Hangup', 'appdata' => ''],
        ])
        ->and($node->extens['denied'])->toBe([
            ['priority' => 1, 'app' => 'Playback', 'appdata' => 'ss-noservice'],
            ['priority' => 2, 'app' => 'Hangup', 'appdata' => '21'],
        ]);

    $revision = ConfigRevision::query()->sole();
    expect($revision->status)->toBe(RevisionStatus::Applied)
        ->and($revision->checksum)->toHaveLength(64)
        ->and($revision->compiled['subscriber'])->toBe(['username' => '20101', 'domain' => $domain])
        ->and(json_encode($revision->compiled))->not->toContain(md5("20101:{$domain}:{$password}"))
        ->and(ProvisioningOperation::query()->sole()->status)->toBe(OperationStatus::Applied);
});

it('lets each tenant reach only its own prefix', function () {
    $node = fakeNode();
    $first = TenantPbxAssignment::factory()->prefix('20')->create();
    $second = TenantPbxAssignment::factory()->prefix('21')->create(['pbx_node_id' => $first->pbx_node_id]);
    [$tenant] = provisioningTenant($second);

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '21001', 'display_name' => 'Sales']);
    applyPendingRevisions();

    expect($node->extens['21001'][0]['appdata'])->toBe('$["${CALLERID(num):0:2}" != "21"]?denied,1');
});

it('takes a disabled extension out of service and a deleted one off the node', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);
    applyPendingRevisions();
    $extension = Extension::withoutTenantScope()->sole();

    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => 'Reception', 'status' => 'disabled']);
    applyPendingRevisions();

    expect($node->subscribers)->not->toHaveKey('20101')
        ->and($node->extens['20101'])->toBe([['priority' => 1, 'app' => 'Goto', 'appdata' => 'denied,1']]);

    $this->delete(route('tenant.extensions.destroy', [$tenant, $extension->id]));
    applyPendingRevisions();

    expect($node->subscribers)->toBe([])
        ->and($node->extens)->not->toHaveKey('20101')
        ->and(ProvisioningOperation::query()->latest('id')->first()->type)->toBe(OperationType::ExtensionDeleted);
});

it('only provisions a rename when the status changes too', function () {
    fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);
    $extension = Extension::withoutTenantScope()->sole();

    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => 'Front desk', 'status' => 'active', 'version' => 1])
        ->assertSessionHasNoErrors();

    expect(ConfigRevision::query()->count())->toBe(1)
        ->and($extension->fresh()->version)->toBe(2);

    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => 'Lobby', 'status' => 'active', 'version' => 1])
        ->assertSessionHasErrors(['extension' => 'Someone else changed this extension. Reload and try again.']);
});

it('suspends and resumes every number of a tenant with its assignment', function () {
    $node = fakeNode();
    signIn(User::factory()->platformAdmin()->create());
    $assignment = TenantPbxAssignment::factory()->prefix('20')->create();
    Extension::factory()->on($assignment)->count(2)->create();
    $numbers = Extension::withoutTenantScope()->pluck('extension_number')->all();

    $this->patch(route('admin.assignments.update', $assignment), ['prefix' => '20', 'status' => 'suspended'])->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect(ProvisioningOperation::query()->where('type', OperationType::AssignmentChanged)->count())->toBe(2)
        ->and($node->subscribers)->toBe([]);
    foreach ($numbers as $number) {
        expect($node->extens[$number])->toBe([['priority' => 1, 'app' => 'Goto', 'appdata' => 'denied,1']]);
    }

    $this->patch(route('admin.assignments.update', $assignment), ['prefix' => '20', 'status' => 'active']);
    applyPendingRevisions();

    expect(array_keys($node->subscribers))->toEqualCanonicalizing($numbers);
});

it('rotates a SIP password and writes the new digest', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);
    applyPendingRevisions();
    $before = $node->subscribers['20101']['ha1'];
    $extension = Extension::withoutTenantScope()->sole();

    $this->post(route('tenant.extensions.rotate-credential', [$tenant, $extension->id]))
        ->assertInertiaFlash('sipCredential.username', '20101');
    applyPendingRevisions();

    $credential = SipCredential::withoutTenantScope()->sole();
    expect($credential->rotated_at)->not->toBeNull()
        ->and($node->subscribers['20101']['ha1'])->not->toBe($before)
        ->and($node->subscribers['20101']['ha1'])->toBe(md5("20101:{$node->subscribers['20101']['domain']}:{$credential->secret}"));
});

it('retries a node that cannot be reached with backoff, then fails', function () {
    $node = fakeNode();
    $node->failWith = new QueryException('mariadb', 'delete from extensions', [], new PDOException('SQLSTATE[HY000] [2002] Connection refused'));
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);
    $revision = ConfigRevision::query()->sole();

    expect(app(ApplyRevision::class)->handle($revision->id))->toBe(10)
        ->and(ProvisioningOperation::query()->sole())
        ->status->toBe(OperationStatus::Retrying)
        ->error_code->toBe('node_unreachable')
        ->attempts->toBe(1)
        ->next_attempt_at->not->toBeNull();

    foreach ([30, 120, 600, 1800] as $delay) {
        expect(app(ApplyRevision::class)->handle($revision->id))->toBe($delay);
    }

    expect(app(ApplyRevision::class)->handle($revision->id))->toBeNull()
        ->and(ProvisioningOperation::query()->sole()->status)->toBe(OperationStatus::Failed)
        ->and($revision->fresh()->status)->toBe(RevisionStatus::Failed);
});

it('fails at once when the node refuses the database login', function () {
    $node = fakeNode();
    $node->failWith = new QueryException('mariadb', 'delete from extensions', [], new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'asterisk_manager'@'10.0.0.1' (using password: YES)"));
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);

    expect(app(ApplyRevision::class)->handle(ConfigRevision::query()->sole()->id))->toBeNull()
        ->and(ProvisioningOperation::query()->sole())
        ->status->toBe(OperationStatus::Failed)
        ->error_code->toBe('node_auth_failed');
});

it('retries when the rows read back differ from those written', function () {
    $node = fakeNode();
    $node->corruptReadBack = true;
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);

    expect(app(ApplyRevision::class)->handle(ConfigRevision::query()->sole()->id))->toBe(10)
        ->and(ProvisioningOperation::query()->sole()->error_code)->toBe('read_back_mismatch');
});

it('refuses a node without the realtime feature', function () {
    fakeNode();
    $assignment = TenantPbxAssignment::factory()->prefix('20')->create();
    $assignment->node->update(['services' => [...$assignment->node->services, 'asterisk' => [...$assignment->node->services['asterisk'], 'features' => ['ami']]]]);
    [$tenant] = provisioningTenant($assignment);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);

    applyPendingRevisions();

    expect(ProvisioningOperation::query()->sole())
        ->status->toBe(OperationStatus::Failed)
        ->error_code->toBe('node_capability');
});

it('lets a newer revision of the same number stand in for an older one', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);
    $extension = Extension::withoutTenantScope()->sole();
    $this->post(route('tenant.extensions.reapply', [$tenant, $extension->id]));

    applyPendingRevisions();

    [$older, $newer] = ConfigRevision::query()->orderBy('id')->get()->all();
    expect($older->status)->toBe(RevisionStatus::Superseded)
        ->and($newer->status)->toBe(RevisionStatus::Applied)
        ->and($newer->revision_number)->toBe(2)
        ->and($node->subscribers)->toHaveKey('20101');
});

it('shows the provisioning state of each extension', function () {
    fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '20101', 'display_name' => 'Reception']);

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->where('extensions.0.provisioning.status', 'pending')
            ->where('assignment.server.port', 5060));

    applyPendingRevisions();

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page->where('extensions.0.provisioning.status', 'applied'));
});

it('returns the same operation for a repeated idempotency key', function () {
    $assignment = TenantPbxAssignment::factory()->create();

    [$first, $second] = DB::transaction(fn (): array => [
        app(RequestProvisioning::class)->forNumber($assignment->pbx_node_id, $assignment->tenant_id, '20101', OperationType::Reapplied, Extension::MORPH, 1, 'key-1'),
        app(RequestProvisioning::class)->forNumber($assignment->pbx_node_id, $assignment->tenant_id, '20101', OperationType::Reapplied, Extension::MORPH, 1, 'key-1'),
    ]);

    expect($second->id)->toBe($first->id)
        ->and(ConfigRevision::query()->count())->toBe(1);
});
