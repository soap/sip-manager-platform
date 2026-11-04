<?php

namespace App\Console\Commands;

use App\Modules\CallRecords\Listener\CallEventListener;
use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Runs for good under a process manager (plan §7), next to a queue worker for the events queue.
 * Stops cleanly on SIGTERM or SIGINT where ext-pcntl is loaded; elsewhere (Windows) the node rows
 * are let go by the heartbeat going stale.
 */
#[Signature('ami:listen {node? : The id of one node; every node with AMI when left out}')]
#[Description('Listen to the AMI events of the SIP nodes and store the call events')]
class ListenAmi extends Command
{
    public function handle(CallEventListener $listener): int
    {
        $stopping = false;

        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$stopping): void {
                $stopping = true;
            });
        }

        $this->line("Listener {$listener->id} started.");

        $listener->run(
            fn (): Collection => PbxNode::query()
                ->when($this->argument('node') !== null, fn ($query) => $query->whereKey($this->argument('node')))
                ->orderBy('id')
                ->get(),
            fn (): bool => $stopping,
            fn (string $line) => $this->line(now()->format('H:i:s').' '.$line),
        );

        return self::SUCCESS;
    }
}
