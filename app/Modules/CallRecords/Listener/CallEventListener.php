<?php

namespace App\Modules\CallRecords\Listener;

use App\Modules\CallRecords\Enums\AmiConnectionStatus;
use App\Modules\CallRecords\Jobs\StoreCallEvents;
use App\Modules\CallRecords\Models\AmiConnection;
use App\Modules\Infrastructure\Asterisk\AmiEventStream;
use App\Modules\Infrastructure\Asterisk\AmiMessage;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiUnavailable;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Listens to the AMI events of the nodes in one process (plan §7): one connection per node, all
 * waited on with stream_select, the events that tell how calls go handed in batches to
 * StoreCallEvents on the events queue. A connection that drops, or does not answer Ping in time,
 * is opened again with backoff. A node is held by one listener at a time (AmiConnection::claim),
 * so a second process does not store every event twice.
 *
 * AMI does not replay what was sent while a connection was down: events of that gap are lost, and
 * the CDR copied by cdr:sync remains the record of the calls.
 */
class CallEventListener
{
    /** Event classes asked for at login; the node's manager user may read each of them. */
    public const EVENT_MASK = 'call,agent,user,cdr';

    /** The events stored; the others of these classes (Newexten, VarSet, RTCP…) are dropped. */
    public const EVENTS = [
        'Newchannel', 'Newstate', 'DialBegin', 'DialEnd', 'BridgeEnter', 'BridgeLeave', 'Hold', 'Unhold',
        'Hangup', 'UserEvent', 'Cdr', 'QueueCallerJoin', 'QueueCallerLeave', 'QueueCallerAbandon',
        'AgentCalled', 'AgentConnect', 'AgentComplete', 'AgentRingNoAnswer',
    ];

    public const PING_SECONDS = 20;

    public const PONG_TIMEOUT_SECONDS = 10;

    /** Seconds before connecting again after 1, 2, 3… failures in a row. */
    public const BACKOFF_SECONDS = [1, 2, 5, 10, 30, 60];

    /** Seconds before trying again a node that another listener holds. */
    public const HELD_RETRY_SECONDS = 60;

    /** Seconds the nodes are listened to before they are read again (a node added, removed or with new credentials). */
    public const REFRESH_SECONDS = 60;

    public const FLUSH_SECONDS = 1;

    public const FLUSH_SIZE = 200;

    /** Events kept while the queue does not take them; the oldest go first. */
    public const MAX_PENDING = 10000;

    /** Seconds stream_select waits for data when nothing else is due. */
    public float $pollSeconds = 1.0;

    /** This process, in ami_connections.listener. */
    public readonly string $id;

    /** @var array<int, NodeSession> */
    private array $sessions = [];

    /** @var Closure(string): void */
    private Closure $report;

    public function __construct(private NodeConnections $connections)
    {
        $this->id = (string) Str::ulid();
        $this->report = function (string $line): void {};
    }

    /**
     * Whether the node has an Asterisk with AMI to listen to.
     */
    public static function canListen(PbxNode $node): bool
    {
        return $node->removed_at === null && $node->hasAsterisk() && isset($node->endpoints['ami']);
    }

    /**
     * Listens until $shouldStop says so, then hands over what it read and logs off.
     *
     * @param  Closure(): iterable<PbxNode>  $nodes  the nodes to listen to, asked again every REFRESH_SECONDS
     * @param  Closure(): bool  $shouldStop  asked once per loop
     * @param  (Closure(string): void)|null  $report  told when a connection opens, drops or stops
     */
    public function run(Closure $nodes, Closure $shouldStop, ?Closure $report = null): void
    {
        $this->report = $report ?? function (string $line): void {};
        $refreshedAt = null;

        try {
            while (! $shouldStop()) {
                if ($refreshedAt === null || $this->clock() - $refreshedAt >= self::REFRESH_SECONDS) {
                    $this->refresh($nodes());
                    $refreshedAt = $this->clock();
                }

                foreach ($this->sessions as $session) {
                    if ($session->stream === null && $this->clock() >= $session->retryAt) {
                        $this->connect($session);
                    }
                }

                $this->wait();

                foreach ($this->sessions as $session) {
                    if ($session->stream !== null) {
                        $this->receive($session);
                    }

                    if ($session->stream !== null) {
                        $this->heartbeat($session);
                    }

                    $this->flush($session);
                }
            }
        } finally {
            foreach ($this->sessions as $session) {
                $this->stop($session);
            }

            $this->sessions = [];
        }
    }

