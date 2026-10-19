<?php

namespace App\Modules\Provisioning\Models;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Enums\RevisionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One change to bring to a node. It starts as a draft naming its target (e.g. "number:20101"); the
 * apply job compiles the target's desired state at that moment, writes it, reads it back, and fills
 * in what was written. Not tenant-scoped: the apply job works across tenants.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int $pbx_node_id
 * @property int $revision_number
 * @property string $target
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property string|null $checksum
 * @property array<string, mixed>|null $compiled
 * @property RevisionStatus $status
 * @property array<string, mixed>|null $validation_report
 * @property array<string, mixed>|null $apply_result
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'tenant_id', 'pbx_node_id', 'revision_number', 'target', 'aggregate_type', 'aggregate_id',
    'checksum', 'compiled', 'status', 'validation_report', 'apply_result',
])]
class ConfigRevision extends Model
{
    public const NUMBER_TARGET_PREFIX = 'number:';

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'aggregate_id' => 'integer',
            'compiled' => 'array',
            'status' => RevisionStatus::class,
            'validation_report' => 'array',
            'apply_result' => 'array',
        ];
    }

    public static function numberTarget(string $number): string
    {
        return self::NUMBER_TARGET_PREFIX.$number;
    }

    /**
     * The number this revision writes, or null for another kind of target.
     */
    public function targetNumber(): ?string
    {
        return str_starts_with($this->target, self::NUMBER_TARGET_PREFIX) ? substr($this->target, strlen(self::NUMBER_TARGET_PREFIX)) : null;
    }

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }

    /**
     * @return HasMany<ProvisioningOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(ProvisioningOperation::class);
    }
}
