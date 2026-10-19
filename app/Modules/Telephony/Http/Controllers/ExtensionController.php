<?php

namespace App\Modules\Telephony\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Telephony\Actions\ManageExtensions;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Http\Requests\StoreExtensionRequest;
use App\Modules\Telephony\Http\Requests\UpdateExtensionRequest;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExtensionController extends Controller
{
    public function index(Request $request, Tenant $tenant, ManageExtensions $extensions): Response
    {
        $assignment = $tenant->pbxAssignment()->with(['node:id,name,sip_domain', 'numberRange'])->first();

        return Inertia::render('tenants/extensions/Index', [
            'assignment' => $assignment?->numberRange === null ? null : [
                'node' => $assignment->node->name,
                'sip_domain' => $assignment->node->sip_domain,
                'prefix' => $assignment->numberRange->prefix,
                'numbers' => $assignment->numberRange->label(),
                'status' => $assignment->status->value,
            ],
            'extensions' => Extension::query()
                ->orderByRaw('length(extension_number)')
                ->orderBy('extension_number')
                ->get()
                ->map(fn (Extension $extension): array => [
                    'id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'display_name' => $extension->display_name,
                    'status' => $extension->status->value,
                ]),
            'nextNumber' => $extensions->nextFreeNumber(),
            'statuses' => ExtensionStatus::options(),
            'canManage' => $request->user()->can('manageExtensions', $tenant),
        ]);
    }

    public function store(StoreExtensionRequest $request, Tenant $tenant, ManageExtensions $extensions): RedirectResponse
    {
        $extension = $extensions->create($request->string('extension_number')->toString(), $request->string('display_name')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Extension {$extension->extension_number} created."]);

        return back();
    }

    public function update(UpdateExtensionRequest $request, Tenant $tenant, int $extension, ManageExtensions $extensions): RedirectResponse
    {
        $extensions->update(
            Extension::query()->findOrFail($extension),
            $request->string('display_name')->toString(),
            $request->enum('status', ExtensionStatus::class) ?? ExtensionStatus::Active,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Extension updated.']);

        return back();
    }

    public function destroy(Tenant $tenant, int $extension, ManageExtensions $extensions): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $extensions->delete(Extension::query()->findOrFail($extension));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Extension deleted.']);

        return back();
    }
}
