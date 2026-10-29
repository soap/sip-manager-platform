<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Tenancy\Enums\CrossTenantInbound;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Policies\TenantPolicy;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A customer of the platform. Every tenant-owned record carries its tenant_id.
 *
 * Phones of the tenant register in its own SIP domain, so its numbers are its own to choose (plan
 * D6). On its node the tenant is known by its code: the attrs of its OpenSIPS domain row, and the
 * prefix of each of its numbers in the shared Asterisk dialplan (code 1042, number 101 → 1042101).
 *
 * Other tenants may call its numbers as 8<code><number> when cross_tenant_inbound lets them and the
 * caller's calling profile allows it (plan D6/D7).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $code
 * @property string $sip_domain
 * @property TenantStatus $status
 * @property CrossTenantInbound $cross_tenant_inbound
 * @property string $auth_salt Salts the hashes of its authorization codes on the node; never changes
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'code', 'sip_domain', 'status', 'cross_tenant_inbound', 'settings'])]
#[Hidden(['auth_salt'])]
#[UseFactory(TenantFactory::class)]
#[UsePolicy(TenantPolicy::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids;

    public const CODE_DIGITS = 4;

    public const FIRST_CODE = 1000;

    public const LAST_CODE = 9999;

    protected $attributes = [
        'status' => 'active',
        'cross_tenant_inbound' => 'none',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->auth_salt ??= Str::random(16);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'cross_tenant_inbound' => CrossTenantInbound::class,
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
     * The tenants allowed to call this one while cross_tenant_inbound is "selected".
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function inboundAllowlist(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_inbound_allowlist', 'tenant_id', 'source_tenant_id')->withTimestamps();
    }

    /**
     * Tenants that take calls from the source (their side of plan D6): those taking calls from
     * every tenant, and those that selected the source.
     *
     * @return Builder<Tenant>
     */
    public static function acceptingCallsFrom(Tenant $source): Builder
    {
        return self::query()
            ->whereKeyNot($source->id)
            ->where(fn (Builder $query) => $query
                ->where('cross_tenant_inbound', CrossTenantInbound::All)
                ->orWhere(fn (Builder $selected) => $selected
                    ->where('cross_tenant_inbound', CrossTenantInbound::Selected)
                    ->whereHas('inboundAllowlist', fn (Builder $allowed) => $allowed->whereKey($source->id))));
    }

    /**
     * The code after the highest one given so far, so no tenant gets the code of a newer one;
     * null when every code is taken.
     */
    public static function nextCode(): ?string
    {
        $highest = self::query()->max('code');
        $next = $highest === null ? self::FIRST_CODE : (int) $highest + 1;

        return $next > self::LAST_CODE ? null : (string) $next;
    }

    /**
     * The SIP domain of a new tenant with the slug.
     */
    public static function sipDomainFor(string $slug): string
    {
        return $slug.'.'.config('services.sip.tenant_domain_suffix');
    }

    /**
     * The number as Asterisk knows it: the tenant code followed by the number.
     */
    public function routingNumber(string $number): string
    {
        return $this->code.$number;
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }
}
