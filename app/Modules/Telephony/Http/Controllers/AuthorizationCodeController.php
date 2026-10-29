<?php

namespace App\Modules\Telephony\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Telephony\Actions\ManageAuthorizationCodes;
use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Http\Requests\SaveAuthorizationCodeRequest;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Authorization codes on the calling permissions page. A code is in the one response that makes
 * or resets it, like a SIP password.
 */
class AuthorizationCodeController extends Controller
{
    public function store(SaveAuthorizationCodeRequest $request, Tenant $tenant, ManageAuthorizationCodes $codes): RedirectResponse
    {
        $created = $codes->create(
            $request->string('label')->toString(),
            $request->categories(),
            $request->expiresAt(),
            $request->userId(),
            $request->filled('code') ? $request->string('code')->toString() : null,
        );

        $this->reveal($created['authorization_code'], $created['code']);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Authorization code for {$created['authorization_code']->label} created."]);

        return back();
    }

    public function update(SaveAuthorizationCodeRequest $request, Tenant $tenant, int $code, ManageAuthorizationCodes $codes): RedirectResponse
    {
        $model = AuthorizationCode::query()->findOrFail($code);

        $codes->update(
            $model,
            $request->string('label', $model->label)->toString(),
            $request->categories($model),
            $request->enum('status', AuthorizationCodeStatus::class) ?? $model->status,
            $request->expiresAt($model),
            $request->userId($model),
            $request->filled('version') ? $request->integer('version') : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Authorization code updated.']);

        return back();
    }

    public function reset(Tenant $tenant, int $code, ManageAuthorizationCodes $codes): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $model = AuthorizationCode::query()->findOrFail($code);
        $this->reveal($model, $codes->reset($model)['code']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "New digits for {$model->label}. The old ones stop working once the PBX has them."]);

        return back();
    }

    public function destroy(Tenant $tenant, int $code, ManageAuthorizationCodes $codes): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $codes->delete(AuthorizationCode::query()->findOrFail($code));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Authorization code deleted.']);

        return back();
    }

    /**
     * The digits are in this one response only; only their hash is kept.
     */
    private function reveal(AuthorizationCode $authorizationCode, string $code): void
    {
        Inertia::flash('authorizationCode', ['label' => $authorizationCode->label, 'code' => $code]);
    }
}
