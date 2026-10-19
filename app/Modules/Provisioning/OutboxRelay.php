<?php

namespace App\Modules\Provisioning;

use App\Modules\Provisioning\Jobs\ApplyConfigRevision;
use App\Modules\Provisioning\Models\OutboxMessage;
use Illuminate\Support\Facades\Log;

/**
 * Step 3 of the pipeline: dispatches committed outbox messages, oldest first, and marks them sent.
 * A message dispatched twice (e.g. the relay died after dispatching) only runs an idempotent job
 * twice.
 */
class OutboxRelay
{
    /**
     * @return int how many messages were dispatched
     */
    public function relay(int $limit = 100): int
    {
        $messages = OutboxMessage::query()
            ->whereNull('dispatched_at')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            match ($message->topic) {
                OutboxMessage::APPLY_REVISION => ApplyConfigRevision::dispatch((int) $message->payload['config_revision_id'], (int) $message->payload['pbx_node_id']),
                default => Log::error('Outbox message with an unknown topic.', ['id' => $message->id, 'topic' => $message->topic]),
            };

            $message->update(['dispatched_at' => now()]);
        }

        return $messages->count();
    }
}
