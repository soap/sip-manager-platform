<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Models\SipNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    signIn(User::factory()->platformAdmin()->create());
});

it('places a tenant on a node with a block of numbers', function () {
    $node = SipNode::factory()->create();
    $tenant = Tenant::factory()->create();

    $this->post(route('admin.nodes.assignments.store', $node), ['tenant_id' => $tenant->id, 'number_range_start' => 1000, 'number_range_end' => 1999])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $assignment = $tenant->pbxAssignment;

    expect($assignment->sip_node_id)->toBe($node->id)
        ->and($assignment->rangeLabel())->toBe('1000–1999')
        ->and($assignment->status)->toBe(AssignmentStatus::Active);

    $entry = AuditLog::query()->where('action', 'pbx.assigned')->sole();
    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->summary)->toBe(['node' => $node->name, 'numbers' => '1000–1999']);
});

it('keeps the blocks of one node apart', function (int $start, int $end) {
    $existing = TenantPbxAssignment::factory()->numbers(1000, 1999)->create();

    $this->post(route('admin.nodes.assignments.store', $existing->sip_node_id), ['tenant_id' => Tenant::factory()->create()->id, 'number_range_start' => $start, 'number_range_end' => $end])
        ->assertSessionHasErrors(['number_range_start' => "These numbers overlap {$existing->tenant->name} (1000–1999) on this node."]);
})->with([
    'same block' => [1000, 1999],
    'inside' => [1500, 1600],
    'over the start' => [900, 1000],
    'over the end' => [1999, 2999],
    'around' => [100, 9999],
]);

it('hands out the same numbers on another node and next to an existing block', function () {
    $existing = TenantPbxAssignment::factory()->numbers(1000, 1999)->create();

    $this->post(route('admin.nodes.assignments.store', SipNode::factory()->create()), ['tenant_id' => Tenant::factory()->create()->id, 'number_range_start' => 1000, 'number_range_end' => 1999])
        ->assertSessionHasNoErrors();
    $this->post(route('admin.nodes.assignments.store', $existing->sip_node_id), ['tenant_id' => Tenant::factory()->create()->id, 'number_range_start' => 2000, 'number_range_end' => 2999])
        ->assertSessionHasNoErrors();

    expect(TenantPbxAssignment::query()->count())->toBe(3);
});

it('gives a tenant one node only', function () {
    $existing = TenantPbxAssignment::factory()->create();

    $this->post(route('admin.nodes.assignments.store', SipNode::factory()->create()), ['tenant_id' => $existing->tenant_id, 'number_range_start' => 5000, 'number_range_end' => 5999])
        ->assertSessionHasErrors(['tenant_id' => 'This tenant already has a node.']);
});

it('refuses a node the deployer removed', function () {
    $this->post(route('admin.nodes.assignments.store', SipNode::factory()->removed()->create()), ['tenant_id' => Tenant::factory()->create()->id, 'number_range_start' => 1000, 'number_range_end' => 1999])
        ->assertSessionHasErrors('sip_node_id');
});

it('validates the block', function (array $block, string $field) {
    $this->post(route('admin.nodes.assignments.store', SipNode::factory()->create()), ['tenant_id' => Tenant::factory()->create()->id, ...$block])
        ->assertSessionHasErrors($field);
})->with([
    'end below start' => [['number_range_start' => 2000, 'number_range_end' => 1000], 'number_range_end'],
    'one digit' => [['number_range_start' => 1, 'number_range_end' => 9], 'number_range_start'],
    'seven digits' => [['number_range_start' => 1000, 'number_range_end' => 1000000], 'number_range_end'],
]);

it('changes a block as long as it keeps the numbers in use', function () {
    $assignment = TenantPbxAssignment::factory()->numbers(1000, 1999)->create();
    Extension::factory()->on($assignment)->create(['extension_number' => '1500']);

    $this->patch(route('admin.assignments.update', $assignment), ['number_range_start' => 1000, 'number_range_end' => 1099, 'status' => 'active'])
        ->assertSessionHasErrors(['number_range_start' => 'The block must keep the numbers in use: 1500.']);

    $this->patch(route('admin.assignments.update', $assignment), ['number_range_start' => 1500, 'number_range_end' => 2999, 'status' => 'suspended'])
        ->assertSessionHasNoErrors();

    expect($assignment->fresh())
        ->rangeLabel()->toBe('1500–2999')
        ->status->toBe(AssignmentStatus::Suspended);
});

it('takes a tenant off its node only without extensions', function () {
    $assignment = TenantPbxAssignment::factory()->create();
    $extension = Extension::factory()->on($assignment)->create();

    $this->delete(route('admin.assignments.destroy', $assignment))->assertSessionHasErrors('assignment');

    $extension->deleteQuietly();

    $this->delete(route('admin.assignments.destroy', $assignment))->assertSessionHasNoErrors();

    expect(TenantPbxAssignment::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'pbx.unassigned')->value('tenant_id'))->toBe($assignment->tenant_id);
});

it('shows the tenants of each node and those without one', function () {
    $assignment = TenantPbxAssignment::factory()->create();
    Extension::factory()->on($assignment)->count(2)->create();
    $waiting = Tenant::factory()->create();

    $this->get(route('admin.nodes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nodes.0.assignments.0.tenant.name', $assignment->tenant->name)
            ->where('nodes.0.assignments.0.extensions_count', 2)
            ->where('unassignedTenants', [['value' => $waiting->id, 'label' => $waiting->name]]));
});

it('keeps other users away from assignments', function () {
    signIn();
    $assignment = TenantPbxAssignment::factory()->create();

    $this->post(route('admin.nodes.assignments.store', $assignment->sip_node_id), [])->assertForbidden();
    $this->patch(route('admin.assignments.update', $assignment), [])->assertForbidden();
    $this->delete(route('admin.assignments.destroy', $assignment))->assertForbidden();
});
