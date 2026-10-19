<?php

namespace App\Modules\Telephony\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Provisioning\ProvisioningHealth;
use App\Modules\Telephony\Actions\ManageExtensions;
use App\Modules\Telephony\Enums\ExtensionStatus;
use App\Modules\Telephony\Http\Requests\StoreExtensionRequest;
use App\Modules\Telephony\Http\Requests\UpdateExtensionRequest;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExtensionController extends Controller
{
    public function index(Request $request, Tenant $tenant, ManageExtensions $extensions, ProvisioningHealth $health): Response
    {
        $assignment = $tenant->pbxAssignment()->with(['node', 'numberRange'])->first();
        $list = Extension::query()->orderBy('extension_number')->get();
        $operations = $this->latestOperations($tenant, $list);

        return Inertia::render('tenants/extensions/Index', [
            'assignment' => $assignment?->numberRange === null ? null : [
                'node' => $assignment->node->name,
                'prefix' => $assignment->numberRange->prefix,
                'numbers' => $assignment->numberRange->label(),
                'status' => $assignment->status->value,
                'server' => $this->sipServer($assignment->node),
            ],
            'extensions' => $list->map(fn (Extension $extension): array => [
                'id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'display_name' => $extension->display_name,
                'status' => $extension->status->value,
                'version' => $extension->version,
                'provisioning' => $operations->get($extension->id),
            ]),
            'nextNumber' => $extensions->nextFreeNumber(),
            'statuses' => ExtensionStatus::options(),
            'canManage' => $request->user()->can('manageExtensions', $tenant),
            'provisioningStalled' => $health->stalledFor($tenant->id),
        ]);
    }

    public function store(StoreExtensionRequest $request, Tenant $tenant, ManageExtensions $extensions): RedirectResponse
    {
        $created = $extensions->create($request->string('extension_number')->toString(), $request->string('display_name')->toString());

        $this->revealCredential($created['extension'], $created['password']);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Extension {$created['extension']->extension_number} created. It is being written to the PBX."]);

        return back();
    }

    public function update(UpdateExtensionRequest $request, Tenant $tenant, int $extension, ManageExtensions $extensions): RedirectResponse
    {
        $extensions->update(
            Extension::query()->findOrFail($extension),
            $request->string('display_name')->toString(),
            $request->enum('status', ExtensionStatus::class) ?? ExtensionStatus::Active,
            $request->filled('version') ? $request->integer('version') : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Extension updated.']);

        return back();
    }

    public function destroy(Tenant $tenant, int $extension, ManageExtensions $extensions): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $extensions->delete(Extension::query()->findOrFail($extension));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Extension deleted.']);

        return back();
    }

    public function rotateCredential(Tenant $tenant, int $extension, ManageExtensions $extensions): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $model = Extension::query()->findOrFail($extension);
        $rotated = $extensions->rotateCredential($model);

        $this->revealCredential($model, $rotated['password']);
        Inertia::flash('toast', ['type' => 'success', 'message' => "New SIP password for {$model->extension_number}. The old one stops working once the PBX has it."]);

        return back();
    }

    public function reapply(Tenant $tenant, int $extension, ManageExtensions $extensions): RedirectResponse
    {
        Gate::authorize('manageExtensions', $tenant);

        $extensions->reapply(Extension::query()->findOrFail($extension));

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Writing the extension to the PBX again.']);

        return back();
    }

    /**
     * The password is in this one response only; it is never sent again (v1.1 §12).
     */
    private function revealCredential(Extension $extension, string $password): void
    {
        Inertia::flash('sipCredential', [
            'extension_number' => $extension->extension_number,
            'username' => $extension->extension_number,
            'password' => $password,
            ...$this->sipServer($extension->node),
        ]);
    }

    /**
     * Where a phone registers: the node's SIP domain (also the realm) and port.
     *
     * @return array{domain: string|null, port: int, transports: list<string>}
     */
    private function sipServer(PbxNode $node): array
    {
        return [
            'domain' => $node->sip_domain,
            'port' => (int) ($node->services['opensips']['sip_port'] ?? 5060),
            'transports' => array_values($node->services['opensips']['transports'] ?? ['udp']),
        ];
    }

    /**
     * The newest provisioning operation of each extension, keyed by extension id.
     *
     * @param  EloquentCollection<int, Extension>  $extensions
     * @return Collection<int, array{status: value-of<OperationStatus>, error_code: string|null, error_message: string|null, next_attempt_at: string|null}>
     */
    private function latestOperations(Tenant $tenant, EloquentCollection $extensions): Collection
    {
        $extensionIds = $extensions->modelKeys();

        return ProvisioningOperation::query()
            ->where('tenant_id', $tenant->id)
            ->where('aggregate_type', Extension::MORPH)
            ->whereIn('aggregate_id', $extensionIds)
            ->orderByDesc('id')
            ->get()
            ->unique('aggregate_id')
            ->mapWithKeys(fn (ProvisioningOperation $operation): array => [$operation->aggregate_id => [
                'status' => $operation->status->value,
                'error_code' => $operation->error_code,
                'error_message' => $operation->error_message,
                'next_attempt_at' => $operation->next_attempt_at?->toIso8601String(),
            ]]);
    }
}
