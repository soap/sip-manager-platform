<?php

namespace App\Modules\Telephony\Models;

use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Enums\RingStrategy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\RingGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A number of the tenant that rings several of its extensions (plan v0.2 D5), all at once or one
 * after another, and sends an unanswered call to its failover. Registered in dial_numbers like an
 * extension, so the two never share a number.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $pbx_node_id
 * @property string $number
 * @property string $name
 * @property RingStrategy $strategy
 * @property int $ring_seconds
 * @property FailoverType $failover_type
 * @property int|null $failover_id
 * @property ExtensionStatus $status Active or disabled, like an extension
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'pbx_node_id', 'number', 'name', 'strategy', 'ring_seconds', 'failover_type', 'failover_id', 'status', 'version'])]
#[UseFactory(RingGroupFactory::class)]
class RingGroup extends Model
{
    /** @use HasFactory<RingGroupFactory> */
    use BelongsToTenant, HasFactory;

    /** The morph name in dial_numbers and config_revisions (registered in AppServiceProvider). */
    public const MORPH = 'ring_group';

    /** A ring_all Dial() lists every member in one dialplan row of at most 256 characters. */
    public const MAX_MEMBERS = 10;

    public const MIN_RING_SECONDS = 5;

    public const MAX_RING_SECONDS = 120;

    protected $attributes = [
        'strategy' => 'ring_all',
        'ring_seconds' => 20,
        'failover_type' => 'hangup',
        'status' => 'active',
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'strategy' => RingStrategy::class,
            'ring_seconds' => 'integer',
            'failover_type' => FailoverType::class,
            'failover_id' => 'integer',
            'status' => ExtensionStatus::class,
            'version' => 'integer',
        ];
    }

    /**
     * The member extensions in ringing order.
     *
     * @return BelongsToMany<Extension, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Extension::class, 'ring_group_members')
            ->withoutGlobalScope(Extension::TENANT_SCOPE)
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function isActive(): bool
    {
        return $this->status === ExtensionStatus::Active;
    }
}
