<?php

use App\Modules\Infrastructure\Asterisk\AmiAdapter;
use App\Modules\Infrastructure\Asterisk\RealtimeAdapter;
use App\Modules\Infrastructure\OpenSips\OpenSipsDbAdapter;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Nodes\NodeConnections;
use App\Modules\Provisioning\Actions\ApplyRevision;
use App\Modules\Provisioning\Models\OutboxMessage;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\TenantMembership;
use Carbon\CarbonImmutable;

/*
| Helpers for tests that provision numbers: a node whose databases live in memory, and a tenant
| on it. Loaded by tests/Pest.php.
*/

/**
 * The node's two databases in memory: what the adapters would read and write on MariaDB.
 */
class FakeNodeDatabases
{
    /** @var array<string, array{username: string, domain: string, ha1: string, ha1_sha256: string}> */
    public array $subscribers = [];

    /** @var array<string, list<array{priority: int, app: string, appdata: string}>> */
    public array $extens = [];

    public ?Throwable $failWith = null;

    public bool $corruptReadBack = false;

    /** @var array<string, array{context: string, mailbox: string, password: string, fullname: string}> */
    public array $mailboxes = [];

    /** Asterisk does not see what the database holds, e.g. its realtime connection is down. */
    public bool $asteriskBlind = false;

    /** @var array<string, list<array{address: string, user_agent: string, registered_at: CarbonImmutable|null, expires_at: CarbonImmutable|null}>> */
    public array $registrations = [];

    /** The location table cannot be read, e.g. the node is down. */
    public bool $locationUnreachable = false;
}

/**
 * Swaps the adapters for ones backed by FakeNodeDatabases.
 */
function fakeNode(): FakeNodeDatabases
{
    $store = new FakeNodeDatabases;
    $connections = app(NodeConnections::class);

    app()->instance(AmiAdapter::class, new class($connections, $store) extends AmiAdapter
    {
        public function __construct(NodeConnections $connections, private FakeNodeDatabases $store)
        {
            parent::__construct($connections);
        }

        public function missingPriorities(PbxNode $node, string $exten, int $count): array
        {
            $seen = $this->store->asteriskBlind ? 0 : count($this->store->extens[$exten] ?? []);

            return $count > $seen ? range($seen + 1, $count) : [];
        }

        public function hasMailbox(PbxNode $node, string $mailbox, string $context): bool
        {
            return ! $this->store->asteriskBlind && ($this->store->mailboxes[$mailbox]['context'] ?? null) === $context;
        }
    });

    app()->instance(RealtimeAdapter::class, new class($connections, $store) extends RealtimeAdapter
    {
        public function __construct(NodeConnections $connections, private FakeNodeDatabases $store)
        {
            parent::__construct($connections);
        }

        public function replaceExten(PbxNode $node, string $exten, array $priorities): void
        {
            if ($this->store->failWith !== null) {
                throw $this->store->failWith;
            }

            $this->store->extens[$exten] = $priorities;

            if ($priorities === []) {
                unset($this->store->extens[$exten]);
            }
        }

        public function exten(PbxNode $node, string $exten): array
        {
            return $this->store->extens[$exten] ?? [];
        }

        public function replaceMailbox(PbxNode $node, string $mailbox, ?array $box): void
        {
            unset($this->store->mailboxes[$mailbox]);

            if ($box !== null) {
                $this->store->mailboxes[$mailbox] = $box;
            }
        }

        public function mailbox(PbxNode $node, string $mailbox): ?array
        {
            return $this->store->mailboxes[$mailbox] ?? null;
        }
    });

    app()->instance(OpenSipsDbAdapter::class, new class($connections, $store) extends OpenSipsDbAdapter
    {
        public function __construct(NodeConnections $connections, private FakeNodeDatabases $store)
        {
            parent::__construct($connections);
        }

        public function replaceSubscriber(PbxNode $node, string $username, ?array $subscriber): void
        {
            unset($this->store->subscribers[$username]);

            if ($subscriber !== null) {
                $this->store->subscribers[$username] = $subscriber;
            }
        }

        public function subscribers(PbxNode $node, string $username): array
        {
            $row = $this->store->subscribers[$username] ?? null;

            if ($row !== null && $this->store->corruptReadBack) {
                $row['ha1'] = 'tampered';
            }

            return $row === null ? [] : [$row];
        }

        public function registrations(PbxNode $node, array $usernames): array
        {
            if ($this->store->locationUnreachable) {
                throw new RuntimeException('SQLSTATE[HY000] [2002] Connection timed out');
            }

            return array_intersect_key($this->store->registrations, array_flip($usernames));
        }
    });

    return $store;
}

/**
 * Applies every revision waiting in the outbox, as the relay and the queue worker would.
 */
function applyPendingRevisions(): void
{
    OutboxMessage::query()->whereNull('dispatched_at')->orderBy('id')->get()->each(function (OutboxMessage $message): void {
        app(ApplyRevision::class)->handle($message->payload['config_revision_id']);
        $message->update(['dispatched_at' => now()]);
    });
}

/**
 * The signed-in admin of a tenant with prefix 20 on a node.
 *
 * @return array{Tenant, TenantPbxAssignment}
 */
function provisioningTenant(?TenantPbxAssignment $assignment = null): array
{
    $user = signIn();
    $assignment ??= TenantPbxAssignment::factory()->prefix('20')->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $assignment->tenant_id, 'user_id' => $user->id, 'role' => TenantRole::Admin]);

    return [$assignment->tenant, $assignment];
}
