<?php

namespace App\Modules\Telephony\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * The change was made against an older version of the record (optimistic lock). A validation
 * error for the web pages; the API answers 409 Conflict.
 */
class StaleVersion extends ValidationException
{
    public static function of(string $field, string $what): self
    {
        /** @var self */
        return self::withMessages([$field => "Someone else changed this {$what}. Reload and try again."]);
    }
}