    /**
     * Logs in to the node's AMI with the event mask.
     */
    protected function open(PbxNode $node): AmiEventStream
    {
        return AmiEventStream::connect($this->connections->ami($node), self::EVENT_MASK);
    }

    /**
     * Starts listening to new nodes, stops for nodes that are gone, and reconnects to a node whose
     * address or credentials changed.
     *
     * @param  iterable<PbxNode>  $nodes
     */
    private function refresh(iterable $nodes): void
    {
        $wanted = [];

        foreach ($nodes as $node) {
            if (self::canListen($node)) {
                $wanted[$node->id] = $node;
            }
        }

        foreach ($this->sessions as $id => $session) {
            if (! isset($wanted[$id])) {
                $this->stop($session);
                unset($this->sessions[$id]);
                ($this->report)("{$session->node->name}: stopped listening");
            }
        }

        foreach ($wanted as $id => $node) {
            $target = $this->target($node);

            if (! isset($this->sessions[$id])) {
                $this->sessions[$id] = new NodeSession($node, $target);

                continue;
            }

            $session = $this->sessions[$id];
            $session->node = $node;

            if ($session->target !== $target) {
                $session->target = $target;
                $session->retryAt = 0;

                if ($session->stream !== null) {
                    $this->close($session);
                    ($this->report)("{$node->name}: address or credentials changed, connecting again");
                }
            }
        }
    }

    /**
     * @return array{host: string, port: int, username: string, password: string}|null
     */
    private function target(PbxNode $node): ?array
    {
        try {
            return $this->connections->ami($node);
        } catch (Throwable) {
            return null;
        }
    }

    private function connect(NodeSession $session): void
    {
        $node = $session->node;

        if (! AmiConnection::claim($node, $this->id)) {
            $session->retryAt = $this->clock() + self::HELD_RETRY_SECONDS;
            ($this->report)("{$node->name}: another listener holds the node");

            return;
        }

        $this->state($node, ['status' => AmiConnectionStatus::Connecting->value]);

        try {
            $session->stream = $this->open($node);
        } catch (Throwable $exception) {
            $this->retryLater($session, $exception->getMessage());

            return;
        }

        $session->session = (string) Str::ulid();
        $session->sequence = 0;
        $session->failures = 0;
        $session->pingId = null;
        $session->lastPingAt = $session->flushedAt = $this->clock();
        $session->eventsCount = 0;
        $session->lastEventAt = null;

        $this->state($node, [
            'status' => AmiConnectionStatus::Connected->value,
            'heartbeat_at' => now(),
            'connected_at' => now(),
            'disconnected_at' => null,
            'last_event_at' => null,
            'events_count' => 0,
            'last_error' => null,
        ]);

        ($this->report)("{$node->name}: connected");
    }

    /**
     * Waits until a connection has data, or pollSeconds have passed.
     */
    private function wait(): void
    {
        $sockets = [];

        foreach ($this->sessions as $session) {
            if ($session->stream !== null) {
                $sockets[] = $session->stream->socket();
            }
        }

        if ($sockets === []) {
            usleep((int) ($this->pollSeconds * 1_000_000));

            return;
        }

        $seconds = (int) floor($this->pollSeconds);
        $write = $except = null;

        // false when a signal interrupts the wait, which the loop then checks
        @stream_select($sockets, $write, $except, $seconds, (int) (($this->pollSeconds - $seconds) * 1_000_000));
    }

    private function receive(NodeSession $session): void
    {
        assert($session->stream !== null && $session->session !== null);

        foreach ($session->stream->read() as $message) {
            if ($session->pingId !== null && $message->actionId() === $session->pingId) {
                $session->pingId = null;
                $this->state($session->node, ['heartbeat_at' => now(), ...$this->progress($session)]);

                continue;
            }

            $event = $message->get('Event');

            if ($event === null || ! in_array($event, self::EVENTS, true)) {
                continue;
            }

            $fields = $message->fields;
            unset($fields['privilege']);

            $session->pending[] = [
                'session' => $session->session,
                'sequence' => ++$session->sequence,
                'event' => $event,
                'fields' => $fields,
                'occurred_at' => $this->occurredAt($message),
            ];
            $session->eventsCount++;
            $session->lastEventAt = $this->clock();
        }

        if ($session->stream->closed()) {
            $this->disconnect($session, AmiUnavailable::closed()->getMessage());
        }
    }

