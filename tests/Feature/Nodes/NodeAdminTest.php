<?php

use App\Models\User;
use App\Modules\Nodes\AmiLogin;
use App\Modules\Nodes\Models\PbxNode;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps other users out of the node pages', function () {
    signIn();
    $node = PbxNode::factory()->create();

    $this->get(route('admin.nodes.index'))->assertForbidden();
    $this->post(route('admin.nodes.sync'))->assertForbidden();
    $this->post(route('admin.nodes.check', $node))->assertForbidden();
});

it('lists the nodes without their passwords', function () {
    signIn(User::factory()->platformAdmin()->create());
    $node = PbxNode::factory()->create();
    PbxNode::factory()->removed()->create();

    $response = $this->get(route('admin.nodes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/nodes/Index')
            ->has('nodes', 2)
            ->where('nodes.0.id', $node->id)
            ->where('nodes.0.credentials', ['opensips_db', 'asterisk_db', 'ami', 'ari']));

    expect($response->getContent())->not->toContain($node->credentials()['ami']['password']);
});

it('syncs the nodes from the deployer', function () {
    config(['services.sip_deployer.url' => 'https://deployer.test', 'services.sip_deployer.token' => 'deployer-token']);
    Http::fake(['deployer.test/api/v1/nodes' => Http::response(['data' => []])]);
    signIn(User::factory()->platformAdmin()->create());

    $this->post(route('admin.nodes.sync'))
        ->assertRedirect(route('admin.nodes.index'))
        ->assertInertiaFlash('toast.type', 'success');
});

it('tells the admin when the deployer cannot be asked', function () {
    config(['services.sip_deployer.url' => null]);
    signIn(User::factory()->platformAdmin()->create());

    $this->post(route('admin.nodes.sync'))
        ->assertRedirect(route('admin.nodes.index'))
        ->assertInertiaFlash('toast.type', 'error');
});

it('checks the connections of a node', function () {
    $node = PbxNode::factory()->create(['endpoints' => ['ami' => ['host' => '10.104.0.5', 'port' => 5038, 'username' => 'manager']]]);
    $this->mock(AmiLogin::class)->shouldReceive('attempt')->once();
    signIn(User::factory()->platformAdmin()->create());

    $this->post(route('admin.nodes.check', $node))
        ->assertRedirect(route('admin.nodes.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($node->fresh()->connection_checks)->toBe(['ami' => ['ok' => true, 'message' => 'Logged in']]);
});
