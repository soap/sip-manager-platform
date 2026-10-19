<?php

namespace App\Modules\Telephony\Models;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\ExtensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's internal number. The number lies in the tenant's block on its node and becomes the
 * SIP username there, so it is unique per tenant and per node.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $pbx_node_id
 * @property string $extension_number
 * @property string $display_name
 * @property ExtensionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'pbx_node_id', 'extension_number', 'display_name', 'status'])]
#[UseFactory(ExtensionFactory::class)]
class Extension extends Model
{
    /** @use HasFactory<ExtensionFactory> */
    use BelongsToTenant, HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtensionStatus::class,
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