    /**
     * Pings a connection that has been quiet for a while, and drops one whose Ping went unanswered.
     */
    private function heartbeat(NodeSession $session): void
    {
        assert($session->stream !== null);

        $now = $this->clock();

        if ($session->pingId !== null) {
            if ($now - $session->pingSentAt >= self::PONG_TIMEOUT_SECONDS) {
                $this->disconnect($session, 'AMI did not answer Ping.');
            }

            return;
        }

        if ($now - $session->lastPingAt >= self::PING_SECONDS) {
            $session->pingId = $session->stream->ping();
            $session->pingSentAt = $session->lastPingAt = $now;

            if ($session->stream->closed()) {
                $this->disconnect($session, AmiUnavailable::closed()->getMessage());
            }
        }
    }

    /**
     * Hands the events read to the queue once there are enough of them or they have waited long
     * enough; keeps them for the next try when the queue does not take them.
     */
    private function flush(NodeSession $session, bool $force = false): void
    {
        if ($session->pending === []) {
            $session->flushedAt = $this->clock();

            return;
        }

        if (! $force && count($session->pending) < self::FLUSH_SIZE && $this->clock() - $session->flushedAt < self::FLUSH_SECONDS) {
            return;
        }

        try {
            StoreCallEvents::dispatch($session->node->id, $session->pending);
            $session->pending = [];
        } catch (Throwable $exception) {
            report($exception);

            if (count($session->pending) > self::MAX_PENDING) {
                $session->pending = array_slice($session->pending, -self::MAX_PENDING);
                ($this->report)("{$session->node->name}: the queue does not take events, dropping the oldest");
            }
        }

        $session->flushedAt = $this->clock();
    }

    private function disconnect(NodeSession $session, string $error): void
    {
        $this->close($session);
        $this->retryLater($session, $error);
    }

    private function close(NodeSession $session): void
    {
        $this->flush($session, force: true);
        $session->stream?->close();
        $session->stream = null;
        $session->session = null;
        $session->pingId = null;
    }

    private function retryLater(NodeSession $session, string $error): void
    {
        $delay = self::BACKOFF_SECONDS[min($session->failures, count(self::BACKOFF_SECONDS) - 1)];
        $session->failures++;
        $session->retryAt = $this->clock() + $delay;

        $this->state($session->node, [
            ...$this->progress($session),
            'status' => AmiConnectionStatus::Disconnected->value,
            'disconnected_at' => now(),
            'last_error' => $error,
        ]);

        ($this->report)("{$session->node->name}: {$error} Connecting again in {$delay}s.");
    }

    /**
     * Logs off and lets go of the node, for another listener to take at once.
     */
    private function stop(NodeSession $session): void
    {
        $wasConnected = $session->stream !== null;
        $this->close($session);

        $this->state($session->node, [
            ...$this->progress($session),
            'status' => AmiConnectionStatus::Stopped->value,
            'listener' => null,
            ...($wasConnected ? ['disconnected_at' => now()] : []),
        ]);
    }

    /**
     * The events read over the current connection, for the node's connection row.
     *
     * @return array{events_count: int, last_event_at: Carbon|null}
     */
    private function progress(NodeSession $session): array
    {
        return [
            'events_count' => $session->eventsCount,
            'last_event_at' => $session->lastEventAt === null ? null : Carbon::createFromTimestamp($session->lastEventAt),
        ];
    }

    /**
     * Updates the node's connection row while this listener holds it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function state(PbxNode $node, array $attributes): void
    {
        AmiConnection::query()
            ->where('pbx_node_id', $node->id)
            ->where('listener', $this->id)
            ->update($attributes);
    }

    /**
     * The node's Timestamp when manager.conf has timestampevents on, otherwise now.
     */
    private function occurredAt(AmiMessage $message): string
    {
        $timestamp = $message->get('Timestamp');
        $time = $timestamp !== null && is_numeric($timestamp) ? Carbon::createFromTimestamp((float) $timestamp) : now();

        return $time->format('Y-m-d H:i:s.u');
    }

    private function clock(): float
    {
        return (float) now()->format('U.u');
    }
}
