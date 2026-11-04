<?php

namespace App\Modules\Telephony\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\PickupGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Extensions of the tenant that answer each other's ringing phones by dialing the pickup feature
 * code (*8). It owns no number: a ringing member carries the group's pickup mark, and each member
 * gets a pickup exten in the tenant's calling dialplan (see CallingCompiler). An extension is in at
 * most one group.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'name', 'version'])]
#[UseFactory(PickupGroupFactory::class)]
class PickupGroup extends Model
{
    /** @use HasFactory<PickupGroupFactory> */
    use BelongsToTenant, HasFactory;

    /** The morph name in config_revisions and provisioning_operations (registered in AppServiceProvider). */
    public const MORPH = 'pickup_group';

    protected $attributes = [
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Extension, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Extension::class, 'pickup_group_members')
            ->withoutGlobalScope(Extension::TENANT_SCOPE)
            ->withTimestamps()
            ->orderBy('extension_number');
    }
}
