<?php

namespace App\Modules\Provisioning;

use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use Illuminate\Support\Collection;

/**
 * The newest provisioning operation of each aggregate, for showing "on PBX / waiting / failed".
 */
class LatestOperations
{
    /**
     * @param  array<int, int|string>  $aggregateIds
     * @return Collection<int, array{status: value-of<OperationStatus>, error_code: string|null, error_message: string|null, next_attempt_at: string|null}>
     */
    public function of(string $tenantId, string $aggregateType, array $aggregateIds): Collection
    {
        return ProvisioningOperation::query()
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', $aggregateType)
            ->whereIn('aggregate_id', $aggregateIds)
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
