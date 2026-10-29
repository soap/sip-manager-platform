<?php

namespace App\Modules\Provisioning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\Actions\RetryOperation;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Provisioning\OperationHistory;
use App\Modules\Provisioning\ProvisioningHealth;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's changes on their way to the PBX: every member may look, the people who manage
 * extensions may retry a failed one.
 */
class OperationController extends Controller
{
    public function index(Request $request, Tenant $tenant, OperationHistory $history, ProvisioningHealth $health): Response
    {
        $filter = in_array($request->query('status'), OperationHistory::FILTERS, true) ? $request->query('status') : 'all';

        return Inertia::render('tenants/Operations', [
            'filter' => $filter,
            'operations' => $history->latest($tenant->id, $filter),
            'canRetry' => Gate::allows('manageExtensions', $tenant),
            'provisioningStalled' => $health->stalledFor($tenant->id),
        ]);
    }

    public function retry(Tenant $tenant, string $operation, RetryOperation $retry): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $retry->handle(ProvisioningOperation::query()->where('tenant_id', $tenant->id)->findOrFail($operation));

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Writing it to the PBX again.']);

        return back();
    }
}
