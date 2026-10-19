<?php

namespace App\Modules\Provisioning\Enums;

enum OperationType: string
{
    case ExtensionCreated = 'extension.created';
    case ExtensionUpdated = 'extension.updated';
    case ExtensionDeleted = 'extension.deleted';
    case CredentialRotated = 'extension.credential_rotated';
    case RingGroupCreated = 'ring_group.created';
    case RingGroupUpdated = 'ring_group.updated';
    case RingGroupDeleted = 'ring_group.deleted';
    /** A member or failover target of a ring group changed or went away. */
    case DependencyChanged = 'dependency.changed';
    /** The tenant's assignment changed (e.g. suspended), so its numbers are written again. */
    case AssignmentChanged = 'assignment.changed';
    /** Asked again by hand, e.g. after a failure. */
    case Reapplied = 'reapplied';
}
