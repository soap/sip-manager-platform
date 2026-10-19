<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform pages are for platform admins with two-factor authentication confirmed; an admin
 * without it is sent to the security settings to turn it on.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->is_platform_admin) {
            abort(403);
        }

        if (! $user->hasConfirmedTwoFactor()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Turn on two-factor authentication to open the platform pages.']);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
