<?php

namespace App\Modules\CallRecords\Actions;

use App\Modules\CallRecords\CallParties;
use App\Modules\CallRecords\Enums\CallStatus;
use App\Modules\CallRecords\Models\CallRecord;
use App\Modules\CallRecords\Models\CdrCursor;
use App\Modules\Infrastructure\Asterisk\CdrAdapter;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\ErrorClassifier;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Copies the CDR rows of a node into call_records (plan §7). Each sync reads the rows that ended
 * since the cursor, starting LAG_MINUTES earlier because Asterisk writes a row when the call is
 * torn down, which can be a little after its end; rows read twice are updated, not duplicated.
 * A long backlog (the first sync of a node) is read a window at a time.
 */
class SyncCallRecords
{
    public const LAG_MINUTES = 5;

    /** Hours of CDR read in one query. */
    public const WINDOW_HOURS = 6;

    /** Windows read in one sync; the next sync carries on from there. */
    public const MAX_WINDOWS = 40;

    public function __construct(private CdrAdapter $cdr, private ErrorClassifier $errors) {}

    /**
     * Whether the node has an Asterisk whose CDR can be read.
     */
    public static function canSync(PbxNode $node): bool
    {
        return $node->removed_at === null && $node->hasAsterisk() && isset($node->endpoints['asterisk_db']);
    }

    public function handle(PbxNode $node): CdrCursor
    {
        $cursor = CdrCursor::query()->firstOrNew(['pbx_node_id' => $node->id]);
        $cursor->fill(['last_run_at' => now()]);

        try {
            $copied = $this->copy($node, $cursor);

            $cursor->fill(['last_count' => $copied, 'last_success_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            report($exception);

            $cursor->last_error = $this->errors->classify($exception)['message'];
        }

        $cursor->save();

        return $cursor;
    }

    /**
     * Copies window after window up to now and moves the cursor along; returns the rows copied.
     */
    private function copy(PbxNode $node, CdrCursor $cursor): int
    {
        $now = CarbonImmutable::now();
        $from = $cursor->synced_until === null
            ? $this->cdr->oldestEnd($node)
            : CarbonImmutable::parse($cursor->synced_until)->subMinutes(self::LAG_MINUTES);

        if ($from === null) {
            $cursor->fill(['synced_until' => $now]);

            return 0;
        }

        $tenantIdsByCode = Tenant::query()->pluck('id', 'code')->all();
        $copied = 0;

        for ($window = 0; $window < self::MAX_WINDOWS && $from < $now; $window++) {
            $until = $from->addHours(self::WINDOW_HOURS)->min($now);
            $copied += $this->store($node, $this->cdr->endedBetween($node, $from, $until), $tenantIdsByCode);

            $cursor->fill(['synced_until' => $until]);
            $from = $until;
        }

        return $copied;
    }

    /**
     * @param  list<array{uniqueid: string, sequence: int, linkedid: string|null, src: string|null, dst: string|null, clid: string|null, channel: string|null, dstchannel: string|null, lastapp: string|null, lastdata: string|null, disposition: string|null, accountcode: string|null, userfield: string|null, duration: int, billsec: int, start: CarbonImmutable|null, answer: CarbonImmutable|null, end: CarbonImmutable}>  $rows
     * @param  array<string, string>  $tenantIdsByCode
     */
    private function store(PbxNode $node, array $rows, array $tenantIdsByCode): int
    {
        if ($rows === []) {
            return 0;
        }

        $codeIds = array_filter(array_map(fn (array $row): ?int => CallParties::authorizationCodeId($row['userfield']), $rows));
        $codeTenants = AuthorizationCode::withoutTenantScope()->whereKey($codeIds)->pluck('tenant_id', 'id')->all();
        $now = now();

        $records = array_map(function (array $row) use ($node, $tenantIdsByCode, $codeTenants, $now): array {
            $parties = CallParties::from($row['src'], $row['dst'], $tenantIdsByCode);
            $codeId = CallParties::authorizationCodeId($row['userfield']);

            return [
                'pbx_node_id' => $node->id,
                'uniqueid' => $row['uniqueid'],
                'sequence' => $row['sequence'],
                'linkedid' => $row['linkedid'],
                ...$parties,
                'status' => CallStatus::fromCdr($row['disposition'], $row['dst'])->value,
                // only a code of the caller's own tenant; a deleted one is gone from the table
                'authorization_code_id' => $codeId !== null && isset($codeTenants[$codeId]) && $codeTenants[$codeId] === $parties['caller_tenant_id'] ? $codeId : null,
                'started_at' => $row['start'],
                'answered_at' => $row['answer'],
                'ended_at' => $row['end'],
                'duration' => $row['duration'],
                'billsec' => $row['billsec'],
                'src' => $row['src'],
                'dst' => $row['dst'],
                'clid' => $row['clid'],
                'channel' => $row['channel'],
                'dstchannel' => $row['dstchannel'],
                'lastapp' => $row['lastapp'],
                'lastdata' => $row['lastdata'],
                'disposition' => $row['disposition'],
                'accountcode' => $row['accountcode'],
                'userfield' => $row['userfield'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);

        foreach (array_chunk($records, 500) as $chunk) {
            CallRecord::query()->upsert(
                $chunk,
                ['pbx_node_id', 'uniqueid', 'sequence'],
                array_values(array_diff(array_keys($chunk[0]), ['pbx_node_id', 'uniqueid', 'sequence', 'created_at'])),
            );
        }

        return count($records);
    }
}
