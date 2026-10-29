<?php

namespace App\Modules\Nodes\Policies;

use App\Models\User;
use App\Modules\Nodes\Models\PbxNode;

/**
 * SIP nodes are platform infrastructure shared by tenants, so only platform admins see or touch them.
 */
class PbxNodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function sync(User $user): bool
    {
        return $user->is_platform_admin;
    }

    /**
     * Placing tenants on nodes and handing out their blocks of numbers.
     */
    public function manageAssignments(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function check(User $user, PbxNode $node): bool
    {
        return $user->is_platform_admin;
    }

    /**
     * Comparing the node's rows with the desired state and writing drifted targets again.
     */
    public function reconcile(User $user, PbxNode $node): bool
    {
        return $user->is_platform_admin;
    }
}
