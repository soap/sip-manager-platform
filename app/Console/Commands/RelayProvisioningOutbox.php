<?php

namespace App\Console\Commands;

use App\Modules\Provisioning\OutboxRelay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every two seconds on the schedule; the provisioning queue worker then applies the revisions.
 */
#[Signature('provisioning:relay')]
#[Description('Dispatch the committed provisioning outbox messages')]
class RelayProvisioningOutbox extends Command
{
    public function handle(OutboxRelay $relay): int
    {
        $dispatched = $relay->relay();

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} outbox messages.");
        }

        return self::SUCCESS;
    }
}
