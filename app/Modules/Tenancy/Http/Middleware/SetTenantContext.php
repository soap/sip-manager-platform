<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant of a /t/{tenant} route as the current tenant. Someone who is not a member gets
 * 404, so they cannot tell whether the tenant exists. A suspended tenant is open to platform
 * admins only.
 */
class SetTenantContext
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');

        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::query()->where('slug', (string) $tenant)->first();
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($tenant === null || $user === null || $user->cannot('view', $tenant)) {
            abort(404);
        }

        if (! $tenant->isActive() && ! $user->is_platform_admin) {
            abort(403, 'This tenant is suspended.');
        }

        $this->context->set($tenant);
        $request->route()?->setParameter('tenant', $tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        try {
            return $next($request);
        } finally {
            $this->context->set(null);
        }
    }
}
