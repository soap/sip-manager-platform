<?php

namespace App\Modules\Telephony;

use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Telephony\Models\DialNumber;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Hands out the tenant's numbers for any kind of destination in dial_numbers: checks a chosen one
 * and suggests free ones. Every tenant has its own SIP domain and dialplan namespace (plan D6), so
 * a number only has to be free within the tenant.
 */
class NumberAllocator
{
    /**
     * 2–5 digits. Never 0 (outbound calls), 8 (other tenants: 8 + tenant code + number), 9 (kept for
     * outbound prefixes) or a feature code first, and no three-digit 1xx, which are Thai emergency
     * and service numbers (191, 199, …). Five digits at most keep a ring_all Dial() of ten members
     * within one dialplan row.
     */
    public const PATTERN = '/^(?!1\d{0,2}$)[1-7]\d{1,4}$/';

    public const RULE_MESSAGE = 'Use 2 to 5 digits, not starting with 0, 8 or 9, and not a three-digit number starting with 1.';

    /** Four-digit emergency numbers (191 and 199 are 1xx); plan D7 moves them to a table. */
    public const EMERGENCY_NUMBERS = ['1155', '1669'];

    public function __construct(private TenantContext $context) {}

    public static function isValid(string $number): bool
    {
        return preg_match(self::PATTERN, $number) === 1 && ! in_array($number, self::EMERGENCY_NUMBERS, true);
    }

    /**
     * Checks that the tenant in the context may take the number: it has an active assignment, the
     * number follows the numbering rules, and nothing else of the tenant uses it.
     *
     * @throws ValidationException under the given field
     */
    public function ensureAvailable(string $number, string $field): TenantPbxAssignment
    {
        $assignment = $this->context->tenant()?->pbxAssignment;

        if ($assignment === null) {
            throw ValidationException::withMessages([$field => 'This tenant has no PBX node yet. A platform admin assigns one.']);
        }

        if (! $assignment->isActive()) {
            throw ValidationException::withMessages([$field => 'This tenant\'s numbers are suspended.']);
        }

        if (! self::isValid($number)) {
            throw ValidationException::withMessages([$field => self::RULE_MESSAGE]);
        }

        if (DialNumber::query()->where('number', $number)->exists()) {
            throw ValidationException::withMessages([$field => 'This number is already in use.']);
        }

        return $assignment;
    }

    /**
     * The lowest free number of the tenant from the given one up to the end of its length, wrapping
     * around to the start of that length (e.g. 201–999, then 100–200), or null when all are taken.
     * Extensions start at 201, ring groups at 500.
     */
    public function nextFree(int $from): ?string
    {
        if ($this->context->tenant()?->pbxAssignment === null) {
            return null;
        }

        $used = DialNumber::query()->pluck('number')->flip();
        $first = 10 ** (strlen((string) $from) - 1);
        $size = 10 ** strlen((string) $from) - $first;

        for ($offset = 0; $offset < $size; $offset++) {
            $number = (string) ($first + ($from - $first + $offset) % $size);

            if (self::isValid($number) && ! $used->has($number)) {
                return $number;
            }
        }

        return null;
    }
}
