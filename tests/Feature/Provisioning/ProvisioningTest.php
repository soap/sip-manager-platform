<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Infrastructure\Asterisk\AmiAdapter;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiActionFailed;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiUnavailable;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Actions\ApplyRevision;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Enums\RevisionStatus;
use App\Modules\Provisioning\Jobs\ApplyConfigRevision;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\OutboxMessage;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Provisioning\ProvisioningHealth;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Telephony\Models\SipCredential;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('creates an extension with a SIP account shown once and requests provisioning', function () {
    [$tenant, $assignment] = provisioningTenant();

    $response = $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('sipCredential.username', '201')
        ->assertInertiaFlash('sipCredential.domain', $tenant->sip_domain)
        ->assertInertiaFlash('sipCredential.proxy', $assignment->node->sip_domain);

    $credential = SipCredential::withoutTenantScope()->sole();
    $password = $credential->secret;

    expect(strlen($password))->toBe(SipCredential::PASSWORD_LENGTH)
        ->and(DB::table('sip_credentials')->value('secret'))->not->toContain($password)
        ->and(AuditLog::query()->get()->toJson())->not->toContain($password);

    $revision = ConfigRevision::query()->sole();
    expect($revision)
        ->target->toBe('number:1020201')
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
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    $this->artisan('provisioning:relay')->assertSuccessful();
    $this->artisan('provisioning:relay')->assertSuccessful();

    Queue::assertPushedOn(ApplyConfigRevision::QUEUE, ApplyConfigRevision::class);
    Queue::assertPushed(ApplyConfigRevision::class, 1);
    expect(OutboxMessage::query()->sole()->dispatched_at)->not->toBeNull();
});

it('writes an active extension to OpenSIPS and the dialplan, and reads it back', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    $password = SipCredential::withoutTenantScope()->sole()->secret;
    $domain = $tenant->sip_domain;

    applyPendingRevisions();

    // the account lives in the tenant's own SIP domain, which is also its realm
    expect($node->subscribers["201@{$domain}"])->toBe([
        'username' => '201',
        'domain' => $domain,
        'ha1' => md5("201:{$domain}:{$password}"),
        'ha1_sha256' => hash('sha256', "201:{$domain}:{$password}"),
    ])
        // OpenSIPS knows the tenant code of the domain's phones from its domain row
        ->and($node->domains['1020'])->toBe(['domain' => $domain, 'attrs' => '1020'])
        // and Asterisk sees the number with the tenant code first, in both directions
        ->and($node->extens['1020201'])->toBe([
            ['priority' => 1, 'app' => 'Dial', 'appdata' => 'PJSIP/1020201@opensips,30'],
            ['priority' => 2, 'app' => 'Hangup', 'appdata' => ''],
        ])
        ->and($node->extens['denied'])->toBe([
            ['priority' => 1, 'app' => 'Playback', 'appdata' => 'ss-noservice'],
            ['priority' => 2, 'app' => 'Hangup', 'appdata' => '21'],
        ]);

    $revision = ConfigRevision::query()->sole();
    expect($revision->status)->toBe(RevisionStatus::Applied)
        ->and($revision->checksum)->toHaveLength(64)
        ->and($revision->compiled['subscriber'])->toBe(['username' => '201', 'domain' => $domain])
        ->and(json_encode($revision->compiled))->not->toContain(md5("201:{$domain}:{$password}"))
        ->and(ProvisioningOperation::query()->sole()->status)->toBe(OperationStatus::Applied);
});

it('keeps the same number of two tenants on one node apart', function () {
    $node = fakeNode();
    $first = TenantPbxAssignment::factory()->code('1020')->create();
    $second = TenantPbxAssignment::factory()->code('1021')->create(['pbx_node_id' => $first->pbx_node_id]);
    Extension::factory()->on($first)->create(['extension_number' => '201']);
    [$tenant] = provisioningTenant($second);

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Sales']);
    $this->post(route('tenant.extensions.reapply', [$tenant, Extension::withoutTenantScope()->where('tenant_id', $tenant->id)->value('id')]));
    DB::transaction(fn () => app(RequestProvisioning::class)->forNumber($first->pbx_node_id, $first->tenant_id, '201', OperationType::Reapplied, Extension::MORPH, 1));
    applyPendingRevisions();

    expect(array_keys($node->subscribers))->toEqualCanonicalizing(["201@{$first->tenant->sip_domain}", "201@{$tenant->sip_domain}"])
        ->and($node->extens)->toHaveKeys(['1020201', '1021201'])
        ->and($node->domains)->toHaveKeys(['1020', '1021']);
});

