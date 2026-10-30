<?php

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Nodes\Models\TenantPbxAssignment;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Enums\RevisionStatus;
use App\Modules\Provisioning\Models\ConfigRevision;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Enums\AuthorizationCodeStatus;
use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Enums\CallPermission;
use App\Modules\Telephony\Enums\CrossTenantScope;
use App\Modules\Telephony\Models\AuthorizationCode;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Enums\CrossTenantInbound;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Requests the tenant's calling dialplan, as assigning it to its node does.
 */
function provisionCalling(TenantPbxAssignment $assignment): void
{
    DB::transaction(fn () => app(RequestProvisioning::class)->forCalling($assignment->pbx_node_id, $assignment->tenant_id, OperationType::Reapplied, TenantPbxAssignment::MORPH, $assignment->id));
}

/**
 * Another tenant on the same node, taking calls from every tenant unless told otherwise.
 */
function neighbour(TenantPbxAssignment $assignment, string $code, CrossTenantInbound $inbound = CrossTenantInbound::All): Tenant
{
    $tenant = Tenant::factory()->create(['code' => $code, 'cross_tenant_inbound' => $inbound]);
    TenantPbxAssignment::factory()->create(['tenant_id' => $tenant->id, 'pbx_node_id' => $assignment->pbx_node_id]);

    return $tenant;
}

it('writes the flags of the extension\'s calling profile to its permission exten', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $profile = CallingProfile::factory()->for($tenant)->permissions([
        CallCategory::CrossTenant->value => CallPermission::Allow,
        CallCategory::Landline->value => CallPermission::Allow,
        CallCategory::Mobile->value => CallPermission::Code,
        CallCategory::International->value => CallPermission::Deny,
    ])->create();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '201', 'calling_profile_id' => $profile->id]);

    $this->post(route('tenant.extensions.reapply', [$tenant, $extension->id]));
    applyPendingRevisions();

    // lowercase allowed, uppercase with a code, "a" for every tenant that takes our calls
    expect($node->extens['1020p201'])->toBe([['priority' => 1, 'app' => 'Return', 'appdata' => '/xa/l/M/']])
        ->and(ConfigRevision::query()->sole()->compiled['permissions'])->toBe($node->extens['1020p201']);
});

it('uses the tenant\'s default profile, and allows nothing beyond the tenant without one', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '201']);

    $this->post(route('tenant.extensions.reapply', [$tenant, $extension->id]));
    applyPendingRevisions();
    expect($node->extens['1020p201'][0]['appdata'])->toBe('/');

    CallingProfile::factory()->for($tenant)->default()->crossTenant(CallPermission::Code)->create();
    $this->post(route('tenant.extensions.reapply', [$tenant, $extension->id]));
    applyPendingRevisions();
    expect($node->extens['1020p201'][0]['appdata'])->toBe('/XA/');
});

it('lists the selected tenants of a profile limited to them', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $profile = CallingProfile::factory()->for($tenant)->crossTenant(CallPermission::Code)->create(['cross_tenant_scope' => CrossTenantScope::Selected]);
    $profile->destinations()->attach([neighbour($assignment, '1050')->id, neighbour($assignment, '1042')->id]);
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '201', 'calling_profile_id' => $profile->id]);

    $this->post(route('tenant.extensions.reapply', [$tenant, $extension->id]));
    applyPendingRevisions();

    expect($node->extens['1020p201'][0]['appdata'])->toBe('/X1042/X1050/');
});

it('takes the permission exten away from a disabled extension, so it calls nowhere outside the tenant', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '201']);
    $node->extens['1020p201'] = [['priority' => 1, 'app' => 'Return', 'appdata' => '/xa/']];

    $this->patch(route('tenant.extensions.update', [$tenant, $extension->id]), ['display_name' => 'Anna', 'status' => 'disabled']);
    applyPendingRevisions();

    expect($node->extens)->not->toHaveKey('1020p201');
});

it('gives an extension another calling profile and writes it to the node', function () {
    fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $profile = CallingProfile::factory()->for($tenant)->crossTenant()->create(['name' => 'Other tenants']);
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '201']);

    $this->patch(route('tenant.extensions.calling-profile', [$tenant, $extension->id]), ['calling_profile_id' => $profile->id, 'version' => 1])
        ->assertSessionHasNoErrors();

    expect($extension->fresh())->calling_profile_id->toBe($profile->id)->version->toBe(2)
        ->and(ProvisioningOperation::query()->sole()->type)->toBe(OperationType::CallingProfileChanged)
        ->and(AuditLog::query()->where('action', 'extension.calling_profile_changed')->sole()->summary)->toMatchArray(['from' => null, 'to' => 'Other tenants']);
});

