<?php

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes and removes members of the tenant in the context. Only owners (and platform
 * admins) hand out or take away the owner role, and a tenant always keeps at least one owner.
 */
class ManageMembers
{
    public function __construct(private InviteUser $invite, private Audit $audit, private TenantContext $context) {}

    public function add(User $actor, string $name, string $email, TenantRole $role): TenantMembership
    {
        $this->ensureMayGrant($actor, $role, 'role');

        return DB::transaction(function () use ($name, $email, $role): TenantMembership {
            $user = $this->invite->handle($name, $email);

            if (TenantMembership::query()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This person is already a member.']);
            }

            $membership = TenantMembership::query()->create(['user_id' => $user->id, 'role' => $role]);

            $this->audit->record('member.added', $membership, ['email' => $user->email, 'role' => $role->value]);

            return $membership;
        });
    }

    public function changeRole(User $actor, TenantMembership $membership, TenantRole $role): void
    {
        if ($membership->role === $role) {
            return;
        }

        $this->ensureMayGrant($actor, $role, 'role');
        $this->ensureMayGrant($actor, $membership->role, 'role');
        $this->ensureAnotherOwnerRemains($membership, 'role');

        $previous = $membership->role;
        $membership->update(['role' => $role]);

        $this->audit->record('member.role_changed', $membership, ['email' => $membership->user->email, 'from' => $previous->value, 'to' => $role->value]);
    }

    public function remove(User $actor, TenantMembership $membership): void
    {
        $this->ensureMayGrant($actor, $membership->role, 'member');
        $this->ensureAnotherOwnerRemains($membership, 'member');

        $membership->delete();

        $this->audit->record('member.removed', $membership, ['email' => $membership->user->email, 'role' => $membership->role->value]);
    }

    private function ensureMayGrant(User $actor, TenantRole $role, string $field): void
    {
        if ($role !== TenantRole::Owner || $actor->is_platform_admin) {
            return;
        }

        $tenant = $this->context->tenant();

        if ($tenant === null || $actor->roleIn($tenant) !== TenantRole::Owner) {
            throw ValidationException::withMessages([$field => 'Only an owner can give or take away the owner role.']);
        }
    }

    private function ensureAnotherOwnerRemains(TenantMembership $membership, string $field): void
    {
        if ($membership->role !== TenantRole::Owner) {
            return;
        }

        $otherOwners = TenantMembership::query()
            ->where('role', TenantRole::Owner)
            ->whereKeyNot($membership->id)
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages([$field => 'A tenant needs at least one owner.']);
        }
    }
}
