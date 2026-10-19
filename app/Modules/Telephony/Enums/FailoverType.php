<?php

namespace App\Modules\Telephony\Enums;

/**
 * Where an unanswered ring group call goes. Voicemail joins once voicemail is provisioned.
 */
enum FailoverType: string
{
    case Hangup = 'hangup';
    case Extension = 'extension';
    case RingGroup = 'ring_group';
}
