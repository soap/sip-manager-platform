<?php

namespace App\Modules\Provisioning\Enums;

enum RevisionStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
    /** A newer revision of the same target came first; it carries this one's change too. */
    case Superseded = 'superseded';
}
