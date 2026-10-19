<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The tenants the user belongs to, to pick one from.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'tenants' => $user->tenants()
                ->orderBy('name')
                ->get()
                ->map(fn (Tenant $tenant): array => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status->value,
                    'role' => $tenant->getRelationValue('pivot')->getAttribute('role'),
                ]),
        ]);
    }
}
