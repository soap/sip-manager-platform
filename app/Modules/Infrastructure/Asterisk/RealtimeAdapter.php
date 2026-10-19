<?php

namespace App\Modules\Infrastructure\Asterisk;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;

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
     * The realtime context of the node's manifest (dialplan.managed_context), "managed" by default.
     */
    public function context(PbxNode $node): string
    {
        return $node->dialplan['managed_context'] ?? self::MANAGED_CONTEXT;
    }
}
