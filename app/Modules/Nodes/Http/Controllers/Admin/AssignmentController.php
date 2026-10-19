<?php

namespace App\Modules\Nodes\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Nodes\Actions\ManageAssignments;
use App\Modules\Nodes\Enums\AssignmentStatus;
use App\Modules\Nodes\Http\Requests\StoreAssignmentRequest;
use App\Modules\Nodes\Http\Requests\UpdateAssignmentRequest;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class AssignmentController extends Controller
{
    public function store(StoreAssignmentRequest $request, PbxNode $node, ManageAssignments $assignments): RedirectResponse
    {
        $tenant = Tenant::query()->findOrFail($request->string('tenant_id')->toString());

        $assignment = $assignments->assign($tenant, $node, $request->string('prefix')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tenant->name} is on {$node->name} with numbers {$assignment->numberRange?->label()}."]);

        return back();
    }

    public function update(UpdateAssignmentRequest $request, TenantPbxAssignment $assignment, ManageAssignments $assignments): RedirectResponse
    {
        $assignments->update(
            $assignment,
            $request->string('prefix')->toString(),
            $request->enum('status', AssignmentStatus::class) ?? AssignmentStatus::Active,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Assignment of {$assignment->tenant->name} updated."]);

        return back();
    }

    public function destroy(TenantPbxAssignment $assignment, ManageAssignments $assignments): RedirectResponse
    {
        Gate::authorize('manageAssignments', PbxNode::class);

        $assignments->unassign($assignment);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$assignment->tenant->name} is no longer on {$assignment->node->name}."]);

        return back();
    }
}
