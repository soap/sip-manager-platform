<?php

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * A tenant-owned model was queried or created with no tenant in the context. Code that works
 * across tenants on purpose must say so with withoutTenantScope().
 */
class MissingTenantContext extends LogicException
{
    public static function forModel(string $model): self
    {
        return new self("No tenant is set in the tenant context, so {$model} cannot be used. Set a tenant or use withoutTenantScope().");
    }
}
