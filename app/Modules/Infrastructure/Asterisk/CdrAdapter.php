<?php

namespace App\Modules\Infrastructure\Asterisk;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\NodeConnections;
use Carbon\CarbonImmutable;

/**
 * The cdr table of the Asterisk database on a node, which cdr_adaptive_odbc fills as calls end.
 * The table (from Asterisk's Alembic scripts) has no key and no index, so rows are read by the
 * time they ended; its datetimes are in the node's local time (services.sip.cdr_timezone).
 */
class CdrAdapter
{
    public function __construct(private NodeConnections $connections) {}

    /**
     * When the oldest row ended, or null when the table is empty.
     */
    public function oldestEnd(PbxNode $node): ?CarbonImmutable
    {
        $end = $this->connections->asteriskDatabase($node)->table('cdr')->min('end');

        return $end === null ? null : $this->time((string) $end);
    }

    /**
     * The rows that ended at or after $from and before $until, oldest first.
     *
     * @return list<array{uniqueid: string, sequence: int, linkedid: string|null, src: string|null, dst: string|null, clid: string|null, channel: string|null, dstchannel: string|null, lastapp: string|null, lastdata: string|null, disposition: string|null, accountcode: string|null, userfield: string|null, duration: int, billsec: int, start: CarbonImmutable|null, answer: CarbonImmutable|null, end: CarbonImmutable}>
     */
    public function endedBetween(PbxNode $node, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = [];

        $this->connections->asteriskDatabase($node)
            ->table('cdr')
            ->where('end', '>=', $this->nodeTime($from))
            ->where('end', '<', $this->nodeTime($until))
            ->whereNotNull('uniqueid')
            ->orderBy('end')
            ->get()
            ->each(function (object $row) use (&$rows): void {
                $rows[] = [
                    'uniqueid' => (string) $row->uniqueid,
                    'sequence' => (int) ($row->sequence ?? 0),
                    'linkedid' => $row->linkedid,
                    'src' => $row->src,
                    'dst' => $row->dst,
                    'clid' => $row->clid,
                    'channel' => $row->channel,
                    'dstchannel' => $row->dstchannel,
                    'lastapp' => $row->lastapp,
                    'lastdata' => $row->lastdata,
                    'disposition' => $row->disposition,
                    'accountcode' => $row->accountcode,
                    'userfield' => $row->userfield,
                    'duration' => (int) ($row->duration ?? 0),
                    'billsec' => (int) ($row->billsec ?? 0),
                    'start' => $row->start === null ? null : $this->time((string) $row->start),
                    'answer' => $row->answer === null ? null : $this->time((string) $row->answer),
                    'end' => $this->time((string) $row->end),
                ];
            });

        return $rows;
    }

    /**
     * A datetime of the table as UTC.
     */
    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $this->timezone())->utc();
    }

    /**
     * A time as the table writes it.
     */
    private function nodeTime(CarbonImmutable $time): string
    {
        return $time->setTimezone($this->timezone())->format('Y-m-d H:i:s');
    }

    private function timezone(): string
    {
        return (string) config('services.sip.cdr_timezone', 'UTC');
    }
}
