<?php

namespace App\Modules\Telephony\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Actions\ManageCrossTenant;
use App\Modules\Tenancy\Enums\CrossTenantInbound;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Calls between tenants (plan D6): which tenants may call the token's tenant, and the directory of
 * tenants it can call. Other tenants are named by their code; a tenant's name shows only in the
 * directory of a tenant it takes calls from.
 */
class CrossTenantController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings($this->tenant())]);
    }

    public function update(Request $request, ManageCrossTenant $crossTenant): JsonResponse
    {
        $tenant = $this->tenant();
        Gate::authorize('manageExtensions', $tenant);

        $request->validate([
            'inbound' => ['required', Rule::enum(CrossTenantInbound::class)],
            'allowed_tenants' => ['sometimes', 'array', 'max:'.ManageCrossTenant::MAX_ALLOWED],
            'allowed_tenants.*' => ['string', 'digits:4', 'distinct'],
        ]);

        $operations = $crossTenant->setInbound(
            $tenant,
            $request->enum('inbound', CrossTenantInbound::class) ?? CrossTenantInbound::None,
            array_values(array_map(strval(...), (array) $request->input('allowed_tenants', []))),
        );

        return response()->json([
            'data' => $this->settings($tenant->fresh() ?? $tenant),
            'operation_ids' => array_map(fn (ProvisioningOperation $operation): string => $operation->id, $operations),
        ], $operations === [] ? 200 : 202);
    }

    public function directory(ManageCrossTenant $crossTenant): JsonResponse
    {
        return response()->json(['data' => $crossTenant->directory($this->tenant())->map(fn (Tenant $destination): array => [
            'name' => $destination->name,
            'tenant_code' => $destination->code,
            'dial_prefix' => '8'.$destination->code,
        ])->values()]);
    }

    /**
     * @return array{inbound: string, allowed_tenants: list<string>}
     */
    private function settings(Tenant $tenant): array
    {
        return [
            'inbound' => $tenant->cross_tenant_inbound->value,
            'allowed_tenants' => array_values($tenant->inboundAllowlist()->orderBy('code')->pluck('code')->map(fn (mixed $code): string => (string) $code)->all()),
        ];
    }

    private function tenant(): Tenant
    {
        return $this->context->tenant() ?? abort(404);
    }
}
