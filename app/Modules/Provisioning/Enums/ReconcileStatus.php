<?php

namespace App\Modules\Provisioning\Enums;

enum ReconcileStatus: string
{
    case Running = 'running';
    /** The node holds exactly what it should. */
    case Clean = 'clean';
    /** Rows were missing, changed or unexpected. */
    case Drift = 'drift';
    /** The node could not be read. */
    case Failed = 'failed';
}
