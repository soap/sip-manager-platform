<?php

namespace App\Console\Commands;

use App\Modules\Nodes\Models\PbxNode;
use App\Modules\Provisioning\Actions\ReconcileNode;
use App\Modules\Provisioning\Enums\ReconcileStatus;
use App\Modules\Provisioning\Models\ReconcileRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every 15 minutes on the schedule (plan v0.2 §6.4); writes drifted targets again and reports
 * rows nobody asked for.
 */
#[Signature('nodes:reconcile {node? : The id of one node} {--report-only : Do not write drifted targets again}')]
#[Description('Compare the rows on the SIP nodes with the desired state and fix what drifted')]
class ReconcilePbxNodes extends Command
{
    public function handle(ReconcileNode $reconcile): int
    {
        $nodes = PbxNode::query()
            ->when($this->argument('node') !== null, fn ($query) => $query->whereKey($this->argument('node')))
            ->orderBy('id')
            ->get()
            ->filter(fn (PbxNode $node): bool => $this->argument('node') !== null || ReconcileNode::canReconcile($node));

        $failed = false;

        foreach ($nodes as $node) {
            $run = $reconcile->handle($node, ReconcileRun::TRIGGER_SCHEDULE, ! $this->option('report-only'));
            $failed = $failed || $run->status === ReconcileStatus::Failed;

            $this->line(match ($run->status) {
                ReconcileStatus::Failed => "{$node->name}: failed — {$run->error}",
                default => "{$node->name}: {$run->status->value}, {$run->targets_checked} targets, {$run->missing_count} missing, {$run->changed_count} changed, {$run->unexpected_count} unexpected, {$run->fixed_count} written again",
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
