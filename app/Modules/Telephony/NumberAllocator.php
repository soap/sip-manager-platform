<?php

namespace App\Modules\Telephony;

use App\Modules\Nodes\Models\TenantNumberRange;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Hands out numbers in the tenant's prefix for any kind of destination in dial_numbers: checks a
 * chosen one and suggests free ones.
 */
class NumberAllocator
{
    public function __construct(private TenantContext $context) {}

    /**
     * Checks that the tenant in the context may take the number on its node: it has an active
     * assignment, the number carries its prefix, and nothing else on the node uses it.
     *
     * @throws ValidationException under the given field
     */
    public function ensureAvailable(string $number, string $field): TenantPbxAssignment
    {
        $tenant = $this->context->tenant();
        $assignment = $tenant?->pbxAssignment;
        $range = $tenant?->numberRange;

        if ($assignment === null || $range === null) {
            throw ValidationException::withMessages([$field => 'This tenant has no PBX node yet. A platform admin assigns one.']);
        }

        if (! $assignment->isActive()) {
            throw ValidationException::withMessages([$field => 'This tenant\'s numbers are suspended.']);
        }

        if (! $range->contains($number)) {
            throw ValidationException::withMessages([$field => "Use a number from {$range->label()}."]);
        }

        if (DialNumber::withoutTenantScope()->where('pbx_node_id', $assignment->pbx_node_id)->where('number', $number)->exists()) {
            throw ValidationException::withMessages([$field => 'This number is already in use.']);
        }

        return $assignment;
    }

    /**
     * The lowest free number from PP<from> up, wrapping around to PP000, or null when the
     * tenant's thousand numbers are all taken. Extensions start at 001, ring groups at 500.
     */
    public function nextFree(int $from = 1): ?string
    {
        $range = $this->context->tenant()?->numberRange;

        if ($range === null) {
            return null;
        }

        $used = DialNumber::query()->pluck('number')->flip();
        $size = 10 ** TenantNumberRange::EXTENSION_DIGITS;

        for ($offset = 0; $offset < $size; $offset++) {
            $number = $range->prefix.str_pad((string) (($from + $offset) % $size), TenantNumberRange::EXTENSION_DIGITS, '0', STR_PAD_LEFT);

            if (! $used->has($number)) {
                return $number;
            }
        }

        return null;
    }
}
