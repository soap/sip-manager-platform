<?php

namespace App\Modules\Telephony\Enums;

/**
 * Which other tenants a calling profile may call, among those that take calls from its tenant.
 */
enum CrossTenantScope: string
{
    case All = 'all';
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Every tenant that takes our calls',
            self::Selected => 'Only the selected tenants',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $scope): array => ['value' => $scope->value, 'label' => $scope->label()], self::cases());
    }
}