it('takes a disabled extension out of service and a deleted one off the node', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    applyPendingRevisions();
    $extension = Extension::withoutTenantScope()->sole();

    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => 'Reception', 'status' => 'disabled']);
    applyPendingRevisions();

    // the subscriber stays as a disabled account, which OpenSIPS refuses without a failed authentication for fail2ban
    expect($node->subscribers["201@{$tenant->sip_domain}"])->toMatchArray(['username' => '201', 'ha1' => 'disabled', 'ha1_sha256' => 'disabled'])
        ->and($node->extens['1020201'])->toBe([['priority' => 1, 'app' => 'Goto', 'appdata' => 'denied,1']]);

    $this->delete(route('tenant.extensions.destroy', [$tenant, $extension->id]));
    applyPendingRevisions();

    expect($node->subscribers)->toBe([])
        ->and($node->extens)->not->toHaveKey('1020201')
        ->and(ProvisioningOperation::query()->latest('id')->first()->type)->toBe(OperationType::ExtensionDeleted);
});

it('only provisions a rename when the status changes too', function () {
    fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
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
    $assignment = TenantPbxAssignment::factory()->code('1020')->create();
    Extension::factory()->on($assignment)->count(2)->create();
    $numbers = Extension::withoutTenantScope()->pluck('extension_number')->all();

    $this->patch(route('admin.assignments.update', $assignment), ['status' => 'suspended'])->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect(ProvisioningOperation::query()->where('type', OperationType::AssignmentChanged)->count())->toBe(2)
        ->and(array_unique(array_column($node->subscribers, 'ha1')))->toBe(['disabled']);
    foreach ($numbers as $number) {
        expect($node->extens["1020{$number}"])->toBe([['priority' => 1, 'app' => 'Goto', 'appdata' => 'denied,1']]);
    }

    $this->patch(route('admin.assignments.update', $assignment), ['status' => 'active']);
    applyPendingRevisions();

    expect(array_column($node->subscribers, 'username'))->toEqualCanonicalizing($numbers)
        ->and(array_column($node->subscribers, 'ha1'))->not->toContain('disabled');
});

it('rotates a SIP password and writes the new digest', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    applyPendingRevisions();
    $account = "201@{$tenant->sip_domain}";
    $before = $node->subscribers[$account]['ha1'];
    $extension = Extension::withoutTenantScope()->sole();

    $this->post(route('tenant.extensions.rotate-credential', [$tenant, $extension->id]))
        ->assertInertiaFlash('sipCredential.username', '201');
    applyPendingRevisions();

    $credential = SipCredential::withoutTenantScope()->sole();
    expect($credential->rotated_at)->not->toBeNull()
        ->and($node->subscribers[$account]['ha1'])->not->toBe($before)
        ->and($node->subscribers[$account]['ha1'])->toBe(md5("201:{$tenant->sip_domain}:{$credential->secret}"));
});

it('retries a node that cannot be reached with backoff, then fails', function () {
    $node = fakeNode();
    $node->failWith = new QueryException('mariadb', 'delete from extensions', [], new PDOException('SQLSTATE[HY000] [2002] Connection refused'));
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
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
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    expect(app(ApplyRevision::class)->handle(ConfigRevision::query()->sole()->id))->toBeNull()
        ->and(ProvisioningOperation::query()->sole())
        ->status->toBe(OperationStatus::Failed)
        ->error_code->toBe('node_auth_failed');
});

it('retries when the rows read back differ from those written', function () {
    $node = fakeNode();
    $node->corruptReadBack = true;
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    expect(app(ApplyRevision::class)->handle(ConfigRevision::query()->sole()->id))->toBe(10)
        ->and(ProvisioningOperation::query()->sole()->error_code)->toBe('read_back_mismatch');
});

