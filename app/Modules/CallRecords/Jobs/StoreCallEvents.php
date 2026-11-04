<?php

namespace App\Modules\CallRecords\Jobs;

use App\Modules\CallRecords\Models\CallEvent;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stores a batch of AMI events the listener read from a node, on the events queue so a slow
 * database never holds the listener up. Each event is keyed by its connection and place in it,
 * so a batch stored twice (a retried job) adds nothing.
 *
 * The tenant comes from the event itself (the Tenant of a UserEvent, the tenant code OpenSIPS puts
 * in front of every dialed number, the 8<code> caller ID of a call let through from another
 * tenant), or else from an earlier event of the same call (linkedid). A Dial event of a call
 * without a calling channel (an originate) has only the Dest fields of the dialed one.
 */
class StoreCallEvents implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'events';

    public int $tries = 5;

    /**
     * @param  list<array{session: string, sequence: int, event: string, fields: array<string, string>, occurred_at: string}>  $events
     */
    public function __construct(public int $nodeId, public array $events)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
        if ($this->events === []) {
            return;
        }

        $tenantIdsByCode = Tenant::query()->pluck('id', 'code')->all();
        $linkedIds = array_values(array_unique(array_filter(array_map(
            fn (array $event): ?string => self::field($event['fields'], 'linkedid'),
            $this->events,
        ))));

        /** @var array<string, string> $tenantsByCall */
        $tenantsByCall = CallEvent::query()
            ->where('pbx_node_id', $this->nodeId)
            ->whereIn('linkedid', $linkedIds)
            ->whereNotNull('tenant_id')
            ->pluck('tenant_id', 'linkedid')
            ->all();

        $rows = [];

        foreach ($this->events as $event) {
            $linkedId = self::field($event['fields'], 'linkedid');
            $tenantId = self::tenantIdFromEvent($event['fields'], $tenantIdsByCode) ?? ($linkedId === null ? null : ($tenantsByCall[$linkedId] ?? null));

            if ($linkedId !== null && $tenantId !== null) {
                $tenantsByCall[$linkedId] ??= $tenantId;
            }

            $rows[] = [
                'pbx_node_id' => $this->nodeId,
                'session' => $event['session'],
                'sequence' => $event['sequence'],
                'tenant_id' => $tenantId,
                'event' => $event['event'],
                'uniqueid' => self::field($event['fields'], 'uniqueid'),
                'linkedid' => $linkedId,
                'channel' => self::field($event['fields'], 'channel'),
                'fields' => json_encode($event['fields'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => $event['occurred_at'],
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            CallEvent::query()->insertOrIgnore($chunk);
        }
    }

    /**
     * The id of the tenant the event itself names.
     *
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $tenantIdsByCode
     */
    private static function tenantIdFromEvent(array $fields, array $tenantIdsByCode): ?string
    {
        $candidates = [
            $fields['tenant'] ?? null,
            preg_match('/^(\d{4})/', self::field($fields, 'exten') ?? '', $match) === 1 ? $match[1] : null,
            preg_match('/^8(\d{4})\d/', self::field($fields, 'calleridnum') ?? '', $match) === 1 ? $match[1] : null,
        ];

        foreach ($candidates as $code) {
            if ($code !== null && isset($tenantIdsByCode[$code])) {
                return $tenantIdsByCode[$code];
            }
        }

        return null;
    }

    /**
     * A field of the event, or of the dialed channel when the event has no calling channel.
     *
     * @param  array<string, string>  $fields
     */
    private static function field(array $fields, string $name): ?string
    {
        return $fields[$name] ?? $fields["dest{$name}"] ?? null;
    }
}
