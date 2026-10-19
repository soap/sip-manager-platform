<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the tenant in the TenantContext and fills tenant_id on create. Without a
 * tenant in the context, querying or creating fails instead of silently reaching every tenant;
 * code that works across tenants on purpose calls withoutTenantScope().
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public const TENANT_SCOPE = 'tenant';

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(self::TENANT_SCOPE, function (Builder $query): void {
            $context = app(TenantContext::class);

            if (! $context->has()) {
                throw MissingTenantContext::forModel(static::class);
            }

            $query->where($query->qualifyColumn('tenant_id'), $context->id());
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if (blank($model->getAttribute('tenant_id'))) {
                if (! $context->has()) {
                    throw MissingTenantContext::forModel(static::class);
                }

                $model->setAttribute('tenant_id', $context->id());
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * A query across every tenant, for platform admins and background work.
     *
     * @return Builder<static>
     */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(self::TENANT_SCOPE);
    }
}
