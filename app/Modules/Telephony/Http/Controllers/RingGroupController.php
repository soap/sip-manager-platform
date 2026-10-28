<?php

namespace App\Modules\Telephony\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\LatestOperations;
use App\Modules\Provisioning\ProvisioningHealth;
use App\Modules\Telephony\Actions\ManageRingGroups;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Enums\RingStrategy;
use App\Modules\Telephony\Http\Requests\SaveRingGroupRequest;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RingGroupController extends Controller
{
    public function index(Request $request, Tenant $tenant, ManageRingGroups $ringGroups, ProvisioningHealth $health, LatestOperations $latest): Response
    {
        $groups = RingGroup::query()->with('members:id,extension_number,display_name')->orderBy('number')->get();
        $operations = $latest->of($tenant->id, RingGroup::MORPH, $groups->modelKeys());

        return Inertia::render('tenants/ring-groups/Index', [
            'ringGroups' => $groups->map(fn (RingGroup $group): array => [
                ...$this->summary($group),
                'members' => $group->members->map(fn (Extension $member): array => ['number' => $member->extension_number, 'name' => $member->display_name])->all(),
                'failover' => $this->failoverLabel($group, $groups->keyBy('id')->all()),
                'provisioning' => $operations->get($group->id),
            ]),
            ...$this->formOptions(),
            'hasNode' => $tenant->pbxAssignment()->exists(),
            'nextNumber' => $ringGroups->nextFreeNumber(),
            'canManage' => $request->user()->can('manageExtensions', $tenant),
            'provisioningStalled' => $health->stalledFor($tenant->id),
        ]);
    }

    public function store(SaveRingGroupRequest $request, Tenant $tenant, ManageRingGroups $ringGroups): RedirectResponse
    {
        $group = $ringGroups->create(
            $request->string('number')->toString(),
            $request->string('name')->toString(),
            $request->enum('strategy', RingStrategy::class) ?? RingStrategy::RingAll,
            $request->integer('ring_seconds'),
            $request->memberIds(),
            $request->enum('failover_type', FailoverType::class) ?? FailoverType::Hangup,
            $request->filled('failover_id') ? $request->integer('failover_id') : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Ring group {$group->number} created. It is being written to the PBX."]);

        return to_route('tenant.ring-groups.index', $tenant);
    }

    public function edit(Tenant $tenant, int $ringGroup): Response
    {
        Gate::authorize('manageExtensions', $tenant);

        $group = RingGroup::query()->with('members:id')->findOrFail($ringGroup);

        return Inertia::render('tenants/ring-groups/Edit', [
            'ringGroup' => [
                ...$this->summary($group),
                'member_ids' => $group->members->modelKeys(),
                'failover_type' => $group->failover_type->value,
                'failover_id' => $group->failover_id,
            ],
            ...$this->formOptions($group),
            'statuses' => ExtensionStatus::options(),
        ]);
    }

    public function update(SaveRingGroupRequest $request, Tenant $tenant, int $ringGroup, ManageRingGroups $ringGroups): RedirectResponse
    {
        $ringGroups->update(
            RingGroup::query()->findOrFail($ringGroup),
            $request->string('name')->toString(),
            $request->enum('strategy', RingStrategy::class) ?? RingStrategy::RingAll,
            $request->integer('ring_seconds'),
            $request->memberIds(),
            $request->enum('failover_type', FailoverType::class) ?? FailoverType::Hangup,
            $request->filled('failover_id') ? $request->integer('failover_id') : null,
            $request->enum('status', ExtensionStatus::class) ?? ExtensionStatus::Active,
            $request->filled('version') ? $request->integer('version') : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ring group updated. It is being written to the PBX.']);

        return to_route('tenant.ring-groups.index', $tenant);
    }

    public function destroy(Tenant $tenant, int $ringGroup, ManageRingGroups $ringGroups): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $ringGroups->delete(RingGroup::query()->findOrFail($ringGroup));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ring group deleted.']);

        return to_route('tenant.ring-groups.index', $tenant);
    }

    public function reapply(Tenant $tenant, int $ringGroup, ManageRingGroups $ringGroups): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $ringGroups->reapply(RingGroup::query()->findOrFail($ringGroup));

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Writing the ring group to the PBX again.']);

        return back();
    }

    /**
     * @return array{id: int, number: string, name: string, strategy: string, ring_seconds: int, status: string, version: int}
     */
    private function summary(RingGroup $group): array
    {
        return [
            'id' => $group->id,
            'number' => $group->number,
            'name' => $group->name,
            'strategy' => $group->strategy->value,
            'ring_seconds' => $group->ring_seconds,
            'status' => $group->status->value,
            'version' => $group->version,
        ];
    }

    /**
     * The extensions that can be members or failover, and the ring groups a group can fail over to.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?RingGroup $except = null): array
    {
        return [
            'extensions' => Extension::query()->orderBy('extension_number')->get()->map(fn (Extension $extension): array => [
                'id' => $extension->id,
                'number' => $extension->extension_number,
                'name' => $extension->display_name,
                'status' => $extension->status->value,
                'voicemail' => $extension->voicemail_enabled,
            ]),
            'failoverGroups' => RingGroup::query()
                ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
                ->orderBy('number')
                ->get()
                ->map(fn (RingGroup $group): array => ['id' => $group->id, 'number' => $group->number, 'name' => $group->name]),
            'strategies' => RingStrategy::options(),
            'limits' => ['members' => RingGroup::MAX_MEMBERS, 'min_seconds' => RingGroup::MIN_RING_SECONDS, 'max_seconds' => RingGroup::MAX_RING_SECONDS],
        ];
    }

    /**
     * @param  array<int, RingGroup>  $groups
     */
    private function failoverLabel(RingGroup $group, array $groups): string
    {
        return match ($group->failover_type) {
            FailoverType::Hangup => 'Hang up',
            FailoverType::Extension => 'Extension '.(Extension::query()->find($group->failover_id)->extension_number ?? '?'),
            FailoverType::RingGroup => 'Ring group '.($groups[$group->failover_id]->number ?? '?'),
            FailoverType::Voicemail => 'Voicemail of '.(Extension::query()->find($group->failover_id)->extension_number ?? '?'),
        };
    }
}
