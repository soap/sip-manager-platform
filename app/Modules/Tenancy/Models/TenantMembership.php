<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Enums\TenantRole;
use Database\Factories\TenantMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's membership of a tenant, with their role there.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $user_id
 * @property TenantRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'user_id', 'role'])]
#[UseFactory(TenantMembershipFactory::class)]
class TenantMembership extends Model
{
    /** @use HasFactory<TenantMembershipFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
