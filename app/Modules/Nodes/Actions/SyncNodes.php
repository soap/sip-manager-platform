<?php

namespace App\Modules\Nodes\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Nodes\Exceptions\SipDeployerUnavailable;
use App\Modules\Nodes\Models\SipNode;
use App\Modules\Nodes\SipDeployer;
use Illuminate\Support\Facades\Log;

/**
 * Copies the node manifests from SIP Deployer. Passwords are fetched only when the deployer's
 * credentials_version differs from the one held, so they are read once per change rather than on
 * every sync. A node the deployer no longer lists is marked removed, never deleted, so its history
 * stays.
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
                Log::warning('Skipped a SIP Deployer node with an unsupported manifest.', ['deployer_id' => $deployerId, 'manifest_version' => $manifest['manifest_version'] ?? null]);
                $result['skipped'][] = $deployerId;

                continue;
            }

            $listed[] = $deployerId;
            $node = $this->store($deployerId, $manifest);
            $result['synced']++;

            if ($node->wasRecentlyCreated) {
                $result['added']++;
                $this->audit->record('node.added', $node, ['name' => $node->name, 'deployer_id' => $deployerId]);
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

        SipNode::query()->listed()->whereNotIn('deployer_id', $listed)->get()->each(function (SipNode $node) use (&$result): void {
            $node->update(['removed_at' => now()]);
            $result['removed']++;
            $this->audit->record('node.removed', $node, ['name' => $node->name, 'deployer_id' => $node->deployer_id]);
        });

        return $result;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function store(int $deployerId, array $manifest): SipNode
    {
        return SipNode::query()->updateOrCreate(['deployer_id' => $deployerId], [
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
     * Whether new credentials were stored.
     */
    private function refreshCredentials(SipNode $node, mixed $version): bool
    {
        if ($version === null) {
            if ($node->credentials !== null) {
                $node->update(['credentials' => null, 'credentials_version' => null]);
            }

            return false;
        }

        if ($version === $node->credentials_version && $node->credentials !== null) {
            return false;
        }

        $fetched = $this->deployer->credentials($node->deployer_id);
        $credentials = array_filter(
            $fetched,
            fn (mixed $credential): bool => is_array($credential) && isset($credential['username'], $credential['password']),
        );

        $node->update([
            'credentials' => $credentials,
            'credentials_version' => is_string($fetched['credentials_version'] ?? null) ? $fetched['credentials_version'] : (string) $version,
        ]);

        $this->audit->record('node.credentials_updated', $node, ['name' => $node->name, 'endpoints' => array_keys($credentials)]);

        return true;
    }
}
