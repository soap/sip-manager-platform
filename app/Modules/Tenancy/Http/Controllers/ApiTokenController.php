<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tenancy\Actions\ManageApiTokens;
use App\Modules\Tenancy\Enums\ApiAbility;
use App\Modules\Tenancy\Http\Requests\StoreApiTokenRequest;
use App\Modules\Tenancy\Models\ApiToken;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function index(Tenant $tenant, ManageApiTokens $tokens): Response
    {
        Gate::authorize('manageApiTokens', $tenant);

        return Inertia::render('tenants/api-tokens/Index', [
            'tokens' => $tokens->list()->map(fn (ApiToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'user' => $token->tokenable instanceof User ? $token->tokenable->name : null,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ]),
            'abilities' => ApiAbility::options(),
            'expiryDays' => StoreApiTokenRequest::EXPIRY_DAYS,
            'apiBaseUrl' => url('/api/v1'),
        ]);
    }

    public function store(StoreApiTokenRequest $request, Tenant $tenant, ManageApiTokens $tokens): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ability = $request->enum('ability', ApiAbility::class) ?? ApiAbility::Read;
        $days = $request->integer('expires_in_days');

        $issued = $tokens->issue(
            $user,
            $request->string('name')->toString(),
            // write implies read
            $ability === ApiAbility::Write ? [ApiAbility::Read, ApiAbility::Write] : [ApiAbility::Read],
            $days > 0 ? now()->addDays($days) : null,
        );

        // shown once; only its hash is stored
        Inertia::flash('apiToken', ['name' => $issued['token']->name, 'token' => $issued['plain_text']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'API token created.']);

        return back();
    }

    public function destroy(Tenant $tenant, int $token, ManageApiTokens $tokens): RedirectResponse
    {
        Gate::authorize('manageApiTokens', $tenant);

        $tokens->revoke($tokens->find($token));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'API token revoked.']);

        return back();
    }
}
