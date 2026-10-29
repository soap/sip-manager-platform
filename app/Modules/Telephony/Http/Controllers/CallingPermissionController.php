<?php

namespace App\Modules\Telephony\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\LatestOperations;
use App\Modules\Telephony\Actions\ManageCallingProfiles;
use App\Modules\Telephony\Actions\ManageCrossTenant;
use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Enums\CallPermission;
use App\Modules\Telephony\Enums\CrossTenantScope;
use App\Modules\Telephony\Http\Requests\SaveCallingProfileRequest;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Tenancy\Enums\CrossTenantInbound;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The calling permissions page (plan D7): calling profiles, authorization codes, and which other
 * tenants may call this one.
 */
class CallingPermissionController extends Controller
{
    public function index(Request $request, Tenant $tenant, ManageCrossTenant $crossTenant, LatestOperations $latest): Response
    {
        $profiles = CallingProfile::query()->with('destinations:id,code,name')->withCount('extensions')->orderBy('name')->get();
        $codes = AuthorizationCode::query()->with('user:id,name')->orderBy('label')->get();
        $codeOperations = $latest->of($tenant->id, AuthorizationCode::MORPH, $codes->modelKeys());

        return Inertia::render('tenants/calling/Index', [
            'profiles' => $profiles->map(fn (CallingProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'categories' => self::permissions($profile),
                'cross_tenant_scope' => $profile->cross_tenant_scope->value,
                'destinations' => $profile->destinations->map(fn (Tenant $destination): array => ['code' => $destination->code, 'name' => $destination->name])->values(),
                'is_default' => $profile->is_default,
                'extensions_count' => $profile->extensions_count,
                'version' => $profile->version,
            ]),
            'codes' => $codes->map(fn (AuthorizationCode $code): array => [
                'id' => $code->id,
                'label' => $code->label,
                'categories' => $code->categories,
                'status' => $code->status->value,
                'expires_at' => $code->expires_at?->toIso8601String(),
                'user' => $code->user?->only('id', 'name'),
                'version' => $code->version,
                'provisioning' => $codeOperations->get($code->id),
            ]),
            'inbound' => [
                'mode' => $tenant->cross_tenant_inbound->value,
                'allowed' => $tenant->inboundAllowlist()->orderBy('code')->get(['tenants.id', 'code', 'name'])->map(fn (Tenant $source): array => ['code' => $source->code, 'name' => $source->name]),
            ],
            'directory' => $crossTenant->directory($tenant)->map(fn (Tenant $destination): array => ['code' => $destination->code, 'name' => $destination->name, 'dial' => '8'.$destination->code]),
            'members' => $tenant->users()->orderBy('name')->get(['users.id', 'name'])->map(fn ($user): array => ['id' => $user->id, 'name' => $user->name]),
            'categories' => CallCategory::options(),
            'permissions' => CallPermission::options(),
            'scopes' => CrossTenantScope::options(),
            'inboundModes' => CrossTenantInbound::options(),
            'codeStatuses' => array_map(fn (AuthorizationCodeStatus $status): array => ['value' => $status->value, 'label' => $status->label()], AuthorizationCodeStatus::cases()),
            'limits' => ['destinations' => CallingProfile::MAX_DESTINATIONS, 'code_min' => AuthorizationCode::MIN_LENGTH, 'code_max' => AuthorizationCode::MAX_LENGTH],
            'tenantCode' => $tenant->code,
            'hasNode' => $tenant->pbxAssignment()->exists(),
            'canManage' => $request->user()->can('manageExtensions', $tenant),
        ]);
    }

    public function storeProfile(SaveCallingProfileRequest $request, Tenant $tenant, ManageCallingProfiles $profiles): RedirectResponse
    {
        $profile = $profiles->create($request->string('name')->toString(), $request->categories(), $request->scope(), $request->destinationCodes());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Calling profile {$profile->name} created."]);

        return back();
    }

    public function updateProfile(SaveCallingProfileRequest $request, Tenant $tenant, int $profile, ManageCallingProfiles $profiles): RedirectResponse
    {
        $model = CallingProfile::query()->findOrFail($profile);
        $operations = $profiles->update(
            $model,
            $request->string('name', $model->name)->toString(),
            $request->categories($model),
            $request->scope($model),
            $request->destinationCodes($model),
            $request->filled('version') ? $request->integer('version') : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $operations === []
            ? 'Calling profile updated.'
            : 'Calling profile updated. '.count($operations).' extension(s) are being written to the PBX.']);

        return back();
    }

    public function makeDefault(Tenant $tenant, int $profile, ManageCallingProfiles $profiles): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $model = CallingProfile::query()->findOrFail($profile);
        $profiles->makeDefault($model);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$model->name} is the default profile now."]);

        return back();
    }

    public function destroyProfile(Tenant $tenant, int $profile, ManageCallingProfiles $profiles): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $profiles->delete(CallingProfile::query()->findOrFail($profile));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Calling profile deleted.']);

        return back();
    }

    public function updateInbound(Request $request, Tenant $tenant, ManageCrossTenant $crossTenant): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $request->validate([
            'mode' => ['required', Rule::enum(CrossTenantInbound::class)],
            'allowed' => ['present', 'array', 'max:'.ManageCrossTenant::MAX_ALLOWED],
            'allowed.*' => ['string', 'digits:4', 'distinct'],
        ]);

        $crossTenant->setInbound($tenant, $request->enum('mode', CrossTenantInbound::class) ?? CrossTenantInbound::None, array_values(array_map(strval(...), (array) $request->input('allowed', []))));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Calls from other tenants updated.']);

        return back();
    }

    /**
     * Every category with its permission, deny included.
     *
     * @return array<string, string>
     */
    public static function permissions(CallingProfile $profile): array
    {
        $permissions = [];

        foreach (CallCategory::cases() as $category) {
            $permissions[$category->value] = $profile->permissionFor($category)->value;
        }

        return $permissions;
    }
}