it('refuses a calling profile of another tenant', function () {
    fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $foreign = CallingProfile::factory()->create();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '201']);

    $this->patch(route('tenant.extensions.calling-profile', [$tenant, $extension->id]), ['calling_profile_id' => $foreign->id])
        ->assertSessionHasErrors('calling_profile_id');

    expect($extension->fresh()->calling_profile_id)->toBeNull();
});

it('writes every extension that uses a profile again when the profile changes', function () {
    fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $profile = CallingProfile::factory()->for($tenant)->default()->create(['name' => 'Staff']);
    Extension::factory()->on($assignment)->create(['extension_number' => '201', 'calling_profile_id' => $profile->id]);
    Extension::factory()->on($assignment)->create(['extension_number' => '202']);
    Extension::factory()->on($assignment)->create(['extension_number' => '203', 'calling_profile_id' => CallingProfile::factory()->for($tenant)->create()->id]);
    neighbour($assignment, '1042');

    $this->patch(route('tenant.calling-profiles.update', [$tenant, $profile->id]), [
        'name' => 'Staff',
        'categories' => ['cross_tenant' => 'code', 'mobile' => 'allow', 'international' => 'deny'],
        'cross_tenant_scope' => 'selected',
        'destinations' => ['1042'],
        'version' => 1,
    ])->assertSessionHasNoErrors();

    // 201 uses it, 202 inherits it as the default, 203 has its own
    expect(ConfigRevision::query()->orderBy('id')->pluck('target')->all())->toBe(['number:1020201', 'number:1020202'])
        ->and($profile->fresh())->categories->toBe(['cross_tenant' => 'code', 'mobile' => 'allow'])->version->toBe(2)
        ->and($profile->fresh()->flags())->toBe('/X1042/m/');
});

it('refuses a stale profile, an unknown destination and a deleted one in use', function () {
    fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $profile = CallingProfile::factory()->for($tenant)->create(['version' => 3]);
    $default = CallingProfile::factory()->for($tenant)->default()->create();
    Extension::factory()->on($assignment)->create(['extension_number' => '201', 'calling_profile_id' => $profile->id]);

    $this->patch(route('tenant.calling-profiles.update', [$tenant, $profile->id]), ['name' => 'X', 'categories' => [], 'version' => 2])
        ->assertSessionHasErrors('profile');
    $this->patch(route('tenant.calling-profiles.update', [$tenant, $profile->id]), ['name' => 'X', 'categories' => [], 'cross_tenant_scope' => 'selected', 'destinations' => ['9999']])
        ->assertSessionHasErrors('destinations');
    $this->delete(route('tenant.calling-profiles.destroy', [$tenant, $profile->id]))->assertSessionHasErrors('profile');
    $this->delete(route('tenant.calling-profiles.destroy', [$tenant, $default->id]))->assertSessionHasErrors('profile');

    expect(CallingProfile::withoutTenantScope()->count())->toBe(2);
});

it('makes another profile the default and writes the extensions without one', function () {
    fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $old = CallingProfile::factory()->for($tenant)->default()->create();
    $new = CallingProfile::factory()->for($tenant)->crossTenant()->create();
    Extension::factory()->on($assignment)->create(['extension_number' => '201']);
    Extension::factory()->on($assignment)->create(['extension_number' => '202', 'calling_profile_id' => $old->id]);

    $this->post(route('tenant.calling-profiles.default', [$tenant, $new->id]))->assertSessionHasNoErrors();

    expect($new->fresh()->is_default)->toBeTrue()
        ->and($old->fresh()->is_default)->toBeFalse()
        ->and(ConfigRevision::query()->pluck('target')->all())->toBe(['number:1020201']);
});

it('gives a new tenant its starting profiles, internal only by default', function () {
    Notification::fake();
    signIn(User::factory()->platformAdmin()->create());

    $this->post(route('admin.tenants.store'), ['name' => 'Acme', 'slug' => 'acme', 'owner_name' => 'Owner', 'owner_email' => 'owner@example.com'])
        ->assertSessionHasNoErrors();

    $profiles = CallingProfile::withoutTenantScope()->get();

    expect($profiles)->toHaveCount(5)
        ->and($profiles->where('is_default', true)->sole())->name->toBe('Internal only')->categories->toBe([])
        ->and(Tenant::query()->sole()->auth_salt)->toHaveLength(16);
});

