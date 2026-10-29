<?php

namespace App\Modules\Telephony\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Actions\ManageCallingProfiles;
use App\Modules\Telephony\Exceptions\ProfileInUse;
use App\Modules\Telephony\Exceptions\StaleVersion;
use App\Modules\Telephony\Http\Requests\SaveCallingProfileRequest;
use App\Modules\Telephony\Http\Resources\CallingProfileResource;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Calling profiles of the token's tenant (plan D7). A change that reaches extensions answers 202
 * with one operation per extension; is_default is set with POST {id}/default.
 */
class CallingProfileController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        return CallingProfileResource::collection(CallingProfile::query()->with('destinations:id,code')->withCount('extensions')->orderBy('name')->get());
    }

    public function store(SaveCallingProfileRequest $request, ManageCallingProfiles $profiles): JsonResponse
    {
        $profile = $profiles->create($request->string('name')->toString(), $request->categories(), $request->scope(), $request->destinationCodes());

        return $this->resource($profile)->setStatusCode(201);
    }

    public function show(int $profile): JsonResponse
    {
        return $this->resource(CallingProfile::query()->findOrFail($profile));
    }

    public function update(SaveCallingProfileRequest $request, int $profile, ManageCallingProfiles $profiles): JsonResponse
    {
        $model = CallingProfile::query()->with('destinations')->findOrFail($profile);
        $version = $request->expectedVersion();

        if ($version === null) {
            return response()->json(['message' => 'Send the version you last saw, as "version" or as If-Match.'], 428);
        }

        try {
            $operations = $profiles->update(
                $model,
                $request->string('name', $model->name)->toString(),
                $request->categories($model),
                $request->scope($model),
                $request->destinationCodes($model),
                $version,
            );
        } catch (StaleVersion) {
            return $this->conflict('Someone else changed this profile. Fetch it again and retry.', $model);
        }

        return $this->withOperations($model, $operations);
    }

    public function makeDefault(int $profile, ManageCallingProfiles $profiles): JsonResponse
    {
        Gate::authorize('manageExtensions', $this->context->tenant());

        $model = CallingProfile::query()->findOrFail($profile);

        return $this->withOperations($model, $profiles->makeDefault($model));
    }

    public function destroy(int $profile, ManageCallingProfiles $profiles): JsonResponse
    {
        Gate::authorize('manageExtensions', $this->context->tenant());

        $model = CallingProfile::query()->findOrFail($profile);

        try {
            $profiles->delete($model);
        } catch (ProfileInUse $exception) {
            return $this->conflict((string) collect($exception->errors())->flatten()->first(), $model);
        }

        return response()->json(null, 204);
    }

    /**
     * 202 with the operations when extensions are being written, else 200.
     *
     * @param  list<ProvisioningOperation>  $operations
     */
    private function withOperations(CallingProfile $profile, array $operations): JsonResponse
    {
        if ($operations === []) {
            return $this->resource($profile);
        }

        return $this->resource($profile, ['operation_ids' => array_map(fn (ProvisioningOperation $operation): string => $operation->id, $operations)])->setStatusCode(202);
    }

    /**
     * @param  array<string, mixed>  $additional
     */
    private function resource(CallingProfile $profile, array $additional = []): JsonResponse
    {
        $profile = $profile->fresh(['destinations']) ?? $profile;
        $profile->loadCount('extensions');

        return (new CallingProfileResource($profile))->additional($additional)->response()->setEtag((string) $profile->version);
    }

    private function conflict(string $message, CallingProfile $profile): JsonResponse
    {
        $profile = $profile->fresh(['destinations']) ?? $profile;

        return response()->json(['message' => $message, 'data' => new CallingProfileResource($profile)], 409);
    }
}
