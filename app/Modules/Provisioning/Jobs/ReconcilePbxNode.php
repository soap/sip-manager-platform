<?php

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Actions\ReconcileNode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Reconciles one node off the request, e.g. when a sync sees a service of the node change version
 * or the platform admin asks for it.
 */
class ReconcilePbxNode implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $nodeId, public string $trigger) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("reconcile-node:{$this->nodeId}"))->dontRelease()->expireAfter(600)];
    }

    public function handle(ReconcileNode $reconcile): void
    {
        $node = PbxNode::query()->find($this->nodeId);

        if ($node !== null) {
            $reconcile->handle($node, $this->trigger);
        }
    }
}
