<?php

namespace App\Modules\Provisioning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Provisioning\Http\Resources\OperationResource;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Tenancy\TenantContext;

/**
 * How far a change of the token's tenant got: pending, running, applied, retrying, failed or
 * superseded (a newer change of the same number took over).
 */
class OperationController extends Controller
{
    public function show(string $operation, TenantContext $context): OperationResource
    {
        return new OperationResource(
            ProvisioningOperation::query()->where('tenant_id', $context->id())->findOrFail($operation),
        );
    }
}
