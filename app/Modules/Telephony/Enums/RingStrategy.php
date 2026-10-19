<?php

namespace App\Modules\Telephony\Enums;

enum RingStrategy: string
{
    /** Every member rings at once; the first to answer takes the call. */
    case RingAll = 'ring_all';
    /** Members ring one after another, in their order, each for the ring time. */
    case Sequential = 'sequential';

    public function label(): string
    {
        return match ($this) {
            self::RingAll => 'Ring all at once',
            self::Sequential => 'One after another',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $strategy): array => ['value' => $strategy->value, 'label' => $strategy->label()], self::cases());
    }
}
