<?php

use App\Models\User;
use App\Modules\CallRecords\Actions\SyncCallRecords;
use App\Modules\CallRecords\Enums\CallStatus;
use App\Modules\CallRecords\Models\CallRecord;
use App\Modules\CallRecords\Models\CdrCursor;
use App\Modules\Infrastructure\Asterisk\CdrAdapter;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Nodes\NodeConnections;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The node's cdr table in memory.
 */
class FakeCdrTable
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public ?Throwable $failWith = null;

    /**
     * A row as cdr_adaptive_odbc writes it, ending at the given time.
     *
     * @param  array<string, mixed>  $columns
     */
    public function add(string $end, array $columns): void
    {
        $this->rows[] = [
            'uniqueid' => '1793286818.'.count($this->rows),
            'sequence' => count($this->rows),
            'linkedid' => null,
            'src' => null,
            'dst' => null,
            'clid' => null,
            'channel' => 'PJSIP/opensips-00000006',
            'dstchannel' => null,
            'lastapp' => 'Dial',
            'lastdata' => null,
            'disposition' => 'ANSWERED',
            'accountcode' => null,
            'userfield' => null,
            'duration' => 20,
            'billsec' => 15,
            'start' => CarbonImmutable::parse($end)->subSeconds(20),
            'answer' => CarbonImmutable::parse($end)->subSeconds(15),
            ...$columns,
            'end' => CarbonImmutable::parse($end),
        ];
    }
}

function fakeCdr(): FakeCdrTable
{
    $table = new FakeCdrTable;

    app()->instance(CdrAdapter::class, new class(app(NodeConnections::class), $table) extends CdrAdapter
    {
        public function __construct(NodeConnections $connections, private FakeCdrTable $table)
        {
            parent::__construct($connections);
        }

        public function oldestEnd(PbxNode $node): ?CarbonImmutable
        {
            if ($this->table->failWith !== null) {
                throw $this->table->failWith;
            }

            $ends = array_map(fn (array $row): CarbonImmutable => $row['end'], $this->table->rows);

            return $ends === [] ? null : min($ends);
        }

        public function endedBetween(PbxNode $node, CarbonImmutable $from, CarbonImmutable $until): array
        {
            if ($this->table->failWith !== null) {
                throw $this->table->failWith;
            }

            return array_values(array_filter($this->table->rows, fn (array $row): bool => $row['end'] >= $from && $row['end'] < $until));
        }
    });

    return $table;
}

/**
 * Tenants 1000 and 1001 on one node.
 *
 * @return array{PbxNode, Tenant, Tenant}
 */
function twoTenantsOnANode(): array
{
    $node = PbxNode::factory()->create();
    $first = TenantPbxAssignment::factory()->code('1000')->for($node, 'node')->create()->tenant;
    $second = TenantPbxAssignment::factory()->code('1001')->for($node, 'node')->create()->tenant;

    return [$node, $first, $second];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 16:00:00'));
});

