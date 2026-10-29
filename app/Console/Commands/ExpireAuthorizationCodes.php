<?php

namespace App\Console\Commands;

use App\Modules\Telephony\Actions\ManageAuthorizationCodes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute on the schedule. The dialplan only holds usable codes, so a code past its
 * expiry keeps working on the node until this takes it off.
 */
#[Signature('authorization-codes:expire')]
#[Description('Expire the authorization codes past their expiry and take them off the nodes')]
class ExpireAuthorizationCodes extends Command
{
    public function handle(ManageAuthorizationCodes $codes): int
    {
        $expired = $codes->expireDue();

        if ($expired > 0) {
            $this->info("Expired {$expired} authorization codes.");
        }

        return self::SUCCESS;
    }
}
