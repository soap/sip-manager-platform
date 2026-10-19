<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        Gate::authorize('viewAuditLog', $tenant);

        return Inertia::render('tenants/AuditLog', [
            'entries' => AuditLog::query()
                ->where('tenant_id', $tenant->id)
                ->with('actor:id,name,email')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (AuditLog $entry): array => [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'actor' => $entry->actor?->name,
                    'summary' => $entry->summary ?? [],
                    'ip_address' => $entry->ip_address,
                    'created_at' => $entry->created_at->toIso8601String(),
                ]),
        ]);
    }
}
