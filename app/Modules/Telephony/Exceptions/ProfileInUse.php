<?php

namespace App\Modules\Telephony\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A calling profile that extensions use, or the tenant's default, cannot be deleted. A validation
 * error for the web pages; the API answers 409 Conflict.
 */
class ProfileInUse extends ValidationException
{
    public static function byExtensions(int $count): self
    {
        /** @var self */
        return self::withMessages(['profile' => "{$count} extension(s) use this profile. Give them another one first."]);
    }

    public static function asDefault(): self
    {
        /** @var self */
        return self::withMessages(['profile' => 'This is the default profile. Make another one the default first.']);
    }
}
