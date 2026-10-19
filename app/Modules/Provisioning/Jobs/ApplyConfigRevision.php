<?php

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Provisioning\Actions\ApplyRevision;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Applies one revision on the provisioning queue, one revision per node at a time; a retryable
 * failure releases the job with the backoff ApplyRevision chose. Running it twice is harmless: an
 * applied revision is left alone and every write replaces the number's rows.
 */
class ApplyConfigRevision implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'provisioning';

    public function __construct(public int $revisionId, public int $nodeId)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("pbx-node:{$this->nodeId}"))->releaseAfter(5)->expireAfter(300)];
    }

    /**
     * Longer than the whole backoff (about 43 minutes), so the job is never dropped while retrying.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(ApplyRevision $apply): void
    {
        $delay = $apply->handle($this->revisionId);

        if ($delay !== null) {
            $this->release($delay);
        }
    }
}
