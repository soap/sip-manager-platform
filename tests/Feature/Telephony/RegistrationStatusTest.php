<?php

use App\Models\User;
use App\Modules\Infrastructure\OpenSips\OpenSipsDbAdapter;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Enums\TenantRole;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

it('shows which phones are registered once the page has loaded', function () {
    $node = fakeNode();
    [$tenant, $assignment] = provisioningTenant();
    $registered = Extension::factory()->on($assignment)->create(['extension_number' => '20101']);
    $silent = Extension::factory()->on($assignment)->create(['extension_number' => '20102']);
    $node->registrations['20101'] = [[
        'address' => '223.205.105.192:1034',
        'user_agent' => 'Yealink SIP-T31 124.85.0.15',
        'registered_at' => CarbonImmutable::parse('2026-10-21 07:50:00', 'UTC'),
        'expires_at' => CarbonImmutable::parse('2026-10-21 08:50:00', 'UTC'),
    ]];

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertInertia(fn (Assert $page) => $page
            ->has('extensions', 2)
            // read from the node in a follow-up request, so the page never waits for it
            ->missing('registrations')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where("registrations.{$registered->id}.status", 'registered')
                ->where("registrations.{$registered->id}.contacts.0.address", '223.205.105.192:1034')
                ->where("registrations.{$registered->id}.contacts.0.user_agent", 'Yealink SIP-T31 124.85.0.15')
                ->where("registrations.{$registered->id}.contacts.0.registered_at", '2026-10-21T07:50:00+00:00')
                ->where("registrations.{$silent->id}.status", 'not_registered')
                ->where("registrations.{$silent->id}.contacts", [])));
});

it('says unknown instead of failing when the node cannot be asked', function () {
    $node = fakeNode();
    $node->locationUnreachable = true;
    [$tenant, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '20101']);

    $this->get(route('tenant.extensions.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where("registrations.{$extension->id}.status", 'unknown')));
});

it('shows the registration of each extension in the API', function () {
    $node = fakeNode();
    [, $assignment] = provisioningTenant();
    $extension = Extension::factory()->on($assignment)->create(['extension_number' => '20101']);
    $node->registrations['20101'] = [[
        'address' => '[2403:6200:8877:9f7f::1]:18288',
        'user_agent' => 'MizuDroid/4.8.26062',
        'registered_at' => CarbonImmutable::parse('2026-10-21 07:50:00', 'UTC'),
        'expires_at' => null,
    ]];
    $user = User::factory()->create();
    $assignment->tenant->users()->attach($user, ['role' => TenantRole::Member]);
    $token = $user->createToken('CRM', ['read']);
    $token->accessToken->forceFill(['tenant_id' => $assignment->tenant_id])->save();
    app('auth')->forgetGuards();

    $this->withToken($token->plainTextToken)->getJson('/api/v1/extensions')
        ->assertOk()
        ->assertJsonPath('data.0.registration.status', 'registered')
        ->assertJsonPath('data.0.registration.contacts.0.user_agent', 'MizuDroid/4.8.26062');

    $this->withToken($token->plainTextToken)->getJson("/api/v1/extensions/{$extension->id}")
        ->assertOk()
        ->assertJsonPath('data.registration.contacts.0.address', '[2403:6200:8877:9f7f::1]:18288');
});

it('takes the address a phone registered from out of its SIP URI', function (string $uri, string $address) {
    expect(OpenSipsDbAdapter::address($uri))->toBe($address);
})->with([
    'received' => ['sip:223.205.105.192:15172', '223.205.105.192:15172'],
    'contact with user and params' => ['sip:20101@223.205.105.192:1034;transport=udp', '223.205.105.192:1034'],
    'IPv6' => ['sip:20102@[2403:6200:8877:9f7f:4a2a:a54f:1faa:9c36]:18288', '[2403:6200:8877:9f7f:4a2a:a54f:1faa:9c36]:18288'],
    'no port' => ['sips:20101@phone.example.com', 'phone.example.com'],
]);
