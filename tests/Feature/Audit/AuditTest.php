<?php

use App\Modules\Audit\Audit;

it('redacts secrets at any depth and keeps the rest', function () {
    expect(Audit::redact([
        'email' => 'ann@example.com',
        'password' => 'hunter2',
        'sip' => ['username' => '20101', 'ha1' => 'abc', 'SecretKey' => 'x', 'voicemail_pin' => '1234'],
        'api_token' => 'tok',
        'mapping' => 'kept',
    ]))->toBe([
        'email' => 'ann@example.com',
        'password' => Audit::REDACTED,
        'sip' => ['username' => '20101', 'ha1' => Audit::REDACTED, 'SecretKey' => Audit::REDACTED, 'voicemail_pin' => Audit::REDACTED],
        'api_token' => Audit::REDACTED,
        'mapping' => 'kept',
    ]);
});
