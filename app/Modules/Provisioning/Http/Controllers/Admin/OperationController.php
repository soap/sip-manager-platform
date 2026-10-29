<?php

namespace App\Modules\Provisioning\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\Actions\RetryOperation;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Provisioning\OperationHistory;
use App\Modules\Provisioning\ProvisioningHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The changes of every tenant on their way to the PBX, for the platform admin.
 */
class OperationController extends Controller
{
    public function index(Request $request, OperationHistory $history, ProvisioningHealth $health): Response
    {
        $filter = in_array($request->query('status'), OperationHistory::FILTERS, true) ? $request->query('status') : 'all';

        return Inertia::render('admin/operations/Index', [
            'filter' => $filter,
            'operations' => $history->latest(null, $filter),
            'provisioningStalled' => $health->stalledAnywhere(),
        ]);
    }

    public function retry(string $operation, RetryOperation $retry): RedirectResponse
    {
        $retry->handle(ProvisioningOperation::query()->findOrFail($operation));

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Writing it to the PBX again.']);

        return back();
    }
}
