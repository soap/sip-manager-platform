<?php

namespace App\Modules\Infrastructure\Asterisk;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;
use Illuminate\Database\Query\Builder;

/**
 * Dialplan rows of the Asterisk realtime database on a node. Calls from OpenSIPS enter
 * from-opensips, which switches to the realtime context "managed"; pbx_realtime reads the rows on
 * every call, so a write needs no reload. This application owns the extens it writes there.
 */
class RealtimeAdapter
{
    public const MANAGED_CONTEXT = 'managed';

    public function __construct(private NodeConnections $connections) {}

    /**
     * Makes the exten's rows in the managed context exactly the given priorities (none removes it).
     *
     * @param  list<array{priority: int, app: string, appdata: string}>  $priorities
     */
    public function replaceExten(PbxNode $node, string $exten, array $priorities): void
    {
        $database = $this->connections->asteriskDatabase($node);
        $context = $this->context($node);

        $database->transaction(function () use ($database, $context, $exten, $priorities): void {
            $database->table('extensions')->where('context', $context)->where('exten', $exten)->delete();

            if ($priorities !== []) {
                $database->table('extensions')->insert(array_map(
                    fn (array $priority): array => ['context' => $context, 'exten' => $exten, ...$priority],
                    $priorities,
                ));
            }
        });
    }

    /**
     * @return list<array{priority: int, app: string, appdata: string}>
     */
    public function exten(PbxNode $node, string $exten): array
    {
        /** @var list<array{priority: int, app: string, appdata: string}> */
        return $this->connections->asteriskDatabase($node)
            ->table('extensions')
            ->where('context', $this->context($node))
            ->where('exten', $exten)
            ->orderBy('priority')
            ->get(['priority', 'app', 'appdata'])
            ->map(fn (object $row): array => ['priority' => (int) $row->priority, 'app' => (string) $row->app, 'appdata' => (string) $row->appdata])
            ->all();
    }

    /**
     * Makes the extens whose names start with one of the prefixes exactly the given ones: those
     * left out are removed. For dialplan owned as a whole, e.g. a tenant's calling extens.
     *
     * @param  list<string>  $prefixes
     * @param  array<string, list<array{priority: int, app: string, appdata: string}>>  $extens
     */
    public function replaceExtensStartingWith(PbxNode $node, array $prefixes, array $extens): void
    {
        $database = $this->connections->asteriskDatabase($node);
        $context = $this->context($node);

        $rows = [];

        foreach ($extens as $exten => $priorities) {
            foreach ($priorities as $priority) {
                $rows[] = ['context' => $context, 'exten' => (string) $exten, ...$priority];
            }
        }

        $database->transaction(function () use ($database, $context, $prefixes, $rows): void {
            $this->startingWith($database->table('extensions')->where('context', $context), $prefixes)->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                $database->table('extensions')->insert($chunk);
            }
        });
    }

    /**
     * @param  list<string>  $prefixes
     * @return array<string, list<array{priority: int, app: string, appdata: string}>>
     */
    public function extensStartingWith(PbxNode $node, array $prefixes): array
    {
        $extens = [];

        $this->startingWith($this->connections->asteriskDatabase($node)->table('extensions')->where('context', $this->context($node)), $prefixes)
            ->orderBy('exten')
            ->orderBy('priority')
            ->get(['exten', 'priority', 'app', 'appdata'])
            ->each(function (object $row) use (&$extens): void {
                $extens[(string) $row->exten][] = ['priority' => (int) $row->priority, 'app' => (string) $row->app, 'appdata' => (string) $row->appdata];
            });

        return $extens;
    }

    /**
     * The name of every exten in the managed context, for finding rows nobody asked for.
     *
     * @return list<string>
     */
    public function extenNames(PbxNode $node): array
    {
        /** @var list<string> */
        return $this->connections->asteriskDatabase($node)
            ->table('extensions')
            ->where('context', $this->context($node))
            ->distinct()
            ->orderBy('exten')
            ->pluck('exten')
            ->map(fn (mixed $exten): string => (string) $exten)
            ->all();
    }

    /**
     * Prefixes are compared with substr() rather than LIKE, where "_" would match any character.
     *
     * @param  list<string>  $prefixes
     */
    private function startingWith(Builder $query, array $prefixes): Builder
    {
        return $query->where(function (Builder $query) use ($prefixes): void {
            foreach ($prefixes as $prefix) {
                $query->orWhereRaw('substr(exten, 1, ?) = ?', [strlen($prefix), $prefix]);
            }
        });
    }

    /**
     * Makes the voicemail row of mailbox@context exactly the given one, or removes it for null;
     * app_voicemail reads the table on every lookup. Each tenant has its own context, so the same
     * mailbox in other contexts belongs to other tenants. Recorded messages stay on disk either way.
     *
     * @param  array{context: string, mailbox: string, password: string, fullname: string}|null  $box
     */
    public function replaceMailbox(PbxNode $node, string $context, string $mailbox, ?array $box): void
    {
        $database = $this->connections->asteriskDatabase($node);

        $database->transaction(function () use ($database, $context, $mailbox, $box): void {
            $database->table('voicemail')->where('context', $context)->where('mailbox', $mailbox)->delete();

            if ($box !== null) {
                $database->table('voicemail')->insert($box);
            }
        });
    }

    /**
     * @return array{context: string, mailbox: string, password: string, fullname: string}|null
     */
    public function mailbox(PbxNode $node, string $context, string $mailbox): ?array
    {
        $row = $this->connections->asteriskDatabase($node)
            ->table('voicemail')
            ->where('context', $context)
            ->where('mailbox', $mailbox)
            ->first(['context', 'mailbox', 'password', 'fullname']);

        return $row === null ? null : [
            'context' => (string) $row->context,
            'mailbox' => (string) $row->mailbox,
            'password' => (string) $row->password,
            'fullname' => (string) $row->fullname,
        ];
    }

    /**
     * The realtime context of the node's manifest (dialplan.managed_context), "managed" by default.
     */
    public function context(PbxNode $node): string
    {
        return $node->dialplan['managed_context'] ?? self::MANAGED_CONTEXT;
    }
}