it('creates an authorization code shown once and writes it as a hash to the tenant\'s dialplan', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();

    $this->post(route('tenant.authorization-codes.store', $tenant), ['label' => 'Teacher Anna', 'categories' => ['cross_tenant', 'mobile']])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('authorizationCode.label', 'Teacher Anna');

    $code = session('inertia.flash_data')['authorizationCode']['code'] ?? null;
    $model = AuthorizationCode::withoutTenantScope()->sole();
    $hash = md5($tenant->fresh()->auth_salt.$code);

    expect($code)->toMatch('/^\d{8}$/')
        ->and($model->node_hash)->toBe($hash)
        ->and(DB::table('authorization_codes')->get()->toJson())->not->toContain($code)
        ->and(AuditLog::query()->get()->toJson())->not->toContain($code)->not->toContain($hash)
        ->and(ConfigRevision::query()->sole()->target)->toBe('calling:1020');

    applyPendingRevisions();

    $revision = ConfigRevision::query()->sole();

    expect($revision->status)->toBe(RevisionStatus::Applied)
        ->and($node->extens["1020a{$hash}"])->toBe([['priority' => 1, 'app' => 'Return', 'appdata' => "{$model->id}/x/m/"]])
        ->and($node->extens['1020auth'][2]['appdata'])->toBe('AUTHEXTEN=1020a${MD5('.$tenant->fresh()->auth_salt.'${AUTHCODE})}')
        // the revision names the code by its id and leaves out the salt
        ->and(json_encode($revision->compiled))->not->toContain($hash)->not->toContain($tenant->fresh()->auth_salt)
        ->and($revision->compiled['extens'])->toHaveKey("1020a<code {$model->id}>");
});

it('refuses digits another code of the tenant has', function () {
    fakeNode();
    [$tenant] = provisioningTenant();
    AuthorizationCode::factory()->for($tenant)->code('24681357')->create();

    $this->post(route('tenant.authorization-codes.store', $tenant), ['label' => 'B', 'categories' => ['mobile'], 'code' => '24681357'])
        ->assertSessionHasErrors('code');
    $this->post(route('tenant.authorization-codes.store', $tenant), ['label' => 'B', 'categories' => ['mobile'], 'code' => '123'])
        ->assertSessionHasErrors('code');
});

it('resets, disables and deletes a code, taking it off the node', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $code = AuthorizationCode::factory()->for($tenant)->create();
    provisionCalling($assignment);
    applyPendingRevisions();
    $first = "1020a{$code->node_hash}";
    expect($node->extens)->toHaveKey($first);

    $this->post(route('tenant.authorization-codes.reset', [$tenant, $code->id]))->assertSessionHasNoErrors();
    applyPendingRevisions();
    $second = "1020a{$code->fresh()->node_hash}";
    expect($node->extens)->not->toHaveKey($first)->toHaveKey($second);

    $this->patch(route('tenant.authorization-codes.update', [$tenant, $code->id]), ['label' => 'A', 'categories' => ['cross_tenant'], 'status' => 'disabled', 'version' => 2])
        ->assertSessionHasNoErrors();
    applyPendingRevisions();
    // no usable code left: no subroutine, and calls to other tenants no longer ask for one
    expect($node->extens)->not->toHaveKey($second)->not->toHaveKey('1020auth');

    $this->delete(route('tenant.authorization-codes.destroy', [$tenant, $code->id]))->assertSessionHasNoErrors();
    expect(AuthorizationCode::withoutTenantScope()->count())->toBe(0);
});

it('expires codes past their expiry and takes them off the node', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $code = AuthorizationCode::factory()->for($tenant)->create(['expires_at' => now()->addHour()]);
    provisionCalling($assignment);
    applyPendingRevisions();
    expect($node->extens)->toHaveKey("1020a{$code->node_hash}");

    $this->travel(2)->hours();
    $this->artisan('authorization-codes:expire')->assertSuccessful();
    applyPendingRevisions();

    expect($code->fresh()->status)->toBe(AuthorizationCodeStatus::Expired)
        ->and($node->extens)->not->toHaveKey("1020a{$code->node_hash}")
        ->and(AuditLog::query()->where('action', 'authorization_code.expired')->sole()->tenant_id)->toBe($tenant->id);
});

