<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    signIn(User::factory()->platformAdmin()->create());
});

it('places a tenant on a node, which starts serving its SIP domain', function () {
    $store = fakeNode();
    $node = PbxNode::factory()->create();
    $tenant = Tenant::factory()->create(['code' => '1042', 'sip_domain' => 'acme.sip.example.com']);

    $this->post(route('admin.nodes.assignments.store', $node), ['tenant_id' => $tenant->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($tenant->pbxAssignment)
        ->pbx_node_id->toBe($node->id)
        ->status->toBe(AssignmentStatus::Active)
        ->and(ConfigRevision::query()->sole()->target)->toBe('domain:1042');

    applyPendingRevisions();

    expect($store->domains)->toBe(['1042' => ['domain' => 'acme.sip.example.com', 'attrs' => '1042']]);

    $entry = AuditLog::query()->where('action', 'pbx.assigned')->sole();
    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->summary)->toBe(['node' => $node->name, 'code' => '1042', 'sip_domain' => 'acme.sip.example.com']);
});

it('places many tenants on one node', function () {
    $existing = TenantPbxAssignment::factory()->code('1020')->create();

    $this->post(route('admin.nodes.assignments.store', $existing->pbx_node_id), ['tenant_id' => Tenant::factory()->create()->id])
        ->assertSessionHasNoErrors();

    expect(TenantPbxAssignment::query()->where('pbx_node_id', $existing->pbx_node_id)->count())->toBe(2);
});

it('gives a tenant one node only', function () {
    $existing = TenantPbxAssignment::factory()->create();

    $this->post(route('admin.nodes.assignments.store', PbxNode::factory()->create()), ['tenant_id' => $existing->tenant_id])
        ->assertSessionHasErrors(['tenant_id' => 'This tenant already has a node.']);
});

it('refuses a node the deployer removed', function () {
    $this->post(route('admin.nodes.assignments.store', PbxNode::factory()->removed()->create()), ['tenant_id' => Tenant::factory()->create()->id])
        ->assertSessionHasErrors('pbx_node_id');
});

it('refuses a node that still serves one shared SIP domain', function () {
    $node = PbxNode::factory()->create(['dialplan' => ['entry_context' => 'from-opensips', 'managed_context' => 'managed', 'opensips_endpoint' => 'opensips']]);

    $this->post(route('admin.nodes.assignments.store', $node), ['tenant_id' => Tenant::factory()->create()->id])
        ->assertSessionHasErrors(['pbx_node_id' => 'This node serves one shared SIP domain. Reinstall OpenSIPS and Asterisk from SIP Deployer, then sync.']);

    expect(TenantPbxAssignment::query()->count())->toBe(0);
});

it('suspends and resumes the tenant on its node', function () {
    $assignment = TenantPbxAssignment::factory()->code('1020')->create();
    Extension::factory()->on($assignment)->create(['extension_number' => '201']);

    $this->patch(route('admin.assignments.update', $assignment), ['status' => 'suspended'])->assertSessionHasNoErrors();

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Suspended)
        ->and(ConfigRevision::query()->pluck('target')->all())->toBe(['number:1020201']);
});

it('takes a tenant off its node only without extensions, and the node stops serving its domain', function () {
    $store = fakeNode();
    $assignment = TenantPbxAssignment::factory()->code('1020')->create();
    $store->domains['1020'] = ['domain' => $assignment->tenant->sip_domain, 'attrs' => '1020'];
    $extension = Extension::factory()->on($assignment)->create();

    $this->delete(route('admin.assignments.destroy', $assignment))->assertSessionHasErrors('assignment');

    DialNumber::withoutTenantScope()->delete();
    $extension->deleteQuietly();

    $this->delete(route('admin.assignments.destroy', $assignment))->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect(TenantPbxAssignment::query()->count())->toBe(0)
        ->and($store->domains)->toBe([])
        ->and(AuditLog::query()->where('action', 'pbx.unassigned')->value('tenant_id'))->toBe($assignment->tenant_id);
});

it('shows the tenants of each node with their code and domain, and the tenants without a node', function () {
    $assignment = TenantPbxAssignment::factory()->code('1020')->create();
    Extension::factory()->on($assignment)->count(2)->create();
    $waiting = Tenant::factory()->create();

    $this->get(route('admin.nodes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nodes.0.tenant_routing', true)
            ->where('nodes.0.assignments.0.tenant.name', $assignment->tenant->name)
            ->where('nodes.0.assignments.0.code', '1020')
            ->where('nodes.0.assignments.0.sip_domain', $assignment->tenant->sip_domain)
            ->where('nodes.0.assignments.0.extensions_count', 2)
            ->where('unassignedTenants', [['value' => $waiting->id, 'label' => $waiting->name]]));
});

it('keeps other users away from assignments', function () {
    signIn();
    $assignment = TenantPbxAssignment::factory()->create();

    $this->post(route('admin.nodes.assignments.store', $assignment->pbx_node_id), [])->assertForbidden();
    $this->patch(route('admin.assignments.update', $assignment), [])->assertForbidden();
    $this->delete(route('admin.assignments.destroy', $assignment))->assertForbidden();
});
