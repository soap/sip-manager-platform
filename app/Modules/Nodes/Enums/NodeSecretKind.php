<?php

namespace App\Modules\Nodes\Enums;

/**
 * The endpoints of a node that this application logs in to, named as SIP Deployer's manifest and
 * credentials API name them.
 */
enum NodeSecretKind: string
{
    case OpenSipsDb = 'opensips_db';
    case AsteriskDb = 'asterisk_db';
    case Ami = 'ami';
    case Ari = 'ari';
}
