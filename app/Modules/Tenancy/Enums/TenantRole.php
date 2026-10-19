<?php

namespace App\Modules\Tenancy\Enums;

/**
 * A user's role in a tenant. Owners and admins manage members; only owners hand out or take
 * away the owner role.
 */
enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
        };
    }

    public function canManageMembers(): bool
    {
        return $this !== self::Member;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $role): array => ['value' => $role->value, 'label' => $role->label()], self::cases());
    }
}