it('confirms over AMI that Asterisk sees the written dialplan', function () {
    fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    applyPendingRevisions();

    expect(ConfigRevision::query()->sole()->apply_result['asterisk'])->toBe('confirmed');
});

it('retries when Asterisk does not see the rows the database holds', function () {
    $node = fakeNode();
    $node->asteriskBlind = true;
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    expect(app(ApplyRevision::class)->handle(ConfigRevision::query()->sole()->id))->toBe(10)
        ->and(ProvisioningOperation::query()->sole())
        ->error_code->toBe('read_back_mismatch')
        ->error_message->toContain('does not see priorities 1, 2 of 1020201 in from-opensips');
});

it('trusts the database read-back on a node without AMI', function () {
    fakeNode();
    $assignment = TenantPbxAssignment::factory()->code('1020')->create();
    $assignment->node->update(['endpoints' => collect($assignment->node->endpoints)->except('ami')->all()]);
    [$tenant] = provisioningTenant($assignment);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    applyPendingRevisions();

    expect(ConfigRevision::query()->sole())
        ->status->toBe(RevisionStatus::Applied)
        ->apply_result->asterisk->toBe('unchecked');
});

it('retries when AMI does not answer and fails when it refuses the login', function (Throwable $exception, ?int $retryIn, string $errorCode) {
    fakeNode();
    $this->mock(AmiAdapter::class)->shouldReceive('missingPriorities')->andThrow($exception);
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    expect(app(ApplyRevision::class)->handle(ConfigRevision::query()->sole()->id))->toBe($retryIn)
        ->and(ProvisioningOperation::query()->sole()->error_code)->toBe($errorCode);
})->with([
    'timeout' => [AmiUnavailable::timedOut(), 10, 'node_unreachable'],
    'login refused' => [AmiActionFailed::for('Login', 'Authentication failed'), null, 'node_auth_failed'],
    'no such function' => [AmiActionFailed::for('Getvar', 'Function DIALPLAN_EXISTS not found'), null, 'ami_error'],
]);

it('refuses a node without the realtime feature', function () {
    fakeNode();
    $assignment = TenantPbxAssignment::factory()->code('1020')->create();
    $assignment->node->update(['services' => [...$assignment->node->services, 'asterisk' => [...$assignment->node->services['asterisk'], 'features' => ['ami']]]]);
    [$tenant] = provisioningTenant($assignment);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    applyPendingRevisions();

    expect(ProvisioningOperation::query()->sole())
        ->status->toBe(OperationStatus::Failed)
        ->error_code->toBe('node_capability');
});

it('lets a newer revision of the same number stand in for an older one', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    $extension = Extension::withoutTenantScope()->sole();
    $this->post(route('tenant.extensions.reapply', [$tenant, $extension->id]));

    applyPendingRevisions();

    [$older, $newer] = ConfigRevision::query()->orderBy('id')->get()->all();
    expect($older->status)->toBe(RevisionStatus::Superseded)
        ->and($newer->status)->toBe(RevisionStatus::Applied)
        ->and($newer->revision_number)->toBe(2)
        ->and($node->subscribers)->toHaveKey("201@{$tenant->sip_domain}");
});

it('shows the provisioning state of each extension', function () {
    fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->where('extensions.0.provisioning.status', 'pending')
            ->where('assignment.server.port', 5060));

    applyPendingRevisions();

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page->where('extensions.0.provisioning.status', 'applied'));
});

it('warns when a change has waited too long and tells which part is not running', function () {
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page->where('provisioningStalled', null));

    $this->travel(ProvisioningHealth::STALL_SECONDS + 1)->seconds();

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page->where('provisioningStalled.reason', 'relay'));

    // relayed, but no worker took the job
    Queue::fake();
    $this->artisan('provisioning:relay');

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page->where('provisioningStalled.reason', 'worker'));
});

