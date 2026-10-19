<?php

namespace App\Modules\Provisioning\Enums;

enum OperationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Applied = 'applied';
    case Retrying = 'retrying';
    case Failed = 'failed';
    /** A newer operation on the same number was applied in its place. */
    case Superseded = 'superseded';

    public function isFinished(): bool
    {
        return in_array($this, [self::Applied, self::Failed, self::Superseded], true);
    }
}
