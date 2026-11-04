<?php

namespace App\Modules\CallRecords\Models;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AMI event of a node that tells how a call went (plan §7), stored by StoreCallEvents. Not
 * scoped to a tenant: the tenant is a best guess and a call between tenants belongs to both.
 *
 * @property int $id
 * @property int $pbx_node_id
 * @property string $session
 * @property int $sequence
 * @property string|null $tenant_id
 * @property string $event
 * @property string|null $uniqueid
 * @property string|null $linkedid
 * @property string|null $channel
 * @property array<string, string> $fields
 * @property Carbon $occurred_at
 */
#[Fillable([
    'pbx_node_id', 'session', 'sequence', 'tenant_id', 'event', 'uniqueid', 'linkedid', 'channel',
    'fields', 'occurred_at',
])]
class CallEvent extends Model
{
    use MassPrunable;

    /** Days an event is kept (plan §7). */
    public const RETENTION_DAYS = 30;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'fields' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('occurred_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
