<?php

namespace App\Modules\Nodes\Actions;

use App\Modules\Nodes\AmiLogin;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Tries every endpoint of a node, the way this application will use it, and stores the outcome
 * on the node. A failure is recorded, never thrown.
 */
class CheckNodeConnections
{
    public function __construct(private NodeConnections $connections, private AmiLogin $amiLogin) {}

    /**
     * @return array<string, array{ok: bool, message: string}>
     */
    public function handle(PbxNode $node): array
    {
        $checks = [];

        foreach (array_keys($node->endpoints) as $endpoint) {
            $checks[$endpoint] = $this->check(fn (): string => match ($endpoint) {
                'opensips_db' => $this->database($this->connections->openSipsDatabase($node)->select('select version() as version')),
                'asterisk_db' => $this->database($this->connections->asteriskDatabase($node)->select('select version() as version')),
                'ari' => 'Asterisk '.($this->connections->ari($node)->get('asterisk/info')->throw()->json('system.version') ?? 'answered'),
                'ami' => $this->ami($node),
                default => throw new RuntimeException('This application does not use this endpoint.'),
            });
        }

        $node->update(['connection_checks' => $checks, 'checked_at' => now()]);

        return $checks;
    }

    /**
     * @param  callable(): string  $probe
     * @return array{ok: bool, message: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'message' => $probe()];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => Str::limit($exception->getMessage(), 300)];
        }
    }

    /**
     * @param  array<int, object>  $rows
     */
    private function database(array $rows): string
    {
        return 'MariaDB '.($rows[0]->version ?? 'answered');
    }

    private function ami(PbxNode $node): string
    {
        $this->amiLogin->attempt($this->connections->ami($node));

        return 'Logged in';
    }
}