it('copies the CDR with the tenants on both ends', function () {
    $cdr = fakeCdr();
    [$node, $first, $second] = twoTenantsOnANode();
    $code = AuthorizationCode::factory()->for($second)->create(['label' => 'Teacher A']);

    $cdr->add('2026-10-29 15:00:00', ['src' => '201', 'dst' => '1001202']);
    $cdr->add('2026-10-29 15:01:00', ['src' => '81001201', 'dst' => '1000201', 'userfield' => "authcode:{$code->id}"]);
    $cdr->add('2026-10-29 15:02:00', ['src' => '202', 'dst' => 'denied', 'lastapp' => 'Hangup', 'lastdata' => '21']);
    $cdr->add('2026-10-29 15:03:00', ['src' => '201', 'dst' => '100181000202', 'disposition' => 'NO ANSWER', 'answer' => null, 'billsec' => 0]);
    $cdr->add('2026-10-29 15:04:00', ['src' => '201', 'dst' => '1000202', 'disposition' => 'BUSY']);
    // a tenant and its code deleted since
    $cdr->add('2026-10-29 15:05:00', ['src' => '201', 'dst' => '099980998201', 'userfield' => 'authcode:999999']);
    // refused by the caller's calling dialplan, then by a disabled number of another tenant
    $cdr->add('2026-10-29 15:06:00', ['src' => '202', 'dst' => '1001denied', 'lastapp' => 'Hangup', 'lastdata' => '21']);
    $cdr->add('2026-10-29 15:07:00', ['src' => '81000201', 'dst' => '1001denied', 'lastapp' => 'Hangup', 'lastdata' => '21']);

    $cursor = app(SyncCallRecords::class)->handle($node);

    expect($cursor->last_error)->toBeNull()
        ->and($cursor->last_count)->toBe(8)
        ->and($cursor->synced_until->toDateTimeString())->toBe('2026-10-29 16:00:00');

    $records = CallRecord::query()->orderBy('ended_at')->get();

    expect($records->map->only(['caller_tenant_id', 'from_number', 'callee_tenant_id', 'to_number', 'status', 'authorization_code_id'])->all())->toBe([
        ['caller_tenant_id' => $second->id, 'from_number' => '201', 'callee_tenant_id' => $second->id, 'to_number' => '202', 'status' => CallStatus::Answered, 'authorization_code_id' => null],
        ['caller_tenant_id' => $second->id, 'from_number' => '201', 'callee_tenant_id' => $first->id, 'to_number' => '201', 'status' => CallStatus::Answered, 'authorization_code_id' => $code->id],
        ['caller_tenant_id' => null, 'from_number' => '202', 'callee_tenant_id' => null, 'to_number' => null, 'status' => CallStatus::Denied, 'authorization_code_id' => null],
        ['caller_tenant_id' => $second->id, 'from_number' => '201', 'callee_tenant_id' => $first->id, 'to_number' => '202', 'status' => CallStatus::NoAnswer, 'authorization_code_id' => null],
        ['caller_tenant_id' => $first->id, 'from_number' => '201', 'callee_tenant_id' => $first->id, 'to_number' => '202', 'status' => CallStatus::Busy, 'authorization_code_id' => null],
        ['caller_tenant_id' => null, 'from_number' => '201', 'callee_tenant_id' => null, 'to_number' => null, 'status' => CallStatus::Answered, 'authorization_code_id' => null],
        ['caller_tenant_id' => $second->id, 'from_number' => '202', 'callee_tenant_id' => null, 'to_number' => null, 'status' => CallStatus::Denied, 'authorization_code_id' => null],
        ['caller_tenant_id' => $first->id, 'from_number' => '201', 'callee_tenant_id' => $second->id, 'to_number' => null, 'status' => CallStatus::Denied, 'authorization_code_id' => null],
    ])->and($records[1]->dst)->toBe('1000201')
        ->and($records[1]->billsec)->toBe(15);
});

it('reads a few minutes back for late rows without copying a row twice', function () {
    $cdr = fakeCdr();
    [$node] = twoTenantsOnANode();
    $cdr->add('2026-10-29 15:58:00', ['src' => '201', 'dst' => '1000202']);
    app(SyncCallRecords::class)->handle($node);

    // written after the sync, though the call ended before it
    $cdr->add('2026-10-29 15:59:30', ['src' => '202', 'dst' => '1000201']);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 16:01:00'));
    $cursor = app(SyncCallRecords::class)->handle($node);

    expect($cursor->last_count)->toBe(2)
        ->and(CallRecord::query()->count())->toBe(2)
        ->and(CallRecord::query()->pluck('from_number')->sort()->values()->all())->toBe(['201', '202']);
});

it('reads a long backlog a window at a time from the oldest row', function () {
    $cdr = fakeCdr();
    [$node] = twoTenantsOnANode();
    $cdr->add('2026-10-01 08:00:00', ['src' => '201', 'dst' => '1000202']);
    $cdr->add('2026-10-29 15:00:00', ['src' => '202', 'dst' => '1000201']);

    $first = app(SyncCallRecords::class)->handle($node);

    // 40 windows of 6 hours cover 10 days
    expect($first->last_count)->toBe(1)
        ->and($first->synced_until->toDateTimeString())->toBe('2026-10-11 08:00:00');

    Artisan::call('cdr:sync');
    Artisan::call('cdr:sync');
    $last = CdrCursor::query()->sole();

    expect($last->synced_until->toDateTimeString())->toBe('2026-10-29 16:00:00')
        ->and(CallRecord::query()->count())->toBe(2);
});

it('keeps the cursor and records the error when the node cannot be read', function () {
    $cdr = fakeCdr();
    [$node] = twoTenantsOnANode();
    app(SyncCallRecords::class)->handle($node);
    $cdr->failWith = new RuntimeException('SQLSTATE[HY000] [2002] Connection timed out');
    $this->travelTo(CarbonImmutable::parse('2026-10-29 16:05:00'));

    $exitCode = Artisan::call('cdr:sync');
    $cursor = CdrCursor::query()->sole();

    expect($exitCode)->toBe(1)
        ->and($cursor->last_error)->not->toBeNull()
        ->and($cursor->synced_until->toDateTimeString())->toBe('2026-10-29 16:00:00')
        ->and($cursor->last_run_at->toDateTimeString())->toBe('2026-10-29 16:05:00');
});

