<?php

namespace App\Modules\CallRecords\Listener;

use App\Modules\Infrastructure\Asterisk\AmiEventStream;
use App\Modules\Nodes\Models\PbxNode;

/**
 * What CallEventListener keeps about one node: its connection when it has one, the events read
 * and not yet handed to the queue, the Ping waiting for its answer and when to try again.
 *
 * @internal
 */
final class NodeSession
{
    public ?AmiEventStream $stream = null;

    /** Identifies the current connection in call_events.session. */
    public ?string $session = null;

    public int $sequence = 0;

    /** Failed connections in a row, which set the backoff. */
    public int $failures = 0;

    public float $retryAt = 0;

    public ?string $pingId = null;

    public float $pingSentAt = 0;

    public float $lastPingAt = 0;

    public float $flushedAt = 0;

    public int $eventsCount = 0;

    public ?float $lastEventAt = null;

    /** @var list<array{session: string, sequence: int, event: string, fields: array<string, string>, occurred_at: string}> */
    public array $pending = [];

    /**
     * @param  array{host: string, port: int, username: string, password: string}|null  $target  where it logs in, to notice a change
     */
    public function __construct(public PbxNode $node, public ?array $target = null) {}
}
