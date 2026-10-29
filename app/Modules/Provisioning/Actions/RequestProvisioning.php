<?php

namespace App\Modules\Provisioning\Actions;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\OutboxMessage;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Step 1 of the pipeline (plan v0.2 §6.1): records that a number, a SIP domain or a calling
 * dialplan on a node must be written again — a draft revision, a pending operation and an outbox
 * message — in the caller's transaction, so the change and the request to apply it commit or
 * roll back together. The outbox relay then hands the revision to the apply job.
 */
class RequestProvisioning
{
    /**
     * A number of the tenant, e.g. extension 101.
     *
     * @throws LogicException when called outside a database transaction
     */
    public function forNumber(
        int $nodeId,
        string $tenantId,
        string $number,
        OperationType $type,
        string $aggregateType,
        int $aggregateId,
        ?string $idempotencyKey = null,
    ): ProvisioningOperation {
        $target = ConfigRevision::numberTarget($this->tenantCode($tenantId).$number);

        return $this->request($nodeId, $tenantId, $target, $type, $aggregateType, $aggregateId, $idempotencyKey);
    }

    /**
     * The tenant's SIP domain, which the node serves while the tenant is assigned to it.
     *
     * @throws LogicException when called outside a database transaction
     */
    public function forDomain(int $nodeId, string $tenantId, OperationType $type, string $aggregateType, int $aggregateId): ProvisioningOperation
    {
        return $this->request($nodeId, $tenantId, ConfigRevision::domainTarget($this->tenantCode($tenantId)), $type, $aggregateType, $aggregateId);
    }

    /**
     * The tenant's calling dialplan on its node (plan D7).
     *
     * @throws LogicException when called outside a database transaction
     */
    public function forCalling(int $nodeId, string $tenantId, OperationType $type, string $aggregateType, int $aggregateId, ?string $idempotencyKey = null): ProvisioningOperation
    {
        return $this->request($nodeId, $tenantId, ConfigRevision::callingTarget($this->tenantCode($tenantId)), $type, $aggregateType, $aggregateId, $idempotencyKey);
    }

    /**
     * The same target on the same node once more, e.g. after the revision failed. The apply job
     * compiles the target as it is then, so the new revision carries every change made since.
     *
     * @throws LogicException when called outside a database transaction
     */
    public function again(ConfigRevision $revision, OperationType $type): ProvisioningOperation
    {
        return $this->request($revision->pbx_node_id, (string) $revision->tenant_id, $revision->target, $type, $revision->aggregate_type, $revision->aggregate_id);
    }

    private function tenantCode(string $tenantId): string
    {
        return (string) Tenant::query()->whereKey($tenantId)->value('code');
    }

    private function request(
        int $nodeId,
        string $tenantId,
        string $target,
        OperationType $type,
        string $aggregateType,
        int $aggregateId,
        ?string $idempotencyKey = null,
    ): ProvisioningOperation {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Request provisioning inside the transaction that changes the desired state.');
        }

        if ($idempotencyKey !== null) {
            $existing = ProvisioningOperation::query()->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        // the node row is the lock that keeps revision numbers of one node in order
        PbxNode::query()->whereKey($nodeId)->lockForUpdate()->first();

        $revision = ConfigRevision::query()->create([
            'tenant_id' => $tenantId,
            'pbx_node_id' => $nodeId,
            'revision_number' => ((int) ConfigRevision::query()->where('pbx_node_id', $nodeId)->max('revision_number')) + 1,
            'target' => $target,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
        ]);

        $operation = ProvisioningOperation::query()->create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'idempotency_key' => $idempotencyKey,
            'config_revision_id' => $revision->id,
        ]);

        OutboxMessage::query()->create([
            'topic' => OutboxMessage::APPLY_REVISION,
            'payload' => ['config_revision_id' => $revision->id, 'pbx_node_id' => $nodeId],
            'available_at' => now(),
        ]);

        return $operation;
    }
}
