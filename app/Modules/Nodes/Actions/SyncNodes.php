<?php

namespace App\Modules\Nodes\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Enums\NodeSecretKind;
use App\Modules\Nodes\Exceptions\SipDeployerUnavailable;
use App\Modules\Nodes\Models\NodeSecret;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\SipDeployer;
use App\Modules\Provisioning\Jobs\ReconcilePbxNode;
use App\Modules\Provisioning\Models\ReconcileRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies the node manifests from SIP Deployer. Passwords are fetched only when the deployer's
 * credentials_version differs from the one held, so they are read once per change rather than on
 * every sync. A node the deployer no longer lists is marked removed, never deleted, so its history
 * stays. A node whose OpenSIPS or Asterisk changed release or version is reconciled.
 */
class SyncNodes
{
    /** Manifest versions this application understands. */
    public const SUPPORTED_MANIFEST_VERSIONS = [1];

    public function __construct(private SipDeployer $deployer, private Audit $audit) {}

    /**
     * @return array{synced: int, added: int, removed: int, credentials_updated: int, skipped: list<int>, credential_errors: array<int, string>}
     *
     * @throws SipDeployerUnavailable when the node list cannot be fetched
     */
    public function handle(): array
    {
        $result = ['synced' => 0, 'added' => 0, 'removed' => 0, 'credentials_updated' => 0, 'skipped' => [], 'credential_errors' => []];
        $listed = [];

        foreach ($this->deployer->nodes() as $manifest) {
            $deployerId = (int) ($manifest['id'] ?? 0);

            if ($deployerId === 0 || ! in_array($manifest['manifest_version'] ?? null, self::SUPPORTED_MANIFEST_VERSIONS, true)) {
                Log::warning('Skipped a SIP Deployer node with an unsupported manifest.', ['deployer_server_id' => $deployerId, 'manifest_version' => $manifest['manifest_version'] ?? null]);
                $result['skipped'][] = $deployerId;

                continue;
            }

            $listed[] = $deployerId;
            $previousServices = PbxNode::query()->where('deployer_server_id', $deployerId)->value('services');
            $node = $this->store($deployerId, $manifest);
            $result['synced']++;

            // a reinstall or upgrade may have left the node's databases without our rows
            if (! $node->wasRecentlyCreated && self::serviceVersionsDiffer($previousServices ?? [], $node->services ?? [])) {
                ReconcilePbxNode::dispatch($node->id, ReconcileRun::TRIGGER_SYNC);
            }

            if ($node->wasRecentlyCreated) {
                $result['added']++;
                $this->audit->record('node.added', $node, ['name' => $node->name, 'deployer_server_id' => $deployerId]);
            }

            try {
                if ($this->refreshCredentials($node, $manifest['credentials_version'] ?? null)) {
                    $result['credentials_updated']++;
                }
            } catch (SipDeployerUnavailable $exception) {
                // the old credentials and version are kept, so the next sync tries again
                $result['credential_errors'][$deployerId] = $exception->getMessage();
            }
        }

        PbxNode::query()->listed()->whereNotIn('deployer_server_id', $listed)->get()->each(function (PbxNode $node) use (&$result): void {
            $node->update(['removed_at' => now()]);
            $result['removed']++;
            $this->audit->record('node.removed', $node, ['name' => $node->name, 'deployer_server_id' => $node->deployer_server_id]);
        });

        return $result;
    }

    /**
     * Whether OpenSIPS or Asterisk changed release or version between two manifests.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function serviceVersionsDiffer(array $before, array $after): bool
    {
        foreach (['opensips', 'asterisk'] as $service) {
            foreach (['release', 'version'] as $key) {
                if (($before[$service][$key] ?? null) !== ($after[$service][$key] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function store(int $deployerId, array $manifest): PbxNode
    {
        return PbxNode::query()->updateOrCreate(['deployer_server_id' => $deployerId], [
            'manifest_version' => $manifest['manifest_version'],
            'name' => $manifest['name'] ?? "node-{$deployerId}",
            'hostname' => $manifest['hostname'] ?? null,
            'sip_domain' => $manifest['sip_domain'] ?? null,
            'environment' => $manifest['environment'] ?? 'lab',
            'status' => $manifest['status'] ?? 'unknown',
            'region' => $manifest['region'] ?? null,
            'addresses' => $manifest['addresses'] ?? [],
            'services' => $manifest['services'] ?? [],
            'endpoints' => $manifest['endpoints'] ?? [],
            'dialplan' => $manifest['dialplan'] ?? null,
            'synced_at' => now(),
            'removed_at' => null,
        ]);
    }

    /**
     * Whether new secrets were stored. A secret whose password changed is marked rotated; an
     * endpoint the node no longer has loses its secret.
     */
    private function refreshCredentials(PbxNode $node, mixed $version): bool
    {
        if ($version === null) {
            if ($node->secrets()->exists() || $node->credentials_version !== null) {
                DB::transaction(function () use ($node): void {
                    $node->secrets()->delete();
                    $node->update(['credentials_version' => null]);
                });
            }

            return false;
        }

        if ($version === $node->credentials_version && $node->secrets()->exists()) {
            return false;
        }

        $fetched = $this->deployer->credentials($node->deployer_server_id);
        $credentials = collect(NodeSecretKind::cases())
            ->mapWithKeys(fn (NodeSecretKind $kind): array => [$kind->value => $fetched[$kind->value] ?? null])
            ->filter(fn (mixed $credential): bool => is_array($credential) && is_string($credential['username'] ?? null) && is_string($credential['password'] ?? null));

        DB::transaction(function () use ($node, $credentials, $fetched, $version): void {
            $existing = $node->secrets()->get()->keyBy(fn (NodeSecret $secret): string => $secret->kind->value);

            foreach ($credentials as $kind => $credential) {
                $current = $existing->get($kind);

                $node->secrets()->updateOrCreate(['kind' => $kind], [
                    'username' => $credential['username'],
                    'secret' => $credential['password'],
                    'fetched_at' => now(),
                    'rotated_at' => $current !== null && $current->secret !== $credential['password'] ? now() : $current?->rotated_at,
                ]);
            }

            $node->secrets()->whereNotIn('kind', $credentials->keys()->all())->delete();
            $node->update([
                'credentials_version' => is_string($fetched['credentials_version'] ?? null) ? $fetched['credentials_version'] : (string) $version,
            ]);
        });

        $node->unsetRelation('secrets');

        $this->audit->record('node.credentials_updated', $node, ['name' => $node->name, 'endpoints' => $credentials->keys()->all()]);

        return true;
    }
}
