<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Models\User;
use App\Modules\Tenancy\Models\ApiToken;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant an API token was issued in as the current tenant. The tenant never comes from
 * the request: a token of one tenant cannot reach another. The token's user must still belong to
 * the tenant, and the tenant must be active.
 */
class SetApiTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user === null || ! $token instanceof ApiToken || $token->tenant_id === null) {
            abort(403, 'Use an API token issued on a tenant\'s API tokens page.');
        }

        $tenant = $token->tenant;

        if ($tenant === null || $user->cannot('view', $tenant)) {
            abort(403, 'The user of this token no longer belongs to its tenant.');
        }

        if (! $tenant->isActive()) {
            abort(403, 'This tenant is suspended.');
        }

        $this->context->set($tenant);

        try {
            return $next($request);
        } finally {
            $this->context->set(null);
        }
    }
}