it('skips removed nodes and nodes without Asterisk', function () {
    fakeCdr();
    PbxNode::factory()->removed()->create();
    $withoutAsterisk = PbxNode::factory()->create();
    $withoutAsterisk->update(['services' => [...$withoutAsterisk->services, 'asterisk' => null]]);

    Artisan::call('cdr:sync');

    expect(CdrCursor::query()->count())->toBe(0);
});

it('shows each tenant its calls, with other tenants as they are dialed', function () {
    $cdr = fakeCdr();
    [$node, $first, $second] = twoTenantsOnANode();
    $code = AuthorizationCode::factory()->for($second)->create(['label' => 'Teacher A']);
    $cdr->add('2026-10-29 15:00:00', ['src' => '201', 'dst' => '1001202']);
    $cdr->add('2026-10-29 15:01:00', ['src' => '81001201', 'dst' => '1000201', 'userfield' => "authcode:{$code->id}"]);
    $cdr->add('2026-10-29 15:02:00', ['src' => '202', 'dst' => 'denied']);
    app(SyncCallRecords::class)->handle($node);

    $user = User::factory()->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $first->id, 'user_id' => $user->id, 'role' => TenantRole::Member]);
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $second->id, 'user_id' => $user->id, 'role' => TenantRole::Member]);
    $this->actingAs($user);

    $this->get(route('tenant.calls.index', $first))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/Calls')
            ->has('calls', 1)
            ->where('calls.0.direction', 'incoming')
            ->where('calls.0.from', '81001201')
            ->where('calls.0.to', '201')
            ->where('calls.0.authorization_code', null)
            ->where('next_cursor', null));

    $this->get(route('tenant.calls.index', $second))
        ->assertInertia(fn (Assert $page) => $page
            ->has('calls', 2)
            ->where('calls.0.direction', 'outgoing')
            ->where('calls.0.from', '201')
            ->where('calls.0.to', '81000201')
            ->where('calls.0.authorization_code', 'Teacher A')
            ->where('calls.1.direction', 'internal')
            ->where('calls.1.to', '202'));
});

it('pages the call history by cursor', function () {
    $cdr = fakeCdr();
    [$node, $first] = twoTenantsOnANode();

    foreach (range(1, 101) as $minute) {
        $cdr->add(CarbonImmutable::parse('2026-10-29 13:00:00')->addMinutes($minute)->toDateTimeString(), ['src' => '201', 'dst' => '1000202']);
    }

    app(SyncCallRecords::class)->handle($node);
    $user = User::factory()->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $first->id, 'user_id' => $user->id, 'role' => TenantRole::Member]);
    $this->actingAs($user);

    $cursor = null;
    $this->get(route('tenant.calls.index', $first))
        ->assertInertia(function (Assert $page) use (&$cursor) {
            $page->has('calls', 100)->where('paged', false)->whereType('next_cursor', 'string');
            $cursor = $page->toArray()['props']['next_cursor'];
        });

    $this->get(route('tenant.calls.index', [$first, 'cursor' => $cursor]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('calls', 1)
            ->where('calls.0.ended_at', CarbonImmutable::parse('2026-10-29 13:01:00')->toIso8601String())
            ->where('paged', true));
});

it('keeps calls for 13 months', function () {
    [$node] = twoTenantsOnANode();
    $attributes = ['pbx_node_id' => $node->id, 'status' => CallStatus::Answered, 'sequence' => 0];
    CallRecord::query()->create([...$attributes, 'uniqueid' => 'old', 'ended_at' => now()->subMonths(13)->subDay()]);
    CallRecord::query()->create([...$attributes, 'uniqueid' => 'recent', 'ended_at' => now()->subMonths(12)]);

    Artisan::call('model:prune', ['--model' => [CallRecord::class]]);

    expect(CallRecord::query()->pluck('uniqueid')->all())->toBe(['recent']);
});

it('shows the platform admin how far the CDR of each node is copied', function () {
    fakeCdr();
    [$node] = twoTenantsOnANode();
    app(SyncCallRecords::class)->handle($node);
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get(route('admin.nodes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nodes.0.cdr_sync.synced_until', CarbonImmutable::parse('2026-10-29 16:00:00')->toIso8601String())
            ->where('nodes.0.cdr_sync.last_error', null));
});
