<?php

namespace App\Modules\Nodes;

use App\Modules\Nodes\Exceptions\NodeNotConnectable;
use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;

/**
 * Opens this application's connections to a node from its synced manifest and credentials:
 * the OpenSIPS and Asterisk realtime databases (MariaDB), ARI and AMI.
 */
class NodeConnections
{
    /** Seconds to wait for a node before giving up. */
    public const TIMEOUT = 5;

    /**
     * The OpenSIPS database: subscribers, domains, dispatcher and dialplan tables.
     */
    public function openSipsDatabase(PbxNode $node): Connection
    {
        return $this->database($node, 'opensips_db');
    }

    /**
     * The Asterisk realtime database: PJSIP endpoints, extensions, voicemail, queues and CDR.
     */
    public function asteriskDatabase(PbxNode $node): Connection
    {
        return $this->database($node, 'asterisk_db');
    }

    /**
     * An HTTP client for the node's ARI, with the base URL ending in /ari/.
     */
    public function ari(PbxNode $node): PendingRequest
    {
        $endpoint = $this->endpoint($node, 'ari');

        return Http::baseUrl(sprintf('%s://%s:%d/ari/', $endpoint['scheme'] ?? 'http', $endpoint['host'], $endpoint['port']))
            ->withBasicAuth($endpoint['username'], $endpoint['password'])
            ->acceptJson()
            ->connectTimeout(self::TIMEOUT)
            ->timeout(self::TIMEOUT * 2);
    }

    /**
     * Where and as whom to log in to the node's AMI.
     *
     * @return array{host: string, port: int, username: string, password: string}
     */
    public function ami(PbxNode $node): array
    {
        $endpoint = $this->endpoint($node, 'ami');

        return [
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'username' => $endpoint['username'],
            'password' => $endpoint['password'],
        ];
    }

    /**
     * Endpoints of the node that it has and holds credentials for.
     *
     * @return list<string>
     */
    public function available(PbxNode $node): array
    {
        return array_values(array_filter(
            array_keys($node->endpoints),
            fn (string $name): bool => isset($node->credentials()[$name]),
        ));
    }

    /**
     * A named connection per node and database, reused within the process and rebuilt when the
     * manifest or the credentials change.
     */
    private function database(PbxNode $node, string $name): Connection
    {
        $endpoint = $this->endpoint($node, $name);

        if (! isset($endpoint['database'])) {
            throw NodeNotConnectable::missingEndpoint($node, $name);
        }

        $connection = "pbx_node_{$node->id}_{$name}";

        $config = [
            'driver' => 'mariadb',
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'database' => $endpoint['database'],
            'username' => $endpoint['username'],
            'password' => $endpoint['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [PDO::ATTR_TIMEOUT => self::TIMEOUT],
        ];

        if (config("database.connections.{$connection}") !== $config) {
            config(["database.connections.{$connection}" => $config]);
            DB::purge($connection);
        }

        return DB::connection($connection);
    }

    /**
     * The endpoint from the manifest with its username and password from the credentials.
     *
     * @return array{host: string, port: int, username: string, password: string, database?: string, scheme?: string}
     */
    private function endpoint(PbxNode $node, string $name): array
    {
        $endpoint = $node->endpoints[$name] ?? null;

        if (! is_array($endpoint) || ! isset($endpoint['host'], $endpoint['port'])) {
            throw NodeNotConnectable::missingEndpoint($node, $name);
        }

        $credential = $node->credentials()[$name] ?? null;

        if ($credential === null) {
            throw NodeNotConnectable::missingCredentials($node, $name);
        }

        /** @var array{host: string, port: int, username: string, password: string, database?: string, scheme?: string} */
        return [...$endpoint, 'port' => (int) $endpoint['port'], 'username' => $credential['username'], 'password' => $credential['password']];
    }
}
