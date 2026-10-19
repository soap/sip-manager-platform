<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'tenant' => fn (): ?array => $this->tenant($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The tenant of the current page and the user's role there, for the tenant navigation.
     *
     * @return array{id: string, name: string, slug: string, role: string|null, canManageMembers: bool}|null
     */
    private function tenant(Request $request): ?array
    {
        $tenant = app(TenantContext::class)->tenant();
        $user = $request->user();

        if ($tenant === null || $user === null) {
            return null;
        }

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'role' => $user->roleIn($tenant)?->value,
            'canManageMembers' => $user->can('manageMembers', $tenant),
        ];
    }
}
