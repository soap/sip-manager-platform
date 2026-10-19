<?php

namespace App\Modules\Nodes\Models;

use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\TenantPbxAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * The node a tenant's phones live on: its primary PBX (one per tenant in the MVP; a node hosts
 * many tenants). The tenant's numbers there are its TenantNumberRange. Platform admins manage
 * assignments; they are not tenant-scoped.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $pbx_node_id
 * @property AssignmentStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'pbx_node_id', 'status'])]
#[UseFactory(TenantPbxAssignmentFactory::class)]
class TenantPbxAssignment extends Model
{
    /** @use HasFactory<TenantPbxAssignmentFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }

    /**
     * The tenant's prefix on this node.
     *
     * @return HasOne<TenantNumberRange, $this>
     */
    public function numberRange(): HasOne
    {
        return $this->hasOne(TenantNumberRange::class, 'tenant_id', 'tenant_id');
    }

    /**
     * The tenant's extensions; they all live on this assignment's node.
     *
     * @return HasMany<Extension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class, 'tenant_id', 'tenant_id')->withoutGlobalScope(Extension::TENANT_SCOPE);
    }

    public function isActive(): bool
    {
        return $this->status === AssignmentStatus::Active;
    }
}
