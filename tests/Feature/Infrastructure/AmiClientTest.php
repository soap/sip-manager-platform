<?php

use App\Modules\Infrastructure\Asterisk\AmiAdapter;
use App\Modules\Infrastructure\Asterisk\AmiClient;
use App\Modules\Infrastructure\Asterisk\AmiMessage;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiActionFailed;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiUnavailable;
use App\Modules\Nodes\Models\PbxNode;

/**
 * One AMI message as Asterisk writes it.
 *
 * @param  array<string, string|list<string>>  $fields
 */
function amiReply(array $fields): string
{
    $message = '';

    foreach ($fields as $key => $values) {
        foreach ((array) $values as $value) {
            $message .= "{$key}: {$value}\r\n";
        }
    }

    return $message."\r\n";
}

/**
 * An AmiClient over a local socket whose far end has already written the script.
 *
 * @return array{AmiClient, Closure(): string} the client, and a function that closes it and returns what it sent
 */
function scriptedAmi(string $script, bool $hangUp = false): array
{
    /** far ends stay open until the process ends, even when the test drops the closure */
    static $keepAlive = [];

    $server = stream_socket_server('tcp://127.0.0.1:0');
    $socket = stream_socket_client('tcp://'.stream_socket_get_name($server, false), timeout: 2);
    $far = stream_socket_accept($server, 2);
    fwrite($far, $script);
    stream_set_timeout($socket, 1);

    if ($hangUp) {
        fclose($far);
    } else {
        $keepAlive[] = [$far, $server];
    }

    $client = new AmiClient($socket);

    return [$client, function () use ($client, $far): string {
        $client->close();

        return (string) stream_get_contents($far);
    }];
}

const AMI_BANNER = "Asterisk Call Manager/11.0.0\r\n";

it('parses a message without regard to the case of keys and keeps repeated keys', function () {
    $message = AmiMessage::parse("Response: Success\r\nActionID: a-1\r\nOutput: first\r\noutput: second\r\nValue: a: b\r\n");

    expect($message->isSuccess())->toBeTrue()
        ->and($message->get('actionid'))->toBe('a-1')
        ->and($message->get('Output'))->toBe("first\nsecond")
        ->and($message->get('Value'))->toBe('a: b')
        ->and($message->isEvent())->toBeFalse();
});

it('logs in without events and answers an action by its ActionID, skipping events', function () {
    [$ami, $sent] = scriptedAmi(AMI_BANNER
        .amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-1', 'Message' => 'Authentication accepted'])
        .amiReply(['Event' => 'FullyBooted', 'Privilege' => 'system,all', 'Status' => 'Fully Booted'])
        .amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-2', 'Variable' => 'DIALPLAN_EXISTS(from-opensips,20101,1)', 'Value' => '1']));

    $ami->login('manager', 's3cret');

    expect($ami->getvar('DIALPLAN_EXISTS(from-opensips,20101,1)'))->toBe('1')
        ->and($sent())->toBe(
            "Action: Login\r\nActionID: sip-manager-1\r\nUsername: manager\r\nSecret: s3cret\r\nEvents: off\r\n\r\n"
            ."Action: Getvar\r\nActionID: sip-manager-2\r\nVariable: DIALPLAN_EXISTS(from-opensips,20101,1)\r\n\r\n"
        );
});

it('collects the events of an action that lists something', function () {
    [$ami] = scriptedAmi(
        amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-1', 'EventList' => 'start', 'Message' => 'Queue status will follow'])
        .amiReply(['Event' => 'QueueParams', 'ActionID' => 'sip-manager-1', 'Queue' => 'rg-20500'])
        .amiReply(['Event' => 'QueueMember', 'ActionID' => 'sip-manager-1', 'Name' => 'PJSIP/20101@opensips'])
        .amiReply(['Event' => 'QueueStatusComplete', 'ActionID' => 'sip-manager-1', 'EventList' => 'Complete', 'ListItems' => '2'])
    );

    $response = $ami->action('QueueStatus', ['Queue' => 'rg-20500']);

    expect($response->events)->toHaveCount(2)
        ->and($response->events[0]->get('Event'))->toBe('QueueParams')
        ->and($response->events[1]->get('Name'))->toBe('PJSIP/20101@opensips');
});

it('returns the output lines of a CLI command', function () {
    [$ami] = scriptedAmi(amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-1', 'Message' => 'Command output follows', 'Output' => ['Asterisk 22.11.0 built by root', '']]));

    expect($ami->command('core show version'))->toBe(['Asterisk 22.11.0 built by root', '']);
});

it('throws when Asterisk refuses an action', function () {
    [$ami] = scriptedAmi(AMI_BANNER.amiReply(['Response' => 'Error', 'ActionID' => 'sip-manager-1', 'Message' => 'Authentication failed']));

    expect(fn () => $ami->login('manager', 'wrong'))->toThrow(AmiActionFailed::class, 'AMI refused Login: Authentication failed.');
});

it('tells a port that is not AMI, a silent one and a closed one apart', function (string $script, bool $hangUp, string $message) {
    [$ami] = scriptedAmi($script, $hangUp);

    expect(fn () => $ami->login('manager', 's3cret'))->toThrow(AmiUnavailable::class, $message);
})->with([
    'not AMI' => ["SSH-2.0-OpenSSH_9.6\r\n", false, 'does not answer as AMI'],
    'silent' => [AMI_BANNER, false, 'did not answer in time'],
    'closed' => [AMI_BANNER, true, 'closed the connection'],
]);

it('refuses a field that would end the message early', function () {
    [$ami] = scriptedAmi('');

    expect(fn () => $ami->action('Getvar', ['Variable' => "X\r\nAction: Originate"]))->toThrow(InvalidArgumentException::class);
});

it('cannot connect to a port nobody listens on', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT);
    fclose($server);

    expect(fn () => AmiClient::connect(['host' => '127.0.0.1', 'port' => $port, 'username' => 'manager', 'password' => 's3cret'], 1))
        ->toThrow(AmiUnavailable::class, "Could not connect to AMI at 127.0.0.1:{$port}");
});

it('lists the priorities Asterisk does not find through the entry context, and logs off', function () {
    $node = PbxNode::factory()->create();
    [$ami, $sent] = scriptedAmi(
        amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-1', 'Value' => '1'])
        .amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-2', 'Value' => '1'])
        .amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-3', 'Value' => '0'])
        .amiReply(['Response' => 'Goodbye', 'ActionID' => 'sip-manager-4', 'Message' => 'Thanks for all the fish.'])
    );
    $this->partialMock(AmiAdapter::class)->shouldAllowMockingProtectedMethods()->shouldReceive('connect')->once()->andReturn($ami);

    expect(app(AmiAdapter::class)->missingPriorities($node, '20101', 3))->toBe([3])
        ->and($sent())->toContain('Variable: DIALPLAN_EXISTS(from-opensips,20101,3)')
        ->toContain('Action: Logoff');
});

it('asks app_voicemail whether a mailbox exists', function () {
    $node = PbxNode::factory()->create();
    [$ami, $sent] = scriptedAmi(
        amiReply(['Response' => 'Success', 'ActionID' => 'sip-manager-1', 'Value' => '1'])
        .amiReply(['Response' => 'Goodbye', 'ActionID' => 'sip-manager-2'])
    );
    $this->partialMock(AmiAdapter::class)->shouldAllowMockingProtectedMethods()->shouldReceive('connect')->once()->andReturn($ami);

    expect(app(AmiAdapter::class)->hasMailbox($node, '20101', 't20'))->toBeTrue()
        ->and($sent())->toContain('Variable: VM_INFO(20101@t20,exists)');
});
