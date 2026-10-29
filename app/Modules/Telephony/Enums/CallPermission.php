<?php

namespace App\Modules\Telephony\Enums;

/**
 * What a calling profile lets its extensions do for one CallCategory (plan D7).
 */
enum CallPermission: string
{
    case Allow = 'allow';
    /** Only after the caller enters an authorization code that unlocks the category. */
    case Code = 'code';
    /** Never, not even with a code. */
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allowed',
            self::Code => 'With a code',
            self::Deny => 'Not allowed',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $permission): array => ['value' => $permission->value, 'label' => $permission->label()], self::cases());
    }
}
