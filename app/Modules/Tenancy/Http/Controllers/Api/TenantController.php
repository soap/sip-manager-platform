<?php

namespace App\Modules\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The token's tenant, the role of the token's user in it and where its phones register.
 */
class TenantController extends Controller
{
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        abort_if($tenant === null, 404);

        /** @var User $user */
        $user = $request->user();
        $assignment = $tenant->pbxAssignment()->with(['node'])->first();

        return response()->json(['data' => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'code' => $tenant->code,
            'sip_domain' => $tenant->sip_domain,
            'status' => $tenant->status->value,
            'role' => $user->roleIn($tenant)?->value,
            'token_abilities' => $user->currentAccessToken()->abilities ?? [],
            'pbx' => $assignment === null ? null : [
                'status' => $assignment->status->value,
                'voicemail' => $assignment->node->hasVoicemail(),
                'sip_server' => $assignment->node->sipServer($tenant),
            ],
        ]]);
    }
}
