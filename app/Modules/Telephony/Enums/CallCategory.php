<?php

namespace App\Modules\Telephony\Enums;

/**
 * Where a call goes, beyond the tenant's own numbers (plan D7). A calling profile sets a
 * CallPermission for each; an authorization code lists the ones it unlocks. On the node each is a
 * flag letter in the Return() of the caller's permission exten.
 */
enum CallCategory: string
{
    /** 8 + another tenant's code + its number, e.g. 810421001 (plan D6). */
    case CrossTenant = 'cross_tenant';
    case Landline = 'landline';
    case Mobile = 'mobile';
    case TollFree = 'toll_free';
    /** Four-digit hotlines dialed with a leading 9, since 1xxx can be an extension. */
    case Hotline = 'hotline';
    case International = 'international';
    case Premium = 'premium';

    /**
     * The flag on the node: lowercase when allowed, uppercase when it needs a code.
     */
    public function flag(): string
    {
        return match ($this) {
            self::CrossTenant => 'x',
            self::Landline => 'l',
            self::Mobile => 'm',
            self::TollFree => 'f',
            self::Hotline => 'h',
            self::International => 'i',
            self::Premium => 'p',
        };
    }

    /**
     * What the caller dials, as an Asterisk pattern without the leading "_" and the tenant code.
     */
    public function pattern(): string
    {
        return match ($this) {
            self::CrossTenant => '8XXXX.',
            self::Landline => '0[2-7]XXXXXXX',
            self::Mobile => '0[689]XXXXXXXX',
            self::TollFree => '1800XXXXXX',
            self::Hotline => '91XXX',
            self::International => '00X.',
            self::Premium => '1900XXXXXX',
        };
    }

    /**
     * Whether the call leaves the platform, which needs a trunk (Phase 4).
     */
    public function needsTrunk(): bool
    {
        return $this !== self::CrossTenant;
    }

    public function label(): string
    {
        return match ($this) {
            self::CrossTenant => 'Other tenants',
            self::Landline => 'Landline',
            self::Mobile => 'Mobile',
            self::TollFree => 'Toll-free (1800)',
            self::Hotline => 'Hotlines (9 + 1xxx)',
            self::International => 'International',
            self::Premium => 'Premium (1900)',
        };
    }

    /**
     * @return list<array{value: string, label: string, needs_trunk: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category): array => [
            'value' => $category->value,
            'label' => $category->label(),
            'needs_trunk' => $category->needsTrunk(),
        ], self::cases());
    }
}
