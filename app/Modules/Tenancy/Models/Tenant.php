<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Policies\TenantPolicy;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A customer of the platform. Every tenant-owned record carries its tenant_id.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'status', 'settings'])]
#[UseFactory(TenantFactory::class)]
#[UsePolicy(TenantPolicy::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_memberships')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * The tenant's primary PBX node.
     *
     * @return HasOne<TenantPbxAssignment, $this>
     */
    public function pbxAssignment(): HasOne
    {
        return $this->hasOne(TenantPbxAssignment::class);
    }

    /**
     * The tenant's prefix on its node: its numbers are the prefix followed by three digits.
     *
     * @return HasOne<TenantNumberRange, $this>
     */
    public function numberRange(): HasOne
    {
        return $this->hasOne(TenantNumberRange::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }
}
