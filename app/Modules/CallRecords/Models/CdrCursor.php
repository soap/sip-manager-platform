<?php

namespace App\Modules\CallRecords\Models;

use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How far the CDR of a node has been copied, and how the last sync went.
 *
 * @property int $id
 * @property int $pbx_node_id
 * @property Carbon|null $synced_until
 * @property Carbon|null $last_run_at
 * @property Carbon|null $last_success_at
 * @property int $last_count
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pbx_node_id', 'synced_until', 'last_run_at', 'last_success_at', 'last_count', 'last_error'])]
class CdrCursor extends Model
{
    protected function casts(): array
    {
        return [
            'synced_until' => 'datetime',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_count' => 'integer',
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
