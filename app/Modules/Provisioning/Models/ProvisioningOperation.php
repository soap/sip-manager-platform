<?php

namespace App\Modules\Provisioning\Models;

use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Enums\OperationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a person or client asked for and how far it got: pending → running → applied, or retrying
 * with backoff after a passing failure, or failed for good. Its id is the operation_id the API
 * returns with 202.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property OperationType $type
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property string|null $idempotency_key
 * @property int $config_revision_id
 * @property OperationStatus $status
 * @property int $attempts
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'tenant_id', 'type', 'aggregate_type', 'aggregate_id', 'idempotency_key', 'config_revision_id',
    'status', 'attempts', 'error_code', 'error_message', 'next_attempt_at',
])]
class ProvisioningOperation extends Model
{
    use HasUlids;

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => OperationType::class,
            'aggregate_id' => 'integer',
            'status' => OperationStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ConfigRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(ConfigRevision::class, 'config_revision_id');
    }
}
