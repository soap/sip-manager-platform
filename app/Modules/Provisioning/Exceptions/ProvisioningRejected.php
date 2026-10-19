<?php

namespace App\Modules\Provisioning\Exceptions;

use RuntimeException;

/**
 * The change cannot be written as it stands (validation, missing capability); retrying will not
 * help until someone fixes the cause.
 */
class ProvisioningRejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $errorCode, string $message): self
    {
        return new self($errorCode, $message);
    }
}
