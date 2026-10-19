<?php

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Platform admins see and manage every tenant; other users only the tenants they belong to.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_platform_admin;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin || $user->roleIn($tenant) !== null;
    }

    public function manageMembers(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin || ($user->roleIn($tenant)?->canManageMembers() ?? false);
    }

    public function viewAuditLog(User $user, Tenant $tenant): bool
    {
        return $this->manageMembers($user, $tenant);
    }
}
