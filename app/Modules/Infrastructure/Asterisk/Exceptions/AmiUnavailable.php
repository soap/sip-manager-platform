<?php

namespace App\Modules\Infrastructure\Asterisk\Exceptions;

use RuntimeException;

/**
 * AMI could not be reached, did not answer in time or closed the connection. Worth retrying.
 */
class AmiUnavailable extends RuntimeException
{
    public static function cannotConnect(string $host, int $port, string $reason): self
    {
        return new self("Could not connect to AMI at {$host}:{$port}: {$reason}");
    }

    public static function notAmi(): self
    {
        return new self('The port does not answer as AMI.');
    }

    public static function timedOut(): self
    {
        return new self('AMI did not answer in time.');
    }

    public static function closed(): self
    {
        return new self('AMI closed the connection.');
    }
}
