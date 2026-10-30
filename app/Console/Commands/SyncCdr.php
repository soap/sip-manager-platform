<?php

namespace App\Console\Commands;

use App\Modules\CallRecords\Actions\SyncCallRecords;
use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute on the schedule (plan §7).
 */
#[Signature('cdr:sync {node? : The id of one node}')]
#[Description('Copy the CDR of the SIP nodes into call records')]
class SyncCdr extends Command
{
    public function handle(SyncCallRecords $sync): int
    {
        $nodes = PbxNode::query()
            ->when($this->argument('node') !== null, fn ($query) => $query->whereKey($this->argument('node')))
            ->orderBy('id')
            ->get()
            ->filter(fn (PbxNode $node): bool => SyncCallRecords::canSync($node));

        $failed = false;

        foreach ($nodes as $node) {
            $cursor = $sync->handle($node);
            $failed = $failed || $cursor->last_error !== null;

            $this->line($cursor->last_error !== null
                ? "{$node->name}: failed — {$cursor->last_error}"
                : "{$node->name}: {$cursor->last_count} rows, synced until {$cursor->synced_until?->toIso8601String()}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
