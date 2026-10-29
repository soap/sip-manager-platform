<?php

namespace App\Modules\Tenancy\Enums;

/**
 * Which other tenants may call this tenant's numbers (plan D6). The caller's calling profile must
 * allow it too.
 */
enum CrossTenantInbound: string
{
    case None = 'none';
    case All = 'all';
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No other tenant',
            self::All => 'Every tenant',
            self::Selected => 'Only the selected tenants',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $inbound): array => ['value' => $inbound->value, 'label' => $inbound->label()], self::cases());
    }
}
