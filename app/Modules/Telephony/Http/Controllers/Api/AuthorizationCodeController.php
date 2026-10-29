<?php

namespace App\Modules\Telephony\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Actions\ManageAuthorizationCodes;
use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Exceptions\StaleVersion;
use App\Modules\Telephony\Http\Requests\SaveAuthorizationCodeRequest;
use App\Modules\Telephony\Http\Resources\AuthorizationCodeResource;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization codes of the token's tenant (plan D7). The digits are in the answer of POST and
 * of reset ("authorization_code"), and nowhere else. A change answers 202 with the operation that writes the tenant's
 * calling dialplan (200 without one, before the tenant has a node).
 */
class AuthorizationCodeController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        return AuthorizationCodeResource::collection(AuthorizationCode::query()->orderBy('label')->get());
    }

    public function store(SaveAuthorizationCodeRequest $request, ManageAuthorizationCodes $codes): JsonResponse
    {
        $created = $codes->create(
            $request->string('label')->toString(),
            $request->categories(),
            $request->expiresAt(),
            $request->userId(),
            $request->filled('code') ? $request->string('code')->toString() : null,
        );

        return $this->answer($created['authorization_code'], $created['operation'], $created['code'], 201);
    }

    public function show(int $code): JsonResponse
    {
        $model = AuthorizationCode::query()->findOrFail($code);

        return (new AuthorizationCodeResource($model))->response()->setEtag((string) $model->version);
    }

    public function update(SaveAuthorizationCodeRequest $request, int $code, ManageAuthorizationCodes $codes): JsonResponse
    {
        $model = AuthorizationCode::query()->findOrFail($code);
        $version = $request->expectedVersion();

        if ($version === null) {
            return response()->json(['message' => 'Send the version you last saw, as "version" or as If-Match.'], 428);
        }

        try {
            $operation = $codes->update(
                $model,
                $request->string('label', $model->label)->toString(),
                $request->categories($model),
                $request->enum('status', AuthorizationCodeStatus::class) ?? $model->status,
                $request->expiresAt($model),
                $request->userId($model),
                $version,
            );
        } catch (StaleVersion) {
            return response()->json([
                'message' => 'Someone else changed this code. Fetch it again and retry.',
                'data' => new AuthorizationCodeResource($model->fresh() ?? $model),
            ], 409);
        }

        return $this->answer($model->fresh() ?? $model, $operation);
    }

    public function reset(int $code, ManageAuthorizationCodes $codes): JsonResponse
    {
        Gate::authorize('manageExtensions', $this->context->tenant());

        $model = AuthorizationCode::query()->findOrFail($code);
        $reset = $codes->reset($model);

        return $this->answer($model, $reset['operation'], $reset['code']);
    }

    public function destroy(int $code, ManageAuthorizationCodes $codes): JsonResponse
    {
        Gate::authorize('manageExtensions', $this->context->tenant());

        $operation = $codes->delete(AuthorizationCode::query()->findOrFail($code));

        return $operation === null ? response()->json(null, 204) : response()->json(['operation_id' => $operation->id], 202);
    }

    private function answer(AuthorizationCode $authorizationCode, ?ProvisioningOperation $operation, ?string $code = null, int $status = 200): JsonResponse
    {
        $resource = (new AuthorizationCodeResource($authorizationCode))->additional(array_filter([
            'operation_id' => $operation?->id,
            // under a key Audit::redact() knows, so an Idempotency-Key replay does not hold the digits
            'authorization_code' => $code,
        ], fn (mixed $value): bool => $value !== null));

        return $resource->response()->setStatusCode($operation === null ? $status : 202)->setEtag((string) $authorizationCode->version);
    }
}
