<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Makes the node refuse the database login, which fails an operation at once.
 */
function refuseNodeLogin(FakeNodeDatabases $node): void
{
    $node->failWith = new QueryException('mariadb', 'delete from extensions', [], new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'asterisk_manager'@'10.0.0.1' (using password: YES)"));
}

it('lists the operations of the tenant only, newest first, and filters them', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    $other = TenantPbxAssignment::factory()->code('1021')->create();
    DB::transaction(fn () => app(RequestProvisioning::class)->forNumber($other->pbx_node_id, $other->tenant_id, '201', OperationType::Reapplied, Extension::MORPH, 1));

    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    applyPendingRevisions();
    refuseNodeLogin($node);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '202', 'display_name' => 'Office']);
    applyPendingRevisions();

    $this->get(route('tenant.operations.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tenants/Operations')
            ->where('filter', 'all')
            ->where('canRetry', true)
            ->has('operations', 2)
            ->where('operations.0.subject', ['kind' => 'number', 'number' => '202'])
            ->where('operations.0.status', 'failed')
            ->where('operations.0.error_code', 'node_auth_failed')
            ->where('operations.0.retryable', true)
            ->where('operations.1.subject.number', '201')
            ->where('operations.1.status', 'applied')
            ->where('operations.1.retryable', false));

    $this->get(route('tenant.operations.index', [$tenant, 'status' => 'failed']))
        ->assertInertia(fn (Assert $page) => $page->where('filter', 'failed')
            ->has('operations', 1)
            ->where('operations.0.subject.number', '202'));
});

it('retries a failed operation by writing its target again', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    refuseNodeLogin($node);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    applyPendingRevisions();
    $failed = ProvisioningOperation::query()->sole();
    $node->failWith = null;

    $this->post(route('tenant.operations.retry', [$tenant, $failed->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();
    applyPendingRevisions();

    $retry = ProvisioningOperation::query()->whereKeyNot($failed->id)->sole();

    expect($retry->type)->toBe(OperationType::Reapplied)
        ->and($retry->status)->toBe(OperationStatus::Applied)
        ->and($retry->revision->target)->toBe('number:1020201')
        ->and($failed->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($node->extens)->toHaveKey('1020201')
        ->and(AuditLog::query()->where('action', 'operation.retried')->sole()->summary)
        ->toMatchArray(['operation' => $failed->id, 'target' => 'number:1020201']);

    // the newer revision carries the change now, so the old failure cannot be retried again
    $this->post(route('tenant.operations.retry', [$tenant, $failed->id]))->assertSessionHasErrors('operation');
});

it('does not retry an operation that did not fail', function () {
    fakeNode();
    [$tenant] = provisioningTenant();
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);

    $this->post(route('tenant.operations.retry', [$tenant, ProvisioningOperation::query()->sole()->id]))
        ->assertSessionHasErrors('operation');

    expect(ProvisioningOperation::query()->count())->toBe(1);
});

it('lets members look but not retry, and hides other tenants\' operations', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    refuseNodeLogin($node);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    applyPendingRevisions();
    $failed = ProvisioningOperation::query()->sole();

    $member = signIn();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $member->id, 'role' => TenantRole::Member]);

    $this->get(route('tenant.operations.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page->where('canRetry', false)->has('operations', 1));
    $this->post(route('tenant.operations.retry', [$tenant, $failed->id]))->assertForbidden();

    [$otherTenant] = provisioningTenant(TenantPbxAssignment::factory()->code('1021')->create());
    $this->post(route('tenant.operations.retry', [$otherTenant, $failed->id]))->assertNotFound();
});

it('shows the platform admin the operations of every tenant and lets them retry', function () {
    $node = fakeNode();
    [$tenant] = provisioningTenant();
    refuseNodeLogin($node);
    $this->post(route('tenant.extensions.store', $tenant), ['extension_number' => '201', 'display_name' => 'Reception']);
    applyPendingRevisions();
    $failed = ProvisioningOperation::query()->sole();

    $this->get(route('admin.operations.index'))->assertForbidden();

    signIn(User::factory()->platformAdmin()->create());
    $node->failWith = null;

    $this->get(route('admin.operations.index', ['status' => 'failed']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/operations/Index')
            ->has('operations', 1)
            ->where('operations.0.tenant.slug', $tenant->slug)
            ->where('operations.0.retryable', true));

    $this->post(route('admin.operations.retry', $failed->id))->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect(ProvisioningOperation::query()->whereKeyNot($failed->id)->sole()->status)->toBe(OperationStatus::Applied)
        ->and(AuditLog::query()->where('action', 'operation.retried')->sole()->tenant_id)->toBe($tenant->id);
});
