<?php

namespace App\Modules\CallRecords\Models;

use App\Modules\CallRecords\Enums\AmiConnectionStatus;
use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The AMI event listener's connection to a node (plan §7), and which listener process holds it.
 *
 * @property int $id
 * @property int $pbx_node_id
 * @property string|null $listener
 * @property Carbon|null $heartbeat_at
 * @property AmiConnectionStatus $status
 * @property Carbon|null $connected_at
 * @property Carbon|null $disconnected_at
 * @property Carbon|null $last_event_at
 * @property int $events_count
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'pbx_node_id', 'listener', 'heartbeat_at', 'status', 'connected_at', 'disconnected_at',
    'last_event_at', 'events_count', 'last_error',
])]
class AmiConnection extends Model
{
    /** Seconds without a heartbeat after which the listener is taken for dead. */
    public const STALE_SECONDS = 90;

    protected $attributes = [
        'status' => 'disconnected',
        'events_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'heartbeat_at' => 'datetime',
            'status' => AmiConnectionStatus::class,
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_event_at' => 'datetime',
            'events_count' => 'integer',
        ];
    }

    /**
     * Takes the node for the listener unless another listener holds it and is still alive, so two
     * listener processes never store the same events twice.
     */
    public static function claim(PbxNode $node, string $listener): bool
    {
        $connection = self::query()->firstOrCreate(['pbx_node_id' => $node->id]);

        return self::query()
            ->whereKey($connection->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('listener')
                ->orWhere('listener', $listener)
                ->orWhereNull('heartbeat_at')
                ->orWhere('heartbeat_at', '<', now()->subSeconds(self::STALE_SECONDS)))
            ->update(['listener' => $listener, 'heartbeat_at' => now()]) === 1;
    }

    /**
     * Whether a listener holds the node and has been heard from lately.
     */
    public function isAlive(): bool
    {
        return $this->listener !== null
            && $this->heartbeat_at !== null
            && $this->heartbeat_at->gte(now()->subSeconds(self::STALE_SECONDS));
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }
}
