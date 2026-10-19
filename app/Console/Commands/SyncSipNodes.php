<?php

namespace App\Console\Commands;

use App\Modules\Nodes\Actions\SyncNodes;
use App\Modules\Nodes\Exceptions\SipDeployerUnavailable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs on the schedule, and by hand after a node is provisioned or its passwords are rotated.
 */
#[Signature('app:sync-sip-nodes')]
#[Description('Copy the OpenSIPS and Asterisk nodes and their credentials from SIP Deployer')]
class SyncSipNodes extends Command
{
    public function handle(SyncNodes $syncNodes): int
    {
        try {
            $result = $syncNodes->handle();
        } catch (SipDeployerUnavailable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Synced {$result['synced']} nodes: {$result['added']} new, {$result['removed']} removed, {$result['credentials_updated']} with new credentials.");

        foreach ($result['skipped'] as $deployerId) {
            $this->warn("Skipped node {$deployerId}: its manifest version is not supported.");
        }

        foreach ($result['credential_errors'] as $deployerId => $error) {
            $this->warn("Credentials of node {$deployerId} were not fetched: {$error}");
        }

        return $result['credential_errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
