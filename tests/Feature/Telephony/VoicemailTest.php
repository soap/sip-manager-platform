<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Enums\FailoverType;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\RingGroup;
use App\Modules\Tenancy\Enums\TenantRole;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('turns voicemail on with a PIN shown once and writes the mailbox', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '20101', 'display_name' => 'Anna']);

    $this->patch(route('tenant.extensions.voicemail', [$tenant, $extension->id]), ['enabled' => true])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('voicemailPin.extension_number', '20101')
        ->assertInertiaFlash('voicemailPin.feature_code', '*97');

    $pin = $extension->fresh()->voicemail_pin;

    expect($pin)->toMatch('/^\d{6}$/')
        ->and(DB::table('extensions')->value('voicemail_pin'))->not->toContain($pin)
        ->and(AuditLog::query()->get()->toJson())->not->toContain($pin);

    applyPendingRevisions();

    // each tenant has its own voicemail context, t and its code
    expect($node->mailboxes['20101@t1020'])->toBe(['context' => 't1020', 'mailbox' => '20101', 'password' => $pin, 'fullname' => 'Anna'])
        ->and($node->extens['102020101'])->toBe([
            ['priority' => 1, 'app' => 'Dial', 'appdata' => 'PJSIP/102020101@opensips,30'],
            ['priority' => 2, 'app' => 'VoiceMail', 'appdata' => '20101@t1020,${IF($["${DIALSTATUS}" = "BUSY"]?b:u)}'],
            ['priority' => 3, 'app' => 'Hangup', 'appdata' => ''],
        ])
        // *97 of a phone of the tenant reaches Asterisk with the tenant code first
        ->and($node->extens['1020*97'])->toBe([
            ['priority' => 1, 'app' => 'VoiceMailMain', 'appdata' => '${CALLERID(num)}@t1020'],
            ['priority' => 2, 'app' => 'Hangup', 'appdata' => ''],
        ]);
});

it('resets the PIN and turns voicemail off again', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '20101']);
    $this->patch(route('tenant.extensions.voicemail', [$tenant, $extension->id]), ['enabled' => true]);
    applyPendingRevisions();
    $first = $node->mailboxes['20101@t1020']['password'];

    $this->post(route('tenant.extensions.voicemail-pin', [$tenant, $extension->id]))
        ->assertInertiaFlash('voicemailPin.extension_number', '20101');
    applyPendingRevisions();

    expect($node->mailboxes['20101@t1020']['password'])->not->toBe($first)
        ->and($node->mailboxes['20101@t1020']['password'])->toBe($extension->fresh()->voicemail_pin);

    $this->patch(route('tenant.extensions.voicemail', [$tenant, $extension->id]), ['enabled' => false])
        ->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect($node->mailboxes)->toBe([])
        ->and(array_column($node->extens['102020101'], 'app'))->toBe(['Dial', 'Hangup'])
        ->and($extension->fresh()->voicemail_pin)->toBeNull();
});

it('removes the mailbox of a disabled or deleted extension', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '20101']);
    $this->patch(route('tenant.extensions.voicemail', [$tenant, $extension->id]), ['enabled' => true]);
    applyPendingRevisions();

    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => $extension->display_name, 'status' => 'disabled']);
    applyPendingRevisions();

    expect($node->mailboxes)->toBe([]);
});

it('refuses voicemail on a node without it', function () {
    fakeNode();
    $assignment = TenantPbxAssignment::factory()->code('1020')->create();
    $assignment->node->update(['services' => [...$assignment->node->services, 'asterisk' => [...$assignment->node->services['asterisk'], 'features' => ['realtime']]]]);
    [$tenant] = provisioningTenant($assignment);
    $extension = Extension::factory()->on($assignment)->create();

    $this->patch(route('tenant.extensions.voicemail', [$tenant, $extension->id]), ['enabled' => true])
        ->assertSessionHasErrors(['voicemail' => 'The PBX node of this tenant has no voicemail.']);

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page->where('assignment.voicemail', false));
});

it('leaves a message in a mailbox when nobody in a ring group answers', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '20101']);

    $this->post(route('tenant.ring-groups.store', $tenant), [
        'number' => '20500', 'name' => 'Sales', 'strategy' => 'ring_all', 'ring_seconds' => 20,
        'members' => [$extension->id], 'failover_type' => 'voicemail', 'failover_id' => $extension->id,
    ])->assertSessionHasErrors(['failover_id' => 'This extension has no voicemail.']);

    $this->patch(route('tenant.extensions.voicemail', [$tenant, $extension->id]), ['enabled' => true]);
    $this->post(route('tenant.ring-groups.store', $tenant), [
        'number' => '20500', 'name' => 'Sales', 'strategy' => 'ring_all', 'ring_seconds' => 20,
        'members' => [$extension->id], 'failover_type' => 'voicemail', 'failover_id' => $extension->id,
    ])->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect(array_slice($node->extens['102020500'], 1))->toBe([
        ['priority' => 2, 'app' => 'VoiceMail', 'appdata' => '20101@t1020,u'],
        ['priority' => 3, 'app' => 'Hangup', 'appdata' => ''],
    ]);

    // turning the mailbox off rewrites the group, which then hangs up
    $this->patch(route('tenant.extensions.voicemail', [$tenant, $extension->id]), ['enabled' => false]);
    applyPendingRevisions();

    expect(ProvisioningOperation::query()->where('aggregate_type', RingGroup::MORPH)->where('type', OperationType::DependencyChanged)->exists())->toBeTrue()
        ->and(array_column($node->extens['102020500'], 'app'))->toBe(['Dial', 'Hangup']);

    $this->delete(route('tenant.extensions.destroy', [$tenant, $extension->id]));

    expect(RingGroup::withoutTenantScope()->sole()->failover_type)->toBe(FailoverType::Hangup);
});

it('lets only owners and admins change voicemail', function () {
    [$tenant, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create();
    $member = signIn();
    TenantMembership::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'user_id' => $member->id, 'role' => TenantRole::Member]);

    $this->patch(route('tenant.extensions.voicemail', [$tenant, $extension->id]), ['enabled' => true])->assertForbidden();
    $this->post(route('tenant.extensions.voicemail-pin', [$tenant, $extension->id]))->assertForbidden();
});
