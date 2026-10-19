<?php

namespace App\Modules\Telephony\Models;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A number in use on a node and what answers it (an extension; later a ring group, queue or IVR).
 * The unique index on node and number keeps every kind of destination apart.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $pbx_node_id
 * @property string $number
 * @property string $owner_type
 * @property int $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'pbx_node_id', 'number', 'owner_type', 'owner_id'])]
class DialNumber extends Model
{
    use BelongsToTenant;

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }
}
