<?php

namespace App\Modules\Telephony\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\Compiler\NumberCompiler;
use App\Modules\Provisioning\LatestOperations;
use App\Modules\Provisioning\ProvisioningHealth;
use App\Modules\Telephony\Actions\ManagePickupGroups;
use App\Modules\Telephony\Http\Requests\SavePickupGroupRequest;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\PickupGroup;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PickupGroupController extends Controller
{
    public function index(Request $request, Tenant $tenant, ProvisioningHealth $health, LatestOperations $latest): Response
    {
        $groups = PickupGroup::query()->with('members:id,extension_number,display_name')->orderBy('name')->get();
        $operations = $latest->of($tenant->id, PickupGroup::MORPH, $groups->modelKeys());

        return Inertia::render('tenants/pickup-groups/Index', [
            'pickupGroups' => $groups->map(fn (PickupGroup $group): array => [
                ...$this->summary($group),
                'members' => $group->members->map(fn (Extension $member): array => ['number' => $member->extension_number, 'name' => $member->display_name])->all(),
                'provisioning' => $operations->get($group->id),
            ]),
            'extensions' => $this->extensionOptions(),
            'featureCode' => NumberCompiler::PICKUP_EXTEN,
            'hasNode' => $tenant->pbxAssignment()->exists(),
            'canManage' => $request->user()->can('manageExtensions', $tenant),
            'provisioningStalled' => $health->stalledFor($tenant->id),
        ]);
    }

    public function store(SavePickupGroupRequest $request, Tenant $tenant, ManagePickupGroups $pickupGroups): RedirectResponse
    {
        $group = $pickupGroups->create($request->string('name')->toString(), $request->memberIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pickup group {$group->name} created. It is being written to the PBX."]);

        return to_route('tenant.pickup-groups.index', $tenant);
    }

    public function edit(Tenant $tenant, int $pickupGroup): Response
    {
        Gate::authorize('manageExtensions', $tenant);

        $group = PickupGroup::query()->with('members:id')->findOrFail($pickupGroup);

        return Inertia::render('tenants/pickup-groups/Edit', [
            'pickupGroup' => [
                ...$this->summary($group),
                'member_ids' => $group->members->modelKeys(),
            ],
            'extensions' => $this->extensionOptions(),
        ]);
    }

    public function update(SavePickupGroupRequest $request, Tenant $tenant, int $pickupGroup, ManagePickupGroups $pickupGroups): RedirectResponse
    {
        $pickupGroups->update(
            PickupGroup::query()->findOrFail($pickupGroup),
            $request->string('name')->toString(),
            $request->memberIds(),
            $request->filled('version') ? $request->integer('version') : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pickup group updated. It is being written to the PBX.']);

        return to_route('tenant.pickup-groups.index', $tenant);
    }

    public function destroy(Tenant $tenant, int $pickupGroup, ManagePickupGroups $pickupGroups): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $pickupGroups->delete(PickupGroup::query()->findOrFail($pickupGroup));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pickup group deleted.']);

        return to_route('tenant.pickup-groups.index', $tenant);
    }

    public function reapply(Tenant $tenant, int $pickupGroup, ManagePickupGroups $pickupGroups): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $pickupGroups->reapply(PickupGroup::query()->findOrFail($pickupGroup));

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Writing the pickup group to the PBX again.']);

        return back();
    }

    /**
     * @return array{id: int, name: string, version: int}
     */
    private function summary(PickupGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'version' => $group->version,
        ];
    }

    /**
     * Every extension of the tenant, with the pickup group it is in (an extension is in at most one).
     *
     * @return list<array{id: int, number: string, name: string, status: string, pickup_group_id: int|null, pickup_group: string|null}>
     */
    private function extensionOptions(): array
    {
        return Extension::query()->with('pickupGroups:id,name')->orderBy('extension_number')->get()->map(fn (Extension $extension): array => [
            'id' => $extension->id,
            'number' => $extension->extension_number,
            'name' => $extension->display_name,
            'status' => $extension->status->value,
            'pickup_group_id' => $extension->pickupGroups->first()?->id,
            'pickup_group' => $extension->pickupGroups->first()?->name,
        ])->all();
    }
}
