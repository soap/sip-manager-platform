<?php

use App\Models\User;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Actions\ReconcileNode;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Enums\ReconcileStatus;
use App\Modules\Provisioning\Jobs\ReconcilePbxNode;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Provisioning\Models\ReconcileRun;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Reconciles the node and applies what it asked to write again, as the scheduler and the worker would.
 */
function reconcile(PbxNode $node): ReconcileRun
{
    $run = app(ReconcileNode::class)->handle($node, ReconcileRun::TRIGGER_MANUAL);
    applyPendingRevisions();

    return $run;
}

/**
 * A tenant on a node with extension 201 (voicemail on) written to it, and its domain and calling
 * dialplan brought in line by a first reconcile.
 *
 * @return array{FakeNodeDatabases, Tenant, PbxNode}
 */
function reconciledTenant(): array
{
    $store = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    test()->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    test()->patch(route('tenant.extensions.voicemail', [$tenant, Extension::withoutTenantScope()->sole()->id]), ['enabled' => true]);
    applyPendingRevisions();
    reconcile($assignment->node);

    return [$store, $tenant, $assignment->node];
}

it('finds nothing to do on a node that holds what it should', function () {
    [, , $node] = reconciledTenant();

    $run = reconcile($node);

    expect($run->status)->toBe(ReconcileStatus::Clean)
        ->and($run->targets_checked)->toBe(3)
        ->and($run->drift)->toBe([])
        ->and($run->fixed_count)->toBe(0)
        ->and($run->finished_at)->not->toBeNull();
});

it('writes back everything a reinstall wiped, then finds the node clean', function () {
    [$store, $tenant, $node] = reconciledTenant();
    $store->extens = [];
    $store->subscribers = [];
    $store->domains = [];
    $store->mailboxes = [];

    $run = reconcile($node);

    expect($run->status)->toBe(ReconcileStatus::Drift)
        ->and($run->changed_count)->toBe(0)
        ->and($run->unexpected_count)->toBe(0)
        ->and($run->fixed_count)->toBe(3)
        ->and(collect($run->drift)->pluck('item')->all())->toContain(
            'exten denied',
            "domain {$tenant->sip_domain}",
            "subscriber 201@{$tenant->sip_domain}",
            'exten 1020201',
            'exten 1020p201',
            'mailbox 201@t1020',
            'exten 1020*97',
        )
        ->and(ProvisioningOperation::query()->where('type', OperationType::Reconciled)->pluck('status')->unique()->map->value->all())->toBe(['applied'])
        ->and($store->extens)->toHaveKeys(['denied', '1020201', '1020p201', '1020*97'])
        ->and($store->subscribers)->toHaveKey("201@{$tenant->sip_domain}")
        ->and($store->domains)->toHaveKey('1020');

    expect(reconcile($node)->status)->toBe(ReconcileStatus::Clean);
});

it('writes a changed number again', function () {
    [$store, , $node] = reconciledTenant();
    $store->extens['1020201'][0]['appdata'] = 'PJSIP/somewhere-else';

    $run = reconcile($node);

    expect($run->drift)->toBe([['kind' => 'changed', 'target' => 'number:1020201', 'item' => 'exten 1020201']])
        ->and($run->fixed_count)->toBe(1)
        ->and($store->extens['1020201'][0]['appdata'])->not->toBe('PJSIP/somewhere-else');
});

it('counts an extra exten under the tenant\'s calling prefixes as a change of its calling dialplan', function () {
    [$store, , $node] = reconciledTenant();
    $store->extens['_10208123.'] = [['priority' => 1, 'app' => 'Goto', 'appdata' => 'somewhere,1']];

    $run = reconcile($node);

    expect($run->drift)->toBe([['kind' => 'changed', 'target' => 'calling:1020', 'item' => 'exten _10208123. (not wanted)']])
        ->and($store->extens)->not->toHaveKey('_10208123.');
});

it('only reports rows nobody asked for', function () {
    [$store, $tenant, $node] = reconciledTenant();
    $store->extens['0999201'] = [['priority' => 1, 'app' => 'NoOp', 'appdata' => 'lab']];
    $store->subscribers["test@{$tenant->sip_domain}"] = ['username' => 'test', 'domain' => $tenant->sip_domain, 'ha1' => 'x', 'ha1_sha256' => 'x'];
    $store->domains['0999'] = ['domain' => 'lab.example.com', 'attrs' => '0999'];

    $run = reconcile($node);

    expect($run->status)->toBe(ReconcileStatus::Drift)
        ->and($run->unexpected_count)->toBe(3)
        ->and($run->fixed_count)->toBe(0)
        ->and(collect($run->drift)->pluck('item')->all())->toBe([
            "subscriber test@{$tenant->sip_domain}",
            'domain lab.example.com (attrs 0999)',
            'exten 0999201',
        ])
        ->and($store->extens)->toHaveKey('0999201');
});

it('leaves a target alone while a revision of it is on its way', function () {
    [$store, $tenant, $node] = reconciledTenant();
    $extension = Extension::withoutTenantScope()->sole();
    $this->patch(route('tenant.extensions.update', [$tenant, $extension]), ['display_name' => 'Front desk', 'status' => 'disabled', 'version' => $extension->version])
        ->assertSessionHasNoErrors();
    $store->extens['1020201'][0]['appdata'] = 'PJSIP/somewhere-else';

    $run = app(ReconcileNode::class)->handle($node, ReconcileRun::TRIGGER_MANUAL);

    expect($run->status)->toBe(ReconcileStatus::Clean)
        ->and($run->targets_checked)->toBe(2);
});

it('reports without writing when asked to', function () {
    [$store, , $node] = reconciledTenant();
    unset($store->extens['1020201']);

    $this->artisan('nodes:reconcile', ['--report-only' => true])->assertSuccessful();

    expect(ReconcileRun::query()->latest('id')->first())
        ->status->toBe(ReconcileStatus::Drift)
        ->missing_count->toBe(1)
        ->fixed_count->toBe(0)
        ->and($store->extens)->not->toHaveKey('1020201');
});

it('does not reconcile a node it does not write to', function () {
    $node = PbxNode::factory()->removed()->create();

    $run = app(ReconcileNode::class)->handle($node, ReconcileRun::TRIGGER_MANUAL);

    expect($run->status)->toBe(ReconcileStatus::Failed)
        ->and($run->error)->toContain('removed');

    $this->artisan('nodes:reconcile')->assertSuccessful();
    expect(ReconcileRun::query()->count())->toBe(1);
});

it('lets the platform admin reconcile a node and shows the latest run', function () {
    [, , $node] = reconciledTenant();
    reconcile($node);

    $this->post(route('admin.nodes.reconcile', $node))->assertForbidden();

    signIn(User::factory()->platformAdmin()->create());
    Queue::fake();

    $this->post(route('admin.nodes.reconcile', $node))->assertRedirect(route('admin.nodes.index'));
    Queue::assertPushed(ReconcilePbxNode::class, fn (ReconcilePbxNode $job): bool => $job->nodeId === $node->id && $job->trigger === ReconcileRun::TRIGGER_MANUAL);

    $this->get(route('admin.nodes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('nodes.0.can_reconcile', true)
            ->where('nodes.0.reconcile.status', 'clean')
            ->where('nodes.0.reconcile.targets_checked', 3));
});
