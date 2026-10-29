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
 * One change to bring to a node. It starts as a draft naming its target: a number as Asterisk knows
 * it, tenant code first (e.g. "number:1042101"), a tenant's SIP domain ("domain:1042") or its
 * calling dialplan ("calling:1042"). The apply job compiles the target's desired state at that
 * moment, writes it, reads it back, and fills in what was written. Not tenant-scoped: the apply job
 * works across tenants.
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

    public const DOMAIN_TARGET_PREFIX = 'domain:';

    public const CALLING_TARGET_PREFIX = 'calling:';

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

    /**
     * @param  string  $routingNumber  tenant code and number, e.g. 1042101
     */
    public static function numberTarget(string $routingNumber): string
    {
        return self::NUMBER_TARGET_PREFIX.$routingNumber;
    }

    public static function domainTarget(string $tenantCode): string
    {
        return self::DOMAIN_TARGET_PREFIX.$tenantCode;
    }

    /**
     * A tenant's calling dialplan (plan D7): cross-tenant calls, outbound categories, authorization codes.
     */
    public static function callingTarget(string $tenantCode): string
    {
        return self::CALLING_TARGET_PREFIX.$tenantCode;
    }

    /**
     * The number this revision writes, tenant code first, or null for another kind of target.
     */
    public function targetNumber(): ?string
    {
        return str_starts_with($this->target, self::NUMBER_TARGET_PREFIX) ? substr($this->target, strlen(self::NUMBER_TARGET_PREFIX)) : null;
    }

    /**
     * The code of the tenant whose SIP domain this revision writes, or null for another target.
     */
    public function targetDomainCode(): ?string
    {
        return str_starts_with($this->target, self::DOMAIN_TARGET_PREFIX) ? substr($this->target, strlen(self::DOMAIN_TARGET_PREFIX)) : null;
    }

    /**
     * The code of the tenant whose calling dialplan this revision writes, or null for another target.
     */
    public function targetCallingCode(): ?string
    {
        return str_starts_with($this->target, self::CALLING_TARGET_PREFIX) ? substr($this->target, strlen(self::CALLING_TARGET_PREFIX)) : null;
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
