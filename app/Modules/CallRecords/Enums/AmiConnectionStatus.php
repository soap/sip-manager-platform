<?php

namespace App\Modules\CallRecords\Enums;

enum AmiConnectionStatus: string
{
    case Connecting = 'connecting';
    case Connected = 'connected';
    /** The connection failed or dropped; the listener tries again with backoff. */
    case Disconnected = 'disconnected';
    /** The listener was stopped, or the node no longer has AMI. */
    case Stopped = 'stopped';
}
