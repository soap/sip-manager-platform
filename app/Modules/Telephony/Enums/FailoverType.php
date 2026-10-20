<?php

namespace App\Modules\Telephony\Enums;

/**
 * Where an unanswered ring group call goes. Voicemail is the mailbox of an extension (failover_id
 * is the extension), which must have voicemail on.
 */
enum FailoverType: string
{
    case Hangup = 'hangup';
    case Extension = 'extension';
    case RingGroup = 'ring_group';
    case Voicemail = 'voicemail';
}
