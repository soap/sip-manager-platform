<?php

namespace App\Modules\Nodes\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Nodes\Actions\CheckNodeConnections;
use App\Modules\Nodes\Actions\SyncNodes;
use App\Modules\Nodes\Exceptions\SipDeployerUnavailable;
use App\Modules\Nodes\Models\SipNode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NodeController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', SipNode::class);

        return Inertia::render('admin/nodes/Index', [
            'deployerConfigured' => filled(config('services.sip_deployer.url')) && filled(config('services.sip_deployer.token')),
            'nodes' => SipNode::query()
                ->orderByRaw('removed_at is not null')
                ->orderBy('name')
                ->get()
                ->map(fn (SipNode $node): array => [
                    'id' => $node->id,
                    'deployer_id' => $node->deployer_id,
                    'name' => $node->name,
                    'sip_domain' => $node->sip_domain,
                    'environment' => $node->environment,
                    'status' => $node->status,
                    'region' => $node->region,
                    'management_address' => $node->addresses['management'] ?? null,
                    'opensips' => $node->services['opensips'] ?? null,
                    'asterisk' => $node->services['asterisk'] ?? null,
                    'endpoints' => array_keys($node->endpoints),
                    'credentials' => array_keys($node->credentials ?? []),
                    'connection_checks' => $node->connection_checks,
                    'checked_at' => $node->checked_at?->toIso8601String(),
                    'synced_at' => $node->synced_at?->toIso8601String(),
                    'removed_at' => $node->removed_at?->toIso8601String(),
                ]),
        ]);
    }

    public function sync(SyncNodes $syncNodes): RedirectResponse
    {
        Gate::authorize('sync', SipNode::class);

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

    public function check(SipNode $node, CheckNodeConnections $checkConnections): RedirectResponse
    {
        Gate::authorize('check', $node);

        $checks = $checkConnections->handle($node);
        $failed = array_keys(array_filter($checks, fn (array $check): bool => ! $check['ok']));

        Inertia::flash('toast', $failed === []
            ? ['type' => 'success', 'message' => "Every endpoint of {$node->name} answered."]
            : ['type' => 'error', 'message' => "{$node->name}: ".implode(', ', $failed).' did not answer.']);

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
