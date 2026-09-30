<?php

use App\Support\Audit\AuditOrigin;
use Illuminate\Http\Request;

it('DEC-021 fromRequest captures the client IP and an empty context', function () {
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);

    $origin = AuditOrigin::fromRequest($request);

    expect($origin->ipAddress())->toBe('203.0.113.7')
        ->and($origin->context())->toBe([]);
});

it('DEC-021 console origin has no IP and describes the process', function () {
    $origin = AuditOrigin::console('users:create-administrator');

    expect($origin->ipAddress())->toBeNull()
        ->and($origin->context())->toHaveKeys(['source', 'command', 'os_user', 'host'])
        ->and($origin->context()['source'])->toBe('console')
        ->and($origin->context()['command'])->toBe('users:create-administrator')
        ->and($origin->context()['host'])->toBe(gethostname())
        ->and($origin->context()['os_user'])->toBeString()->not->toBeEmpty();
});
