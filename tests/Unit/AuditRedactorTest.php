<?php

use App\Support\Audit\AuditRedactor;

it('FND-012 drops keys containing password at any depth, case-insensitively', function () {
    $result = AuditRedactor::redact([
        'first_name' => 'Ana',
        'password' => 'secret',
        'Password_Confirmation' => 'secret',
        'current_password' => 'secret',
        'nested' => [
            'email' => 'ana@ecolekua.com',
            'NEW_PASSWORD_HASH' => 'x',
            'deeper' => ['user_password' => 'x', 'kept' => 1],
        ],
    ]);

    expect($result)->toBe([
        'first_name' => 'Ana',
        'nested' => [
            'email' => 'ana@ecolekua.com',
            'deeper' => ['kept' => 1],
        ],
    ]);
});

it('FND-023 drops remember_token only when the key matches exactly', function () {
    $result = AuditRedactor::redact([
        'remember_token' => 'abc',
        'REMEMBER_TOKEN' => 'abc',
        'remember_token_extra' => 'kept',
        'nested' => ['remember_token' => 'abc', 'name' => 'kept'],
    ]);

    expect($result)->toBe([
        'remember_token_extra' => 'kept',
        'nested' => ['name' => 'kept'],
    ]);
});

it('E-28 keeps values and list items that are not password-like keys', function () {
    $result = AuditRedactor::redact([
        'roles' => [['id' => 1, 'name' => 'Administrador'], ['id' => 2, 'name' => 'Gerente']],
        'note' => 'password reset requested',
        'count' => 0,
        'flag' => false,
        'nothing' => null,
    ]);

    expect($result)->toBe([
        'roles' => [['id' => 1, 'name' => 'Administrador'], ['id' => 2, 'name' => 'Gerente']],
        'note' => 'password reset requested',
        'count' => 0,
        'flag' => false,
        'nothing' => null,
    ]);
});

it('FND-023 returns an empty array unchanged', function () {
    expect(AuditRedactor::redact([]))->toBe([]);
});
