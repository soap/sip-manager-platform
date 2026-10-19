<?php

namespace App\Modules\Tenancy\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Http\Requests\StoreTenantRequest;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Tenant::class);

        return Inertia::render('admin/tenants/Index', [
            'tenants' => Tenant::query()
                ->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn (Tenant $tenant): array => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status->value,
                    'members_count' => $tenant->users_count,
                    'created_at' => $tenant->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(StoreTenantRequest $request, CreateTenant $createTenant): RedirectResponse
    {
        $tenant = $createTenant->handle(
            $request->string('name')->toString(),
            $request->string('slug')->toString(),
            $request->string('owner_name')->toString(),
            $request->string('owner_email')->toString(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Tenant {$tenant->name} created. The owner gets an email to set a password."]);

        return redirect()->route('admin.tenants.index');
    }
}
