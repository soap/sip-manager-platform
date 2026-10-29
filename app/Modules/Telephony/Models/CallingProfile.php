<?php

namespace App\Modules\Telephony\Models;

use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Enums\CallPermission;
use App\Modules\Telephony\Enums\CrossTenantScope;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\CallingProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where the extensions using it may call (plan D7): a CallPermission per CallCategory, and for
 * calls to other tenants every tenant that takes our calls or only the selected ones. Extensions
 * without a profile use the tenant's default.
 *
 * On the node each extension gets the exten <code>p<number> whose Return() lists the flags of its
 * profile, e.g. "/l/M/xa/": landline allowed, mobile with a code, every other tenant allowed.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property array<string, string> $categories category value => permission value
 * @property CrossTenantScope $cross_tenant_scope
 * @property bool $is_default
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'name', 'categories', 'cross_tenant_scope', 'is_default', 'version'])]
#[UseFactory(CallingProfileFactory::class)]
class CallingProfile extends Model
{
    /** @use HasFactory<CallingProfileFactory> */
    use BelongsToTenant, HasFactory;

    public const MORPH = 'calling_profile';

    /** Each selected tenant takes up to 7 characters of a Return() of at most 256. */
    public const MAX_DESTINATIONS = 30;

    protected $attributes = [
        'categories' => '{}',
        'cross_tenant_scope' => 'all',
        'is_default' => false,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'cross_tenant_scope' => CrossTenantScope::class,
            'is_default' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * The tenants it may call when its scope is "selected".
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'calling_profile_destinations', 'calling_profile_id', 'destination_tenant_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Extension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }

    public function permissionFor(CallCategory $category): CallPermission
    {
        return CallPermission::tryFrom($this->categories[$category->value] ?? '') ?? CallPermission::Deny;
    }

    /**
     * The flags of the permission exten, between slashes: lowercase allowed, uppercase with a code;
     * other tenants as "xa" (all) or "x<code>" for each selected one.
     */
    public function flags(): string
    {
        $flags = [];

        foreach (CallCategory::cases() as $category) {
            $permission = $this->permissionFor($category);

            if ($permission === CallPermission::Deny) {
                continue;
            }

            $flag = $permission === CallPermission::Code ? strtoupper($category->flag()) : $category->flag();

            if ($category !== CallCategory::CrossTenant) {
                $flags[] = $flag;
            } elseif ($this->cross_tenant_scope === CrossTenantScope::All) {
                $flags[] = $flag.($permission === CallPermission::Code ? 'A' : 'a');
            } else {
                array_push($flags, ...$this->destinations->sortBy('code')->map(fn (Tenant $tenant): string => $flag.$tenant->code)->values()->all());
            }
        }

        return '/'.implode('/', $flags).($flags === [] ? '' : '/');
    }
}
