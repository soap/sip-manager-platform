<?php

use App\Models\User;
use App\Modules\CallRecords\Enums\AmiConnectionStatus;
use App\Modules\CallRecords\Jobs\StoreCallEvents;
use App\Modules\CallRecords\Listener\CallEventListener;
use App\Modules\CallRecords\Models\AmiConnection;
use App\Modules\CallRecords\Models\CallEvent;
use App\Modules\Infrastructure\Asterisk\AmiEventStream;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiUnavailable;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Asterisk as the listener sees it: each connection the listener opens is a local socket whose far
 * end already holds the script for it; the test writes more, or hangs up, while the listener runs.
 */
class FakeAmiServer
{
    /** @var list<string> scripts of the next connections; none left = the node refuses */
    public array $scripts = [];

    /** @var list<resource> */
    public array $connections = [];

    public int $attempts = 0;

    public function open(): AmiEventStream
    {
        $this->attempts++;
        $script = array_shift($this->scripts);

        if ($script === null) {
            throw AmiUnavailable::cannotConnect('127.0.0.1', 5038, 'Connection refused');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $socket = stream_socket_client('tcp://'.stream_socket_get_name($server, false), timeout: 2);
        $far = stream_socket_accept($server, 2);
        fclose($server);
        fwrite($far, $script);
        stream_set_timeout($socket, 1);
        $this->connections[] = $far;

        return AmiEventStream::open($socket, 'manager', 's3cret', CallEventListener::EVENT_MASK);
    }

    public function write(int $connection, string $data): void
    {
        fwrite($this->connections[$connection], $data);
    }

    public function hangUp(int $connection): void
    {
        fclose($this->connections[$connection]);
    }

    /**
     * What the listener has sent over the connection so far.
     */
    public function sent(int $connection): string
    {
        stream_set_blocking($this->connections[$connection], false);

        return (string) stream_get_contents($this->connections[$connection]);
    }
}

/**
 * A listener that opens its connections on the fake server and polls every 10 ms.
 */
function fakeAmiListener(FakeAmiServer $server): CallEventListener
{
    $listener = new class(app(NodeConnections::class), $server) extends CallEventListener
    {
        public function __construct(NodeConnections $connections, private FakeAmiServer $server)
        {
            parent::__construct($connections);
        }

        protected function open(PbxNode $node): AmiEventStream
        {
            return $this->server->open();
        }
    };

    $listener->pollSeconds = 0.01;

    return $listener;
}

/**
 * Runs the listener for the given loops, calling $step at the start of each one.
 *
 * @param  list<PbxNode>  $nodes
 * @param  (Closure(int): void)|null  $step
 * @return list<string> what the listener reported
 */
function listenFor(CallEventListener $listener, array $nodes, int $loops, ?Closure $step = null): array
{
    $reported = [];
    $loop = 0;

    $listener->run(
        fn (): array => $nodes,
        function () use (&$loop, $loops, $step): bool {
            $loop++;

            if ($loop > $loops) {
                return true;
            }

            if ($step !== null) {
                $step($loop);
            }

            return false;
        },
        function (string $line) use (&$reported): void {
            $reported[] = $line;
        },
    );

    return $reported;
}

function amiLogin(): string
{
    return "Asterisk Call Manager/11.0.0\r\n".amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-1', 'Message' => 'Authentication accepted']);
}

/**
 * @param  array<string, string>  $fields
 */
function amiEvent(string $event, array $fields = []): string
{
    return amiReply(['Event' => $event, 'Privilege' => 'call,all', ...$fields]);
}

function advanceSeconds(float $seconds): void
{
    Carbon::setTestNow(now()->addMilliseconds((int) ($seconds * 1000)));
}

beforeEach(function () {
    Carbon::setTestNow(now());
});

it('logs in for call events and stores the ones that tell how calls go, with their tenant', function () {
    $tenant = Tenant::factory()->create(['code' => '1001']);
    $node = PbxNode::factory()->create();
    $server = new FakeAmiServer;
    $server->scripts[] = amiLogin()
        .amiEvent('Newchannel', ['Channel' => 'PJSIP/opensips-00000001', 'Exten' => '1001201', 'CallerIDNum' => '202', 'Uniqueid' => 'u1', 'Linkedid' => 'u1'])
        .amiEvent('VarSet', ['Channel' => 'PJSIP/opensips-00000001', 'Variable' => 'TRIES', 'Value' => '0', 'Uniqueid' => 'u1', 'Linkedid' => 'u1'])
        .amiEvent('UserEvent', ['UserEvent' => 'AuthCodeFailed', 'Tenant' => '1001', 'Caller' => '202', 'Uniqueid' => 'u1', 'Linkedid' => 'u1'])
        .amiEvent('UserEvent', ['UserEvent' => 'AuthCodeFailed', 'Tenant' => '1001', 'Caller' => '202', 'Uniqueid' => 'u1', 'Linkedid' => 'u1'])
        .amiEvent('Hangup', ['Channel' => 'PJSIP/opensips-00000001', 'Exten' => '1001denied', 'Cause' => '21', 'Uniqueid' => 'u1', 'Linkedid' => 'u1']);

    $reported = listenFor(fakeAmiListener($server), [$node], 5);

    $events = CallEvent::query()->orderBy('sequence')->get();

    expect($server->sent(0))->toContain("Events: call,agent,user,cdr\r\n")
        ->and($reported)->toContain("{$node->name}: connected")
        ->and($events->pluck('event')->all())->toBe(['Newchannel', 'UserEvent', 'UserEvent', 'Hangup'])
        ->and($events->pluck('sequence')->all())->toBe([1, 2, 3, 4])
        ->and($events->pluck('session')->unique())->toHaveCount(1)
        ->and($events->pluck('tenant_id')->unique()->all())->toBe([$tenant->id])
        ->and($events[0]->uniqueid)->toBe('u1')
        ->and($events[0]->channel)->toBe('PJSIP/opensips-00000001')
        ->and($events[1]->fields)->toMatchArray(['userevent' => 'AuthCodeFailed', 'tenant' => '1001', 'caller' => '202'])
        ->and($events[1]->fields)->not->toHaveKey('privilege');

    $connection = AmiConnection::query()->sole();

    expect($connection->status)->toBe(AmiConnectionStatus::Stopped)
        ->and($connection->listener)->toBeNull()
        ->and($connection->events_count)->toBe(4)
        ->and($connection->last_event_at)->not->toBeNull();
});

it('finds the tenant of an event from an earlier event of the same call', function () {
    $tenant = Tenant::factory()->create(['code' => '1001']);
    $caller = Tenant::factory()->create(['code' => '1002']);
    $node = PbxNode::factory()->create();
    $event = fn (int $sequence, string $name, array $fields): array => ['session' => '01JBSESSION0000000000000000', 'sequence' => $sequence, 'event' => $name, 'fields' => $fields, 'occurred_at' => now()->format('Y-m-d H:i:s.u')];

    (new StoreCallEvents($node->id, [
        $event(1, 'Newchannel', ['exten' => '1001201', 'uniqueid' => 'u1', 'linkedid' => 'u1']),
        $event(2, 'Newchannel', ['exten' => 's', 'calleridnum' => '81002201', 'uniqueid' => 'u2', 'linkedid' => 'u2']),
    ]))->handle();
    (new StoreCallEvents($node->id, [
        $event(3, 'DialBegin', ['exten' => 's', 'uniqueid' => 'u3', 'linkedid' => 'u1']),
        $event(4, 'Hangup', ['exten' => 'h', 'uniqueid' => 'u4', 'linkedid' => 'unknown']),
        // an originated call: no calling channel
        $event(5, 'DialBegin', ['destchannel' => 'Local/1001201@from-opensips-00000002;1', 'destexten' => '1001201', 'destuniqueid' => 'u5', 'destlinkedid' => 'u5']),
    ]))->handle();

    $events = CallEvent::query()->orderBy('sequence')->get();

    expect($events->pluck('tenant_id')->all())->toBe([$tenant->id, $caller->id, $tenant->id, null, $tenant->id])
        ->and($events[4]->only(['uniqueid', 'linkedid', 'channel']))->toBe(['uniqueid' => 'u5', 'linkedid' => 'u5', 'channel' => 'Local/1001201@from-opensips-00000002;1']);
});

it('connects again after the connection drops, without storing an event twice', function () {
    $node = PbxNode::factory()->create();
    $server = new FakeAmiServer;
    $server->scripts[] = amiLogin().amiEvent('Newchannel', ['Uniqueid' => 'u1', 'Linkedid' => 'u1']);
    $server->scripts[] = amiLogin().amiEvent('Hangup', ['Uniqueid' => 'u1', 'Linkedid' => 'u1']);

    $reported = listenFor(fakeAmiListener($server), [$node], 8, function (int $loop) use ($server): void {
        if ($loop === 3) {
            $server->hangUp(0);
        }

        if ($loop === 5) {
            advanceSeconds(CallEventListener::BACKOFF_SECONDS[0]);
        }
    });

    $events = CallEvent::query()->orderBy('id')->get();

    expect($server->attempts)->toBe(2)
        ->and($reported)->toContain("{$node->name}: AMI closed the connection. Connecting again in 1s.")
        ->and($events->pluck('event')->all())->toBe(['Newchannel', 'Hangup'])
        ->and($events->pluck('session')->unique())->toHaveCount(2);

    // a batch stored again, as when its job is retried, adds nothing
    $first = $events[0];
    (new StoreCallEvents($node->id, [[
        'session' => $first->session, 'sequence' => $first->sequence, 'event' => $first->event,
        'fields' => $first->fields, 'occurred_at' => $first->occurred_at->format('Y-m-d H:i:s.u'),
    ]]))->handle();

    expect(CallEvent::query()->count())->toBe(2);
});

it('pings a quiet connection, drops one that does not answer and backs off while the node refuses', function () {
    $node = PbxNode::factory()->create();
    $server = new FakeAmiServer;
    $server->scripts[] = amiLogin();

    $reported = listenFor(fakeAmiListener($server), [$node], 12, function (int $loop) use ($server): void {
        match ($loop) {
            2 => advanceSeconds(CallEventListener::PING_SECONDS),
            4 => $server->write(0, amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-ping-1', 'Ping' => 'Pong'])),
            6 => advanceSeconds(CallEventListener::PING_SECONDS),
            8 => advanceSeconds(CallEventListener::PONG_TIMEOUT_SECONDS),
            10 => advanceSeconds(CallEventListener::BACKOFF_SECONDS[0]),
            default => null,
        };
    });

    expect($server->sent(0))->toContain("Action: Ping\r\nActionID: sip-manager-ping-1\r\n")
        ->toContain("ActionID: sip-manager-ping-2\r\n")
        ->and($reported)->toContain("{$node->name}: AMI did not answer Ping. Connecting again in 1s.")
        ->toContain("{$node->name}: Could not connect to AMI at 127.0.0.1:5038: Connection refused Connecting again in 2s.")
        ->and($server->attempts)->toBe(2)
        ->and(AmiConnection::query()->sole()->last_error)->toContain('Could not connect to AMI');
});

it('leaves a node alone while another listener holds it, and takes it over once that one is gone', function () {
    $node = PbxNode::factory()->create();
    AmiConnection::query()->create(['pbx_node_id' => $node->id, 'listener' => '01JBOTHERLISTENER000000000', 'heartbeat_at' => now()]);
    $server = new FakeAmiServer;
    $server->scripts[] = amiLogin();

    $reported = listenFor(fakeAmiListener($server), [$node], 4, function (int $loop): void {
        if ($loop === 3) {
            advanceSeconds(AmiConnection::STALE_SECONDS + 1);
        }
    });

    expect($reported[0])->toBe("{$node->name}: another listener holds the node")
        ->and($reported)->toContain("{$node->name}: connected")
        ->and($server->attempts)->toBe(1);
});

it('does not listen to a node without AMI', function () {
    $node = PbxNode::factory()->create();
    $node->update(['endpoints' => collect($node->endpoints)->except('ami')->all()]);
    $server = new FakeAmiServer;

    listenFor(fakeAmiListener($server), [$node], 3);

    expect($server->attempts)->toBe(0)
        ->and(CallEventListener::canListen($node))->toBeFalse();
});

it('keeps call events for 30 days', function () {
    $node = PbxNode::factory()->create();
    $attributes = ['pbx_node_id' => $node->id, 'session' => '01JBSESSION0000000000000000', 'event' => 'Hangup', 'fields' => []];
    CallEvent::query()->create([...$attributes, 'sequence' => 1, 'uniqueid' => 'old', 'occurred_at' => now()->subDays(31)]);
    CallEvent::query()->create([...$attributes, 'sequence' => 2, 'uniqueid' => 'recent', 'occurred_at' => now()->subDays(29)]);

    Artisan::call('model:prune', ['--model' => [CallEvent::class]]);

    expect(CallEvent::query()->pluck('uniqueid')->all())->toBe(['recent']);
});

it('shows the platform admin whether a listener is connected to each node', function () {
    $node = PbxNode::factory()->create();
    AmiConnection::query()->create([
        'pbx_node_id' => $node->id, 'listener' => '01JBLISTENER00000000000000', 'heartbeat_at' => now(),
        'status' => AmiConnectionStatus::Connected, 'connected_at' => now(), 'events_count' => 12,
    ]);
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get(route('admin.nodes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nodes.0.ami_listener.status', 'connected')
            ->where('nodes.0.ami_listener.alive', true)
            ->where('nodes.0.ami_listener.events_count', 12));

    advanceSeconds(AmiConnection::STALE_SECONDS + 1);

    $this->get(route('admin.nodes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('nodes.0.ami_listener.alive', false));
});
