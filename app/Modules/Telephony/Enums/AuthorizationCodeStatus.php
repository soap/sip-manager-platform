<?php

namespace App\Modules\Telephony\Enums;

enum AuthorizationCodeStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    /** Its expires_at passed; set by authorization-codes:expire, which takes it off the node. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
            self::Expired => 'Expired',
        };
    }
}
