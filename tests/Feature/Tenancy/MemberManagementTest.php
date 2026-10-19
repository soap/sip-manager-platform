<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/**
 * A tenant with the signed-in user in the role, and the user's membership.
 */
function tenantWithSignedIn(TenantRole $role): array
{
    $user = signIn();
    $tenant = Tenant::factory()->withMember($user, $role)->create();

    return [$tenant, $user];
}

function membershipOf(Tenant $tenant, User $user): TenantMembership
{
    return TenantMembership::withoutTenantScope()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->sole();
}

it('invites a new person by email and records it without secrets', function () {
    Notification::fake();
    [$tenant] = tenantWithSignedIn(TenantRole::Admin);

    $this->post(route('tenant.members.store', $tenant), ['name' => 'Ann', 'email' => 'Ann@Example.com', 'role' => 'member'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $ann = User::query()->where('email', 'ann@example.com')->sole();

    expect(membershipOf($tenant, $ann)->role)->toBe(TenantRole::Member);
    Notification::assertSentTo($ann, ResetPassword::class);

    $entry = AuditLog::query()->where('action', 'member.added')->sole();
    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->summary)->toBe(['email' => 'ann@example.com', 'role' => 'member']);
});

it('adds an existing user without inviting them again', function () {
    Notification::fake();
    [$tenant] = tenantWithSignedIn(TenantRole::Owner);
    $existing = User::factory()->create();

    $this->post(route('tenant.members.store', $tenant), ['name' => 'Ignored', 'email' => $existing->email, 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect(membershipOf($tenant, $existing)->role)->toBe(TenantRole::Admin);
    Notification::assertNothingSent();
});

it('refuses to add someone twice', function () {
    [$tenant, $user] = tenantWithSignedIn(TenantRole::Owner);

    $this->post(route('tenant.members.store', $tenant), ['name' => $user->name, 'email' => $user->email, 'role' => 'member'])
        ->assertSessionHasErrors(['email' => 'This person is already a member.']);
});

it('validates a new member', function () {
    [$tenant] = tenantWithSignedIn(TenantRole::Owner);

    $this->post(route('tenant.members.store', $tenant), ['name' => '', 'email' => 'not-an-email', 'role' => 'superuser'])
        ->assertSessionHasErrors(['name', 'email', 'role']);
});

it('does not let plain members manage members', function () {
    [$tenant, $user] = tenantWithSignedIn(TenantRole::Member);

    $this->post(route('tenant.members.store', $tenant), ['name' => 'Ann', 'email' => 'ann@example.com', 'role' => 'member'])
        ->assertForbidden();
    $this->delete(route('tenant.members.destroy', [$tenant, membershipOf($tenant, $user)->id]))
        ->assertForbidden();
});

it('answers 404 for a membership of another tenant', function () {
    [$tenant] = tenantWithSignedIn(TenantRole::Owner);
    $other = Tenant::factory()->withMember($stranger = User::factory()->create(), TenantRole::Member)->create();
    $foreign = membershipOf($other, $stranger);

    $this->patch(route('tenant.members.update', [$tenant, $foreign->id]), ['role' => 'admin'])->assertNotFound();
    $this->delete(route('tenant.members.destroy', [$tenant, $foreign->id]))->assertNotFound();

    expect($foreign->fresh()->role)->toBe(TenantRole::Member);
});

it('lets only owners give the owner role', function (TenantRole $actorRole, bool $allowed) {
    [$tenant] = tenantWithSignedIn($actorRole);
    $member = User::factory()->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $member->id, 'role' => TenantRole::Member]);
    TenantMembership::withoutTenantScope()->firstOrCreate(['tenant_id' => $tenant->id, 'role' => TenantRole::Owner], ['user_id' => User::factory()->create()->id]);

    $response = $this->patch(route('tenant.members.update', [$tenant, membershipOf($tenant, $member)->id]), ['role' => 'owner']);

    if ($allowed) {
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasErrors(['role' => 'Only an owner can give or take away the owner role.']);
    }

    expect(membershipOf($tenant, $member)->role)->toBe($allowed ? TenantRole::Owner : TenantRole::Member);
})->with([
    'owner' => [TenantRole::Owner, true],
    'admin' => [TenantRole::Admin, false],
]);

it('keeps at least one owner', function () {
    [$tenant, $owner] = tenantWithSignedIn(TenantRole::Owner);
    $membership = membershipOf($tenant, $owner);

    $this->patch(route('tenant.members.update', [$tenant, $membership->id]), ['role' => 'admin'])
        ->assertSessionHasErrors(['role' => 'A tenant needs at least one owner.']);
    $this->delete(route('tenant.members.destroy', [$tenant, $membership->id]))
        ->assertSessionHasErrors(['member' => 'A tenant needs at least one owner.']);

    expect($membership->fresh()->role)->toBe(TenantRole::Owner);
});

it('removes a member and records it', function () {
    [$tenant] = tenantWithSignedIn(TenantRole::Admin);
    $leaving = User::factory()->create();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $leaving->id, 'role' => TenantRole::Member]);

    $this->delete(route('tenant.members.destroy', [$tenant, membershipOf($tenant, $leaving)->id]))->assertSessionHasNoErrors();

    expect(TenantMembership::withoutTenantScope()->where('user_id', $leaving->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'member.removed')->value('summary'))->toBe(['email' => $leaving->email, 'role' => 'member']);
});