it('does not count retrying or finished operations, or other tenants, as stalled', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $other = TenantPbxAssignment::factory()->code('1021')->create();
    DB::transaction(fn () => app(RequestProvisioning::class)->forNumber($other->pbx_node_id, $other->tenant_id, '201', OperationType::Reapplied, Extension::MORPH, 1));

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    $node->failWith = new QueryException('mariadb', 'delete', [], new PDOException('SQLSTATE[HY000] [2002] Connection refused'));
    app(ApplyRevision::class)->handle(ConfigRevision::query()->where('target', 'number:1020201')->value('id'));
    OutboxMessage::query()->update(['dispatched_at' => now()]);

    $this->travel(ProvisioningHealth::STALL_SECONDS + 1)->seconds();

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page->where('provisioningStalled', null));
});

it('compiles a ring group that rings all members, then fails over', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $first = Extension::factory()->on($assignment)->create(['extension_number' => '201']);
    $second = Extension::factory()->on($assignment)->create(['extension_number' => '202']);
    $disabled = Extension::factory()->on($assignment)->disabled()->create(['extension_number' => '203']);

    $this->post(route('tenant.ring-groups.store', $tenant), [
        'number' => '500', 'name' => 'Sales', 'strategy' => 'ring_all', 'ring_seconds' => 25,
        'members' => [$first->id, $disabled->id, $second->id], 'failover_type' => 'extension', 'failover_id' => $first->id,
    ])->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect($node->extens['1020500'])->toBe([
        ['priority' => 1, 'app' => 'Dial', 'appdata' => 'PJSIP/1020201@opensips&PJSIP/1020202@opensips,25'],
        ['priority' => 2, 'app' => 'Goto', 'appdata' => '1020201,1'],
    ])
        ->and($node->subscribers)->not->toHaveKey("500@{$tenant->sip_domain}");
});

it('compiles a sequential ring group one member at a time, hanging up at the end', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $first = Extension::factory()->on($assignment)->create(['extension_number' => '201']);
    $second = Extension::factory()->on($assignment)->create(['extension_number' => '202']);

    $this->post(route('tenant.ring-groups.store', $tenant), [
        'number' => '500', 'name' => 'Support', 'strategy' => 'sequential', 'ring_seconds' => 10,
        'members' => [$second->id, $first->id], 'failover_type' => 'hangup',
    ]);
    applyPendingRevisions();

    expect(array_column($node->extens['1020500'], 'appdata', 'priority'))->toBe([
        1 => 'PJSIP/1020202@opensips,10',
        2 => 'PJSIP/1020201@opensips,10',
        3 => '',
    ]);
});

it('sends callers of a disabled ring group to denied', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $group = RingGroup::factory()->on($assignment)->create(['number' => '500']);

    $this->patch(route('tenant.ring-groups.update', [$tenant, $group->id]), [
        'name' => $group->name, 'strategy' => 'ring_all', 'ring_seconds' => 20, 'members' => [],
        'failover_type' => 'hangup', 'status' => 'disabled',
    ])->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect($node->extens['1020500'])->toBe([['priority' => 1, 'app' => 'Goto', 'appdata' => 'denied,1']]);
});

it('refuses a node that still serves one shared SIP domain', function () {
    fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    $assignment->node->update(['dialplan' => ['entry_context' => 'from-opensips', 'managed_context' => 'managed', 'opensips_endpoint' => 'opensips']]);

    applyPendingRevisions();

    expect(ProvisioningOperation::query()->sole())
        ->status->toBe(OperationStatus::Failed)
        ->error_code->toBe('node_capability');
});

it('returns the same operation for a repeated idempotency key', function () {
    $assignment = TenantPbxAssignment::factory()->create();

    [$first, $second] = DB::transaction(fn (): array => [
        app(RequestProvisioning::class)->forNumber($assignment->pbx_node_id, $assignment->tenant_id, '201', OperationType::Reapplied, Extension::MORPH, 1, 'key-1'),
        app(RequestProvisioning::class)->forNumber($assignment->pbx_node_id, $assignment->tenant_id, '201', OperationType::Reapplied, Extension::MORPH, 1, 'key-1'),
    ]);

    expect($second->id)->toBe($first->id)
        ->and(ConfigRevision::query()->count())->toBe(1);
});
