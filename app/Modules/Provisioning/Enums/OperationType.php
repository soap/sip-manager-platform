<?php

namespace App\Modules\Provisioning\Enums;

enum OperationType: string
{
    case ExtensionCreated = 'extension.created';
    case ExtensionUpdated = 'extension.updated';
    case ExtensionDeleted = 'extension.deleted';
    case CredentialRotated = 'extension.credential_rotated';
    /** The tenant's assignment changed (e.g. suspended), so its numbers are written again. */
    case AssignmentChanged = 'assignment.changed';
    /** Asked again by hand, e.g. after a failure. */
    case Reapplied = 'reapplied';
}
