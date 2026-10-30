<?php

namespace App\Modules\CallRecords\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CallRecords\CallHistory;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's call history from the CDR of its node; every member may look.
 */
class CallRecordController extends Controller
{
    public function index(Request $request, Tenant $tenant, CallHistory $history): Response
    {
        $cursor = $request->query('cursor');

        return Inertia::render('tenants/Calls', [
            ...$history->page($tenant, is_string($cursor) ? $cursor : null),
            'paged' => is_string($cursor),
        ]);
    }
}
