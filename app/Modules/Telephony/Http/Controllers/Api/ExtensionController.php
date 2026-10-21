<?php

namespace App\Modules\Telephony\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Actions\ManageExtensions;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Exceptions\StaleVersion;
use App\Modules\Telephony\ExtensionRegistrations;
use App\Modules\Telephony\Http\Requests\Api\UpdateExtensionRequest;
use App\Modules\Telephony\Http\Requests\StoreExtensionRequest;
use App\Modules\Telephony\Http\Resources\ExtensionResource;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Extensions of the token's tenant (plan v0.2 §8). A change that reaches the PBX answers 202 with
 * the operation_id to follow at GET /operations/{id}; a SIP password is in the answer that sets it
 * and nowhere else.
 */
class ExtensionController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(Request $request, ExtensionRegistrations $registrations): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(ExtensionStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Extension::query()
            ->with('latestOperation')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderBy('extension_number')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        $this->withRegistrations($page->getCollection(), $registrations);

        return ExtensionResource::collection($page);
    }

    public function store(StoreExtensionRequest $request, ManageExtensions $extensions): JsonResponse
    {
        $created = $extensions->create($request->string('extension_number')->toString(), $request->string('display_name')->toString());

        return $this->accepted($created['extension'], $created['operation'], $created['password']);
    }

    public function show(int $extension, ExtensionRegistrations $registrations): JsonResponse
    {
        $model = Extension::query()->with('latestOperation')->findOrFail($extension);
        $this->withRegistrations(new EloquentCollection([$model]), $registrations);

        return (new ExtensionResource($model))->response()->setEtag((string) $model->version);
    }

    public function update(UpdateExtensionRequest $request, int $extension, ManageExtensions $extensions): JsonResponse
    {
        $model = Extension::query()->findOrFail($extension);
        $version = $request->expectedVersion();

        if ($version === null) {
            return response()->json(['message' => 'Send the version you last saw, as "version" or as If-Match.'], 428);
        }

        if ($version !== $model->version) {
            return $this->conflict($model);
        }

        try {
            $operation = $extensions->update(
                $model,
                $request->string('display_name', $model->display_name)->toString(),
                $request->enum('status', ExtensionStatus::class) ?? $model->status,
                $version,
            );
        } catch (StaleVersion) {
            return $this->conflict($model);
        }

        $model = $model->fresh(['latestOperation']) ?? $model;

        if ($operation === null) {
            return (new ExtensionResource($model))->response()->setEtag((string) $model->version);
        }

        return $this->accepted($model, $operation);
    }

    public function destroy(int $extension, ManageExtensions $extensions): JsonResponse
    {
        Gate::authorize('manageExtensions', $this->context->tenant());

        $operation = $extensions->delete(Extension::query()->findOrFail($extension));

        return response()->json(['operation_id' => $operation->id], 202);
    }

    public function rotateCredential(int $extension, ManageExtensions $extensions): JsonResponse
    {
        Gate::authorize('manageExtensions', $this->context->tenant());

        $model = Extension::query()->findOrFail($extension);
        $rotated = $extensions->rotateCredential($model);

        return $this->accepted($model, $rotated['operation'], $rotated['password']);
    }

    /**
     * 202 with the extension, the operation to follow and, when one was set, the SIP account.
     */
    private function accepted(Extension $extension, ProvisioningOperation $operation, ?string $password = null): JsonResponse
    {
        $extension->setRelation('latestOperation', $operation);

        $resource = (new ExtensionResource($extension))->additional(array_filter([
            'operation_id' => $operation->id,
            'sip_credential' => $password === null ? null : [
                'username' => $extension->extension_number,
                'password' => $password,
                ...$extension->node->sipServer(),
            ],
        ], fn (mixed $value): bool => $value !== null));

        return $resource->response()->setStatusCode(202)->setEtag((string) $extension->version);
    }

    /**
     * Attaches the live registration state of each extension for ExtensionResource (not saved).
     *
     * @param  EloquentCollection<int, Extension>  $extensions
     */
    private function withRegistrations(EloquentCollection $extensions, ExtensionRegistrations $registrations): void
    {
        $states = $registrations->of($extensions);

        $extensions->each(fn (Extension $extension) => $extension->setRelation('registration', $states[$extension->id] ?? null));
    }

    private function conflict(Extension $extension): JsonResponse
    {
        return response()->json([
            'message' => 'Someone else changed this extension. Fetch it again and retry.',
            'data' => new ExtensionResource($extension->fresh(['latestOperation']) ?? $extension),
        ], 409);
    }
}
