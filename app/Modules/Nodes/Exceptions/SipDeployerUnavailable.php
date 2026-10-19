<?php

namespace App\Modules\Nodes\Exceptions;

use RuntimeException;

/**
 * SIP Deployer could not be asked: not configured, unreachable, or it refused the request.
 */
class SipDeployerUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('SIP Deployer is not configured. Set SIP_DEPLOYER_URL and SIP_DEPLOYER_TOKEN.');
    }

    public static function unreachable(string $reason): self
    {
        return new self("SIP Deployer could not be reached: {$reason}");
    }

    public static function refused(int $status): self
    {
        return new self(match ($status) {
            401 => 'SIP Deployer refused the token (401). Issue a new one to this application\'s API client.',
            403 => 'SIP Deployer refused the request (403). Check the token\'s abilities and the API client\'s allowed addresses.',
            default => "SIP Deployer answered with HTTP {$status}.",
        });
    }

    public static function unexpectedResponse(string $what): self
    {
        return new self("SIP Deployer sent an unexpected response for {$what}.");
    }
}
