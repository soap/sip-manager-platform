<?php

namespace App\Modules\Nodes\Models;

use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\TenantPbxAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The node a tenant's phones live on (its primary PBX) and the block of numbers the tenant owns
 * there. Every tenant on a node shares the node's SIP domain and dialplan, so numbers must be
 * unique per node: the blocks of one node never overlap. Platform admins manage assignments; it is
 * not tenant-scoped.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $sip_node_id
 * @property int $number_range_start
 * @property int $number_range_end
 * @property AssignmentStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'sip_node_id', 'number_range_start', 'number_range_end', 'status'])]
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
            'number_range_start' => 'integer',
            'number_range_end' => 'integer',
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
     * @return BelongsTo<SipNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(SipNode::class, 'sip_node_id');
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

    /**
     * Assignments on the node whose block shares a number with start..end.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, int $nodeId, int $start, int $end): void
    {
        $query->where('sip_node_id', $nodeId)
            ->where('number_range_start', '<=', $end)
            ->where('number_range_end', '>=', $start);
    }

    public function contains(string $number): bool
    {
        return ctype_digit($number) && (int) $number >= $this->number_range_start && (int) $number <= $this->number_range_end;
    }

    public function isActive(): bool
    {
        return $this->status === AssignmentStatus::Active;
    }

    public function rangeLabel(): string
    {
        return "{$this->number_range_start}–{$this->number_range_end}";
    }
}
