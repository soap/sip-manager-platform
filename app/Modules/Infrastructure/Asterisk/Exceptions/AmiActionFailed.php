<?php

namespace App\Modules\Infrastructure\Asterisk\Exceptions;

use RuntimeException;

/**
 * Asterisk answered an action with "Response: Error": the login was refused, the user lacks the
 * privilege, or the action or function does not exist. Retrying will not help.
 */
class AmiActionFailed extends RuntimeException
{
    public function __construct(public readonly string $action, string $message)
    {
        parent::__construct($message);
    }

    public static function for(string $action, ?string $reason): self
    {
        return new self($action, "AMI refused {$action}: ".($reason ?? 'no reason given').'.');
    }

    public function isLogin(): bool
    {
        return strcasecmp($this->action, 'Login') === 0;
    }
}
