<?php

namespace App\Modules\Infrastructure\OpenSips;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;
use Carbon\CarbonImmutable;

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
     * The registrations of the usernames that have not expired, newest first, from the location
     * table. OpenSIPS writes it back from memory periodically (sql-write-back), so a fresh
     * registration may take up to a minute to appear; a phone switched off without unregistering
     * stays until its registration expires.
     *
     * @param  list<string>  $usernames
     * @return array<string, list<array{address: string, user_agent: string, registered_at: CarbonImmutable|null, expires_at: CarbonImmutable|null}>>
     */
    public function registrations(PbxNode $node, array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }

        $registrations = [];

        $rows = $this->connections->openSipsDatabase($node)
            ->table('location')
            ->whereIn('username', $usernames)
            // the node's clock decides, so a skewed local clock cannot hide or revive a phone
            ->where(fn ($query) => $query->where('expires', 0)->orWhereRaw('expires > unix_timestamp()'))
            ->orderByDesc('last_modified')
            ->get(['username', 'contact', 'received', 'user_agent', 'expires', 'last_modified']);

        foreach ($rows as $row) {
            $registrations[trim((string) $row->username)][] = [
                'address' => self::address((string) ($row->received ?: $row->contact)),
                'user_agent' => trim((string) $row->user_agent),
                // the node's database runs in UTC
                'registered_at' => $row->last_modified === null ? null : CarbonImmutable::parse((string) $row->last_modified, 'UTC'),
                'expires_at' => (int) $row->expires === 0 ? null : CarbonImmutable::createFromTimestampUTC((int) $row->expires),
            ];
        }

        return $registrations;
    }

    /**
     * host:port of a SIP URI ("sip:20101@203.0.113.7:5060;transport=udp" → "203.0.113.7:5060").
     */
    public static function address(string $uri): string
    {
        return preg_match('/^sips?:(?:[^@;>]*@)?(\[[^\]]+\]|[^:;>]+)(?::(\d+))?/i', trim($uri), $matches) === 1
            ? $matches[1].(isset($matches[2]) ? ':'.$matches[2] : '')
            : $uri;
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
