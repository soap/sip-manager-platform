<?php

namespace App\Modules\Infrastructure\OpenSips;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;

/**
 * Rows of the OpenSIPS database on a node that this application owns: one subscriber per SIP
 * username. OpenSIPS reads subscriber on every authentication (auth_db), so a write needs no reload.
 */
class OpenSipsDbAdapter
{
    public function __construct(private NodeConnections $connections) {}

    /**
     * Makes the username's subscriber exactly the given row, or removes it for null. Rows of the
     * username under another domain are removed too, so a new SIP domain leaves nothing behind.
     *
     * @param  array{username: string, domain: string, ha1: string, ha1_sha256: string}|null  $subscriber
     */
    public function replaceSubscriber(PbxNode $node, string $username, ?array $subscriber): void
    {
        $database = $this->connections->openSipsDatabase($node);

        $database->transaction(function () use ($database, $username, $subscriber): void {
            $database->table('subscriber')->where('username', $username)->delete();

            if ($subscriber !== null) {
                $database->table('subscriber')->insert($subscriber);
            }
        });
    }

    /**
     * @return list<array{username: string, domain: string, ha1: string, ha1_sha256: string}>
     */
    public function subscribers(PbxNode $node, string $username): array
    {
        /** @var list<array{username: string, domain: string, ha1: string, ha1_sha256: string}> */
        return $this->connections->openSipsDatabase($node)
            ->table('subscriber')
            ->where('username', $username)
            ->orderBy('domain')
            ->get(['username', 'domain', 'ha1', 'ha1_sha256'])
            ->map(fn (object $row): array => array_map(fn (mixed $value): string => trim((string) $value), (array) $row))
            ->all();
    }
}
