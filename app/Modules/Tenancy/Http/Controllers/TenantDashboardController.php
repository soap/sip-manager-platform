<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Inertia\Inertia;
use Inertia\Response;

class TenantDashboardController extends Controller
{
    public function show(Tenant $tenant): Response
    {
        return Inertia::render('tenants/Dashboard', [
            'membersCount' => TenantMembership::query()->count(),
        ]);
    }
}
