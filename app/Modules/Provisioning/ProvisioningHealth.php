<?php

namespace App\Modules\Provisioning;

use App\Modules\Provisioning\Enums\OperationStatus;
use App\Modules\Provisioning\Models\OutboxMessage;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use Illuminate\Support\Carbon;

/**
 * Notices when changes stop reaching the PBX: the relay dispatches the outbox every two seconds and
 * a worker picks a revision up at once, so anything still waiting after STALL_SECONDS means one of
 * them is not running. Retrying operations are not stalled: they wait for their backoff on purpose.
 */
class ProvisioningHealth
{
    public const STALL_SECONDS = 30;

    /**
     * Why the tenant's oldest waiting operation is stuck, or null when nothing is.
     *
     * @return array{reason: 'relay'|'worker', since: string}|null
     */
    public function stalledFor(string $tenantId): ?array
    {
        $cutoff = now()->subSeconds(self::STALL_SECONDS);

        $oldestWaiting = ProvisioningOperation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', OperationStatus::Pending)
            ->where('created_at', '<=', $cutoff)
            ->oldest()
            ->first();

        if ($oldestWaiting === null) {
            return null;
        }

        // the outbox is shared by every tenant: an old undispatched message means the relay is down
        $relayIsBehind = OutboxMessage::query()
            ->whereNull('dispatched_at')
            ->where('available_at', '<=', $cutoff)
            ->exists();

        /** @var Carbon $since */
        $since = $oldestWaiting->created_at;

        return ['reason' => $relayIsBehind ? 'relay' : 'worker', 'since' => $since->toIso8601String()];
    }
}
