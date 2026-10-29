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
    /** @var array<string, array{username: string, domain: string, ha1: string, ha1_sha256: string}> keyed by username@domain */
    public array $subscribers = [];

    /** @var array<string, array{domain: string, attrs: string}> keyed by tenant code */
    public array $domains = [];

    /** @var array<string, list<array{priority: int, app: string, appdata: string}>> */
    public array $extens = [];

    public ?Throwable $failWith = null;

    public bool $corruptReadBack = false;

    /** @var array<string, array{context: string, mailbox: string, password: string, fullname: string}> keyed by mailbox@context */
    public array $mailboxes = [];

    /** Asterisk does not see what the database holds, e.g. its realtime connection is down. */
    public bool $asteriskBlind = false;

    /** @var array<string, list<array{address: string, user_agent: string, registered_at: CarbonImmutable|null, expires_at: CarbonImmutable|null}>> */
    public array $registrations = [];

    /** The location table cannot be read, e.g. the node is down. */
    public bool $locationUnreachable = false;

    /**
     * The priorities of the first pattern exten that matches the number, as pbx_realtime finds it.
     *
     * @return list<array{priority: int, app: string, appdata: string}>
     */
    public function matchingPattern(string $number): array
    {
        foreach ($this->extens as $exten => $priorities) {
            if (str_starts_with($exten, '_') && preg_match(asteriskPatternRegex($exten), $number) === 1) {
                return $priorities;
            }
        }

        return [];
    }
}

/**
 * The regex of an Asterisk pattern such as _1042[2-7]XXX. (X, Z, N, [..], . and !).
 */
function asteriskPatternRegex(string $pattern): string
{
    $regex = (string) preg_replace_callback('/\[[^\]]+\]|[XZN.!]|[^XZN.!\[]+/', fn (array $match): string => match ($match[0]) {
        'X' => '[0-9]',
        'Z' => '[1-9]',
        'N' => '[2-9]',
        '.' => '.+',
        '!' => '.*',
        default => str_starts_with($match[0], '[') ? $match[0] : preg_quote($match[0], '/'),
    }, substr($pattern, 1));

    return '/^'.$regex.'$/';
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

        public function missingPrioritiesOf(PbxNode $node, array $counts): array
        {
            $missing = [];

            foreach (array_filter($counts) as $exten => $count) {
                $seen = $this->store->asteriskBlind ? 0 : count($this->store->extens[$exten] ?? $this->store->matchingPattern((string) $exten));

                if ($count > $seen) {
                    $missing[(string) $exten] = range($seen + 1, $count);
                }
            }

            return $missing;
        }

        public function hasMailbox(PbxNode $node, string $mailbox, string $context): bool
        {
            return ! $this->store->asteriskBlind && isset($this->store->mailboxes["{$mailbox}@{$context}"]);
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

        public function replaceExtensStartingWith(PbxNode $node, array $prefixes, array $extens): void
        {
            if ($this->store->failWith !== null) {
                throw $this->store->failWith;
            }

            foreach (array_keys($this->extensStartingWith($node, $prefixes)) as $exten) {
                unset($this->store->extens[$exten]);
            }

            foreach ($extens as $exten => $priorities) {
                $this->store->extens[$exten] = $priorities;
            }
        }

        public function extensStartingWith(PbxNode $node, array $prefixes): array
        {
            return array_filter(
                $this->store->extens,
                fn (string $exten): bool => array_any($prefixes, fn (string $prefix): bool => str_starts_with($exten, $prefix)),
                ARRAY_FILTER_USE_KEY,
            );
        }

        public function replaceMailbox(PbxNode $node, string $context, string $mailbox, ?array $box): void
        {
            unset($this->store->mailboxes["{$mailbox}@{$context}"]);

            if ($box !== null) {
                $this->store->mailboxes["{$mailbox}@{$context}"] = $box;
            }
        }

        public function mailbox(PbxNode $node, string $context, string $mailbox): ?array
        {
            return $this->store->mailboxes["{$mailbox}@{$context}"] ?? null;
        }
    });

    app()->instance(OpenSipsDbAdapter::class, new class($connections, $store) extends OpenSipsDbAdapter
    {
        public function __construct(NodeConnections $connections, private FakeNodeDatabases $store)
        {
            parent::__construct($connections);
        }

        public function replaceSubscriber(PbxNode $node, string $username, string $domain, ?array $subscriber): void
        {
            unset($this->store->subscribers["{$username}@{$domain}"]);

            if ($subscriber !== null) {
                $this->store->subscribers["{$username}@{$domain}"] = $subscriber;
            }
        }

        public function subscribers(PbxNode $node, string $username, string $domain): array
        {
            $row = $this->store->subscribers["{$username}@{$domain}"] ?? null;

            if ($row !== null && $this->store->corruptReadBack) {
                $row['ha1'] = 'tampered';
            }

            return $row === null ? [] : [$row];
        }

        public function replaceDomain(PbxNode $node, string $tenantCode, ?array $row): void
        {
            if ($this->store->failWith !== null) {
                throw $this->store->failWith;
            }

            unset($this->store->domains[$tenantCode]);

            if ($row !== null) {
                $this->store->domains[$tenantCode] = $row;
            }
        }

        public function domain(PbxNode $node, string $tenantCode): ?array
        {
            return $this->store->domains[$tenantCode] ?? null;
        }

        public function registrations(PbxNode $node, string $domain, array $usernames): array
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
 * The signed-in admin of a tenant with code 1020 on a node.
 *
 * @return array{Tenant, TenantPbxAssignment}
 */
function provisioningTenant(?TenantPbxAssignment $assignment = null): array
{
    $user = signIn();
    $assignment ??= TenantPbxAssignment::factory()->code('1020')->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $assignment->tenant_id, 'user_id' => $user->id, 'role' => TenantRole::Admin]);

    return [$assignment->tenant, $assignment];
}
