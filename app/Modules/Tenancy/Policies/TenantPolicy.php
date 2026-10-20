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

    /**
     * Extensions (and later devices and routes): the same people who manage members.
     */
    public function manageExtensions(User $user, Tenant $tenant): bool
    {
        return $this->manageMembers($user, $tenant);
    }

    /**
     * API tokens act with the role of the user who issued them, so the same people as members.
     */
    public function manageApiTokens(User $user, Tenant $tenant): bool
    {
        return $this->manageMembers($user, $tenant);
    }

    public function viewAuditLog(User $user, Tenant $tenant): bool
    {
        return $this->manageMembers($user, $tenant);
    }
}