it('compiles calls to tenants that take ours, outbound categories as denied, and asks for a code', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    neighbour($assignment, '1042');
    neighbour($assignment, '1050', CrossTenantInbound::None);
    AuthorizationCode::factory()->for($tenant)->create();
    provisionCalling($assignment);

    applyPendingRevisions();

    expect(ConfigRevision::query()->sole()->status)->toBe(RevisionStatus::Applied)
        ->and($node->extens['1020x1042'][0]['app'])->toBe('NoOp')
        ->and($node->extens['1020denied'])->toBe([
            ['priority' => 1, 'app' => 'Playback', 'appdata' => 'ss-noservice'],
            ['priority' => 2, 'app' => 'Hangup', 'appdata' => '21'],
        ])
        ->and($node->extens)->not->toHaveKey('1020x1050')
        ->and($node->extens['_10200[689]XXXXXXXX'])->toBe([['priority' => 1, 'app' => 'Goto', 'appdata' => '1020denied,1']])
        ->and($node->extens['_102000X.'])->toBe([['priority' => 1, 'app' => 'Goto', 'appdata' => '1020denied,1']])
        ->and(array_column($node->extens['_10208XXXX.'], 'appdata', 'priority'))->toBe([
            1 => '$[${REGEX("^[0-9]+$" ${EXTEN:9})}]?3',
            2 => '1020denied,1',
            3 => '$[${DIALPLAN_EXISTS(${CONTEXT},1020x${EXTEN:5:4},1)} & ${DIALPLAN_EXISTS(${CONTEXT},${EXTEN:5},1)}]?5',
            4 => '1020denied,1',
            5 => '1020p${CALLERID(num)},1',
            6 => '$[${REGEX("/(xa|x${EXTEN:5:4})/" ${GOSUB_RETVAL})}]?10',
            7 => '$[${REGEX("/(XA|X${EXTEN:5:4})/" ${GOSUB_RETVAL})}]?9',
            8 => '1020denied,1',
            9 => '1020auth,1(x)',
            10 => 'CALLERID(num)=81020${CALLERID(num)}',
            11 => '${EXTEN:5},1',
        ]);
});

it('does not ask for a code no code can satisfy', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    AuthorizationCode::factory()->for($tenant)->unlocking([CallCategory::Mobile])->create();
    provisionCalling($assignment);

    applyPendingRevisions();

    expect(array_column($node->extens['_10208XXXX.'], 'app'))->toBe(['GotoIf', 'Goto', 'GotoIf', 'Goto', 'Gosub', 'GotoIf', 'Goto', 'Set', 'Goto'])
        ->and($node->extens['_10208XXXX.'][5]['appdata'])->toEndWith('?8');
});

it('removes the calling dialplan of a suspended tenant', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    provisionCalling($assignment);
    applyPendingRevisions();
    expect($node->extens)->toHaveKey('_10208XXXX.');

    $assignment->update(['status' => 'suspended']);
    provisionCalling($assignment);
    applyPendingRevisions();

    expect(array_filter(array_keys($node->extens), fn (string $exten): bool => str_starts_with($exten, '_1020')))->toBe([]);
});

it('writes the calling dialplan of the tenants that gain or lose this one as a destination', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $acme = neighbour($assignment, '1042', CrossTenantInbound::None);
    neighbour($assignment, '1050', CrossTenantInbound::None);

    $this->patch(route('tenant.cross-tenant.update', $tenant), ['mode' => 'selected', 'allowed' => ['1042']])->assertSessionHasNoErrors();

    expect(ConfigRevision::query()->pluck('target')->all())->toBe(['calling:1042']);
    applyPendingRevisions();
    expect($node->extens['1042x1020'][0]['app'])->toBe('NoOp');

    $this->patch(route('tenant.cross-tenant.update', $tenant), ['mode' => 'all', 'allowed' => []])->assertSessionHasNoErrors();
    expect(ConfigRevision::query()->where('status', 'draft')->pluck('target')->all())->toBe(['calling:1050']);

    $this->patch(route('tenant.cross-tenant.update', $tenant), ['mode' => 'none', 'allowed' => []])->assertSessionHasNoErrors();
    applyPendingRevisions();

    expect($node->extens)->not->toHaveKey('1042x1020')->not->toHaveKey('1050x1020')
        ->and($acme->fresh()->cross_tenant_inbound)->toBe(CrossTenantInbound::None);
});

it('shows profiles, codes without their digits, and the tenants that take our calls', function () {
    fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    CallingProfile::factory()->for($tenant)->default()->crossTenant()->create(['name' => 'Staff']);
    AuthorizationCode::factory()->for($tenant)->create(['label' => 'Anna']);
    neighbour($assignment, '1042')->update(['name' => 'Acme']);

    $this->get(route('tenant.calling.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/calling/Index')
            ->where('profiles.0.name', 'Staff')
            ->where('profiles.0.categories.cross_tenant', 'allow')
            ->where('profiles.0.categories.international', 'deny')
            ->where('codes.0.label', 'Anna')
            ->missing('codes.0.node_hash')
            ->where('directory.0', ['code' => '1042', 'name' => 'Acme', 'dial' => '81042'])
            ->where('canManage', true));
});
