<?php

namespace App\Modules\Tenancy\Enums;

/**
 * What an API token may do. Write also needs the owner and admin role of the token's user.
 */
enum ApiAbility: string
{
    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read',
            self::Write => 'Write',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $ability): array => ['value' => $ability->value, 'label' => $ability->label()], self::cases());
    }
}
