<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asks again for a failed operation by writing its target once more. Only while nothing newer was
 * asked for that target on that node: a newer revision already carries the change, or will.
 */
class RetryOperation
{
    public function __construct(private RequestProvisioning $provisioning, private Audit $audit) {}

    /**
     * @throws ValidationException when the operation did not fail or a newer one took over
     */
    public function handle(ProvisioningOperation $operation): ProvisioningOperation
    {
        $operation->loadMissing('revision');

        if (! $this->retryable(collect([$operation]))->contains($operation->id)) {
            throw ValidationException::withMessages([
                'operation' => 'Only the newest failed change of a number or setting can be retried.',
            ]);
        }

        return DB::transaction(function () use ($operation): ProvisioningOperation {
            $retry = $this->provisioning->again($operation->revision, OperationType::Reapplied);

            $this->audit->record('operation.retried', $retry, [
                'operation' => $operation->id,
                'target' => $operation->revision->target,
                'error_code' => $operation->error_code,
            ], Tenant::query()->find($operation->tenant_id));

            return $retry;
        });
    }

    /**
     * The ids of the operations that can be retried, with one query for the whole list.
     *
     * @param  Collection<int, ProvisioningOperation>  $operations  with their revisions loaded
     * @return Collection<int, string>
     */
    public function retryable(Collection $operations): Collection
    {
        $failed = $operations->filter(fn (ProvisioningOperation $operation): bool => $operation->status === OperationStatus::Failed && $operation->revision !== null);

        if ($failed->isEmpty()) {
            return collect();
        }

        $newest = ConfigRevision::query()
            ->whereIn('target', $failed->map(fn (ProvisioningOperation $operation): string => $operation->revision->target)->unique()->values())
            ->groupBy('pbx_node_id', 'target')
            ->selectRaw('pbx_node_id, target, max(id) as newest_id')
            ->get()
            ->mapWithKeys(fn (ConfigRevision $revision): array => ["{$revision->pbx_node_id}|{$revision->target}" => (int) $revision->getAttribute('newest_id')]);

        return $failed
            ->filter(fn (ProvisioningOperation $operation): bool => $newest->get("{$operation->revision->pbx_node_id}|{$operation->revision->target}") === $operation->revision->id)
            ->map(fn (ProvisioningOperation $operation): string => $operation->id)
            ->values();
    }
}
