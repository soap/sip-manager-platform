<?php

use App\Modules\Nodes\Actions\CheckNodeConnections;
use App\Modules\Nodes\AmiLogin;
use App\Modules\Nodes\Exceptions\NodeNotConnectable;
use App\Modules\Nodes\Models\SipNode;
use App\Modules\Nodes\NodeConnections;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('opens the OpenSIPS and Asterisk databases with the node credentials', function () {
    $node = SipNode::factory()->create();
    $connections = app(NodeConnections::class);

    $openSips = $connections->openSipsDatabase($node);
    $asterisk = $connections->asteriskDatabase($node);

    expect($openSips->getDriverName())->toBe('mariadb')
        ->and($openSips->getConfig('host'))->toBe($node->endpoints['opensips_db']['host'])
        ->and($openSips->getConfig('database'))->toBe('opensips')
        ->and($openSips->getConfig('password'))->toBe($node->credentials['opensips_db']['password'])
        ->and($asterisk->getConfig('database'))->toBe('asterisk')
        ->and($asterisk->getConfig('username'))->toBe('asterisk_manager');
});

it('rebuilds a database connection when the credentials change', function () {
    $node = SipNode::factory()->create();
    $connections = app(NodeConnections::class);
    $connections->openSipsDatabase($node);

    $node->update(['credentials' => [...$node->credentials, 'opensips_db' => ['username' => 'manager', 'password' => 'Rotated']]]);

    expect($connections->openSipsDatabase($node)->getConfig('password'))->toBe('Rotated');
});

it('calls ARI on the management address with basic auth', function () {
    $node = SipNode::factory()->create();
    Http::fake(['*' => Http::response(['system' => ['version' => '22.11.0']])]);

    app(NodeConnections::class)->ari($node)->get('asterisk/info');

    Http::assertSent(fn (Request $request): bool => $request->url() === "http://{$node->addresses['management']}:8088/ari/asterisk/info"
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('manager:'.$node->credentials['ari']['password'])));
});

it('refuses to connect without the endpoint or its credentials', function (array $attributes, string $message) {
    $node = SipNode::factory()->create($attributes);

    expect(fn () => app(NodeConnections::class)->ami($node))->toThrow(NodeNotConnectable::class, $message);
})->with([
    'feature off' => [['endpoints' => []], 'has no ami endpoint'],
    'credentials not fetched' => [['credentials' => null], 'have not been fetched'],
]);

it('records which endpoints of a node answer', function () {
    $node = SipNode::factory()->create();
    Http::fake(['*' => Http::response(['system' => ['version' => '22.11.0']])]);
    $this->mock(AmiLogin::class)->shouldReceive('attempt')->once()->andThrow(new RuntimeException('AMI refused the login.'));
    $this->partialMock(NodeConnections::class)
        ->shouldReceive('openSipsDatabase', 'asteriskDatabase')
        ->andThrow(new RuntimeException('Connection refused'));

    $checks = app(CheckNodeConnections::class)->handle($node);

    expect($checks)->toBe([
        'opensips_db' => ['ok' => false, 'message' => 'Connection refused'],
        'asterisk_db' => ['ok' => false, 'message' => 'Connection refused'],
        'ami' => ['ok' => false, 'message' => 'AMI refused the login.'],
        'ari' => ['ok' => true, 'message' => 'Asterisk 22.11.0'],
    ])
        ->and($node->fresh()->connection_checks)->toBe($checks)
        ->and($node->fresh()->checked_at)->not->toBeNull();
});
