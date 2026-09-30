<?php

use App\Enums\AuditAction;

it('FND-022 defines exactly the 18 audited events of the design contract', function () {
    $values = array_map(fn (AuditAction $case) => $case->value, AuditAction::cases());

    expect($values)->toEqualCanonicalizing([
        'auth.login_succeeded',
        'auth.login_failed',
        'auth.lockout',
        'auth.logout',
        'auth.password_changed',
        'users.created',
        'users.updated',
        'users.activated',
        'users.deactivated',
        'users.password_reset',
        'users.roles_assigned',
        'users.roles_removed',
        'roles.created',
        'roles.updated',
        'roles.deleted',
        'roles.permissions_granted',
        'roles.permissions_revoked',
        'authorization.denied',
    ]);
});

it('FND-022 gives every audited event a non-empty Spanish label', function () {
    expect(AuditAction::cases())->toHaveCount(18);

    foreach (AuditAction::cases() as $case) {
        expect($case->label())->toBeString()->not->toBe('');
    }
});

it('FND-022 gives distinct labels so the audit query can tell events apart', function () {
    $labels = array_map(fn (AuditAction $case) => $case->label(), AuditAction::cases());

    expect(array_unique($labels))->toHaveCount(18);
});
