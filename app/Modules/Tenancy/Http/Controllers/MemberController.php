<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tenancy\Actions\ManageMembers;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Http\Requests\StoreMemberRequest;
use App\Modules\Tenancy\Http\Requests\UpdateMemberRequest;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        return Inertia::render('tenants/members/Index', [
            'members' => TenantMembership::query()
                ->with('user:id,name,email')
                ->get()
                ->sortBy(fn (TenantMembership $membership): string => $membership->user->name)
                ->values()
                ->map(fn (TenantMembership $membership): array => [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->value,
                ]),
            'roles' => TenantRole::options(),
            'canManage' => $request->user()->can('manageMembers', $tenant),
        ]);
    }

    public function store(StoreMemberRequest $request, Tenant $tenant, ManageMembers $members): RedirectResponse
    {
        $members->add(
            $this->actor($request),
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->enum('role', TenantRole::class),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Member added.']);

        return back();
    }

    public function update(UpdateMemberRequest $request, Tenant $tenant, int $membership, ManageMembers $members): RedirectResponse
    {
        $members->changeRole($this->actor($request), TenantMembership::query()->findOrFail($membership), $request->enum('role', TenantRole::class));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role changed.']);

        return back();
    }

    public function destroy(Request $request, Tenant $tenant, int $membership, ManageMembers $members): RedirectResponse
    {
        Gate::authorize('manageMembers', $tenant);

        $members->remove($this->actor($request), TenantMembership::query()->findOrFail($membership));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Member removed.']);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
