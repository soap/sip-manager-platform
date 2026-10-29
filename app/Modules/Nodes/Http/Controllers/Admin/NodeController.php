<?php

namespace App\Modules\Nodes\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Nodes\Actions\CheckNodeConnections;
use App\Modules\Nodes\Actions\SyncNodes;
use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Exceptions\SipDeployerUnavailable;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Actions\ReconcileNode;
use App\Modules\Provisioning\Jobs\ReconcilePbxNode;
use App\Modules\Provisioning\Models\ReconcileRun;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NodeController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', PbxNode::class);

        return Inertia::render('admin/nodes/Index', [
            'deployerConfigured' => filled(config('services.sip_deployer.url')) && filled(config('services.sip_deployer.token')),
            'unassignedTenants' => Tenant::query()
                ->whereDoesntHave('pbxAssignment')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Tenant $tenant): array => ['value' => $tenant->id, 'label' => $tenant->name]),
            'assignmentStatuses' => AssignmentStatus::options(),
            'nodes' => PbxNode::query()
                ->with(['secrets', 'latestReconcileRun', 'assignments' => fn ($query) => $query->with(['tenant:id,name,slug,code,sip_domain'])->withCount('extensions')])
                ->orderByRaw('removed_at is not null')
                ->orderBy('name')
                ->get()
                ->map(fn (PbxNode $node): array => [
                    'id' => $node->id,
                    'deployer_server_id' => $node->deployer_server_id,
                    'name' => $node->name,
                    'sip_domain' => $node->sip_domain,
                    'environment' => $node->environment,
                    'status' => $node->status,
                    'region' => $node->region,
                    'management_address' => $node->addresses['management'] ?? null,
                    'opensips' => $node->services['opensips'] ?? null,
                    'asterisk' => $node->services['asterisk'] ?? null,
                    'endpoints' => array_keys($node->endpoints),
                    'credentials' => array_keys($node->credentials()),
                    'connection_checks' => $node->connection_checks,
                    'checked_at' => $node->checked_at?->toIso8601String(),
                    'synced_at' => $node->synced_at?->toIso8601String(),
                    'removed_at' => $node->removed_at?->toIso8601String(),
                    'tenant_routing' => $node->hasTenantDomains(),
                    'can_reconcile' => ReconcileNode::canReconcile($node),
                    'reconcile' => $node->latestReconcileRun === null ? null : [
                        'status' => $node->latestReconcileRun->status->value,
                        'trigger' => $node->latestReconcileRun->trigger,
                        'targets_checked' => $node->latestReconcileRun->targets_checked,
                        'missing_count' => $node->latestReconcileRun->missing_count,
                        'changed_count' => $node->latestReconcileRun->changed_count,
                        'unexpected_count' => $node->latestReconcileRun->unexpected_count,
                        'fixed_count' => $node->latestReconcileRun->fixed_count,
                        'drift' => $node->latestReconcileRun->drift ?? [],
                        'error' => $node->latestReconcileRun->error,
                        'started_at' => $node->latestReconcileRun->started_at->toIso8601String(),
                        'finished_at' => $node->latestReconcileRun->finished_at?->toIso8601String(),
                    ],
                    'assignments' => $node->assignments
                        ->sortBy(fn (TenantPbxAssignment $assignment): string => $assignment->tenant->code)
                        ->values()
                        ->map(fn (TenantPbxAssignment $assignment): array => [
                            'id' => $assignment->id,
                            'tenant' => ['name' => $assignment->tenant->name, 'slug' => $assignment->tenant->slug],
                            'code' => $assignment->tenant->code,
                            'sip_domain' => $assignment->tenant->sip_domain,
                            'status' => $assignment->status->value,
                            'extensions_count' => $assignment->extensions_count,
                        ]),
                ]),
        ]);
    }

    public function sync(SyncNodes $syncNodes): RedirectResponse
    {
        Gate::authorize('sync', PbxNode::class);

        try {
            $result = $syncNodes->handle();
        } catch (SipDeployerUnavailable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return redirect()->route('admin.nodes.index');
        }

        $message = "Synced {$result['synced']} nodes: {$result['added']} new, {$result['removed']} removed, {$result['credentials_updated']} with new credentials.";

        if ($result['credential_errors'] !== [] || $result['skipped'] !== []) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $message.' '.$this->problems($result)]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return redirect()->route('admin.nodes.index');
    }

    public function check(PbxNode $node, CheckNodeConnections $checkConnections): RedirectResponse
    {
        Gate::authorize('check', $node);

        $checks = $checkConnections->handle($node);
        $failed = array_keys(array_filter($checks, fn (array $check): bool => ! $check['ok']));

        Inertia::flash('toast', $failed === []
            ? ['type' => 'success', 'message' => "Every endpoint of {$node->name} answered."]
            : ['type' => 'error', 'message' => "{$node->name}: ".implode(', ', $failed).' did not answer.']);

        return redirect()->route('admin.nodes.index');
    }

    public function reconcile(PbxNode $node): RedirectResponse
    {
        Gate::authorize('reconcile', $node);

        ReconcilePbxNode::dispatch($node->id, ReconcileRun::TRIGGER_MANUAL);

        Inertia::flash('toast', ['type' => 'info', 'message' => "Comparing the rows on {$node->name} with what they should be. Drifted ones are written again."]);

        return redirect()->route('admin.nodes.index');
    }

    /**
     * @param  array{skipped: list<int>, credential_errors: array<int, string>}  $result
     */
    private function problems(array $result): string
    {
        $problems = [];

        if ($result['skipped'] !== []) {
            $problems[] = count($result['skipped']).' skipped for an unsupported manifest version';
        }

        if ($result['credential_errors'] !== []) {
            $problems[] = 'credentials could not be fetched for '.count($result['credential_errors']).' ('.collect($result['credential_errors'])->first().')';
        }

        return ucfirst(implode('; ', $problems)).'.';
    }
}
