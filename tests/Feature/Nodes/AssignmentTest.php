<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    signIn(User::factory()->platformAdmin()->create());
});

it('places a tenant on a node with a two-digit prefix', function () {
    $node = PbxNode::factory()->create();
    $tenant = Tenant::factory()->create();

    $this->post(route('admin.nodes.assignments.store', $node), ['tenant_id' => $tenant->id, 'prefix' => '20'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($tenant->pbxAssignment)
        ->pbx_node_id->toBe($node->id)
        ->status->toBe(AssignmentStatus::Active)
        ->and($tenant->numberRange)
        ->pbx_node_id->toBe($node->id)
        ->prefix->toBe('20')
        ->label()->toBe('20000–20999');

    $entry = AuditLog::query()->where('action', 'pbx.assigned')->sole();
    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->summary)->toBe(['node' => $node->name, 'prefix' => '20', 'numbers' => '20000–20999']);
});

it('accepts prefixes 20 to 89 only', function (string $prefix) {
    $this->post(route('admin.nodes.assignments.store', PbxNode::factory()->create()), ['tenant_id' => Tenant::factory()->create()->id, 'prefix' => $prefix])
        ->assertSessionHasErrors(['prefix' => 'Use a two-digit prefix from 20 to 89.']);
})->with(['00', '10', '19', '90', '99', '2', '200', '2a', '*2']);

it('gives each tenant on a node its own prefix', function () {
    $existing = TenantPbxAssignment::factory()->prefix('20')->create();

    $this->post(route('admin.nodes.assignments.store', $existing->pbx_node_id), ['tenant_id' => Tenant::factory()->create()->id, 'prefix' => '20'])
        ->assertSessionHasErrors(['prefix' => "Prefix 20 belongs to {$existing->tenant->name} on this node."]);

    $this->post(route('admin.nodes.assignments.store', $existing->pbx_node_id), ['tenant_id' => Tenant::factory()->create()->id, 'prefix' => '21'])
        ->assertSessionHasNoErrors();
    $this->post(route('admin.nodes.assignments.store', PbxNode::factory()->create()), ['tenant_id' => Tenant::factory()->create()->id, 'prefix' => '20'])
        ->assertSessionHasNoErrors();

    expect(TenantNumberRange::query()->where('prefix', '20')->count())->toBe(2);
});

it('gives a tenant one node only', function () {
    $existing = TenantPbxAssignment::factory()->create();

    $this->post(route('admin.nodes.assignments.store', PbxNode::factory()->create()), ['tenant_id' => $existing->tenant_id, 'prefix' => '30'])
        ->assertSessionHasErrors(['tenant_id' => 'This tenant already has a node.']);
});

it('refuses a node the deployer removed', function () {
    $this->post(route('admin.nodes.assignments.store', PbxNode::factory()->removed()->create()), ['tenant_id' => Tenant::factory()->create()->id, 'prefix' => '20'])
        ->assertSessionHasErrors('pbx_node_id');
});

it('changes the prefix only while the tenant has no extensions', function () {
    $assignment = TenantPbxAssignment::factory()->prefix('20')->create();

    $this->patch(route('admin.assignments.update', $assignment), ['prefix' => '25', 'status' => 'active'])->assertSessionHasNoErrors();
    expect($assignment->numberRange()->value('prefix'))->toBe('25');

    Extension::factory()->on($assignment)->create(['extension_number' => '25001']);

    $this->patch(route('admin.assignments.update', $assignment), ['prefix' => '26', 'status' => 'active'])
        ->assertSessionHasErrors(['prefix' => 'The prefix cannot change while the tenant has extensions.']);
    $this->patch(route('admin.assignments.update', $assignment), ['prefix' => '25', 'status' => 'suspended'])
        ->assertSessionHasNoErrors();

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Suspended)
        ->and($assignment->numberRange()->value('prefix'))->toBe('25');
});

it('takes a tenant off its node only without extensions', function () {
    $assignment = TenantPbxAssignment::factory()->create();
    $extension = Extension::factory()->on($assignment)->create();

    $this->delete(route('admin.assignments.destroy', $assignment))->assertSessionHasErrors('assignment');

    $extension->deleteQuietly();

    $this->delete(route('admin.assignments.destroy', $assignment))->assertSessionHasNoErrors();

    expect(TenantPbxAssignment::query()->count())->toBe(0)
        ->and(TenantNumberRange::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'pbx.unassigned')->value('tenant_id'))->toBe($assignment->tenant_id);
});

it('shows the tenants of each node, the next free prefix and the tenants without a node', function () {
    $assignment = TenantPbxAssignment::factory()->prefix('20')->create();
    Extension::factory()->on($assignment)->count(2)->create();
    $waiting = Tenant::factory()->create();

    $this->get(route('admin.nodes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nodes.0.assignments.0.tenant.name', $assignment->tenant->name)
            ->where('nodes.0.assignments.0.prefix', '20')
            ->where('nodes.0.assignments.0.numbers', '20000–20999')
            ->where('nodes.0.assignments.0.extensions_count', 2)
            ->where('nodes.0.next_prefix', '21')
            ->where('unassignedTenants', [['value' => $waiting->id, 'label' => $waiting->name]]));
});

it('keeps other users away from assignments', function () {
    signIn();
    $assignment = TenantPbxAssignment::factory()->create();

    $this->post(route('admin.nodes.assignments.store', $assignment->pbx_node_id), [])->assertForbidden();
    $this->patch(route('admin.assignments.update', $assignment), [])->assertForbidden();
    $this->delete(route('admin.assignments.destroy', $assignment))->assertForbidden();
});
