<?php

use App\Models\User;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;

/*
| Helpers for tests of API v1. Loaded by tests/Pest.php.
*/

/**
 * A member of a tenant on a node and an API token issued in it.
 *
 * @param  list<string>  $abilities
 * @return array{token: string, user: User, assignment: TenantPbxAssignment, tenant: Tenant}
 */
function apiClient(TenantRole $role = TenantRole::Admin, array $abilities = ['read', 'write'], ?TenantPbxAssignment $assignment = null): array
{
    $assignment ??= TenantPbxAssignment::factory()->create();
    $user = User::factory()->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $assignment->tenant_id, 'user_id' => $user->id, 'role' => $role]);

    $issued = $user->createToken('CRM', $abilities);
    $issued->accessToken->forceFill(['tenant_id' => $assignment->tenant_id])->save();

    // a new token in the same test must not reuse the user of the last request
    app('auth')->forgetGuards();

    return ['token' => $issued->plainTextToken, 'user' => $user, 'assignment' => $assignment, 'tenant' => $assignment->tenant];
}
