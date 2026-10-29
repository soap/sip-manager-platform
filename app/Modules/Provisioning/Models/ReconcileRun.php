<?php

namespace App\Modules\Provisioning\Models;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Enums\ReconcileStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One comparison of a node's rows with the desired state of every tenant on it (plan v0.2 §6.4):
 * what was missing, changed or unexpected, and how many targets were written again to fix it.
 *
 * @property int $id
 * @property int $pbx_node_id
 * @property ReconcileStatus $status
 * @property string $trigger
 * @property int $targets_checked
 * @property int $missing_count
 * @property int $changed_count
 * @property int $unexpected_count
 * @property int $fixed_count
 * @property list<array{kind: 'missing'|'changed'|'unexpected', target: string|null, item: string}>|null $drift
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'pbx_node_id', 'status', 'trigger', 'targets_checked', 'missing_count', 'changed_count',
    'unexpected_count', 'fixed_count', 'drift', 'error', 'started_at', 'finished_at',
])]
class ReconcileRun extends Model
{
    public const TRIGGER_SCHEDULE = 'schedule';

    /** A service of the node changed version, e.g. SIP Deployer reinstalled it. */
    public const TRIGGER_SYNC = 'sync';

    public const TRIGGER_MANUAL = 'manual';

    protected $attributes = [
        'status' => 'running',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReconcileStatus::class,
            'targets_checked' => 'integer',
            'missing_count' => 'integer',
            'changed_count' => 'integer',
            'unexpected_count' => 'integer',
            'fixed_count' => 'integer',
            'drift' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }
}
