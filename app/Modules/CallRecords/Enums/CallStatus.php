<?php

namespace App\Modules\CallRecords\Enums;

enum CallStatus: string
{
    case Answered = 'answered';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    /** Asterisk could not place the call, e.g. congestion or an unreachable phone. */
    case Failed = 'failed';
    /** The dialplan refused the call (exten <code>denied): no permission, wrong code or disabled number. */
    case Denied = 'denied';

    /**
     * The status of a CDR row from its disposition, or Denied when it ended in a denied exten, the
     * tenant's or the old shared one (which answers the caller to play the announcement, so its
     * disposition says ANSWERED).
     */
    public static function fromCdr(?string $disposition, ?string $dst): self
    {
        if ($dst !== null && preg_match('/^(\d{4})?denied$/', $dst) === 1) {
            return self::Denied;
        }

        return match (strtoupper((string) $disposition)) {
            'ANSWERED' => self::Answered,
            'BUSY' => self::Busy,
            'NO ANSWER' => self::NoAnswer,
            default => self::Failed,
        };
    }
}
