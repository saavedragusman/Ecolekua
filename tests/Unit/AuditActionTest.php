<?php

use App\Enums\AuditAction;

it('FND-022, CLI-016 and PRD-017 define exactly the 48 audited events of the design contracts', function () {
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
        'customers.created',
        'customers.updated',
        'customers.deactivated',
        'customers.activated',
        'customers.deleted',
        'customers.advisor_assigned',
        'catalog.created',
        'catalog.updated',
        'catalog.deactivated',
        'catalog.activated',
        'catalog.fabric_colors_updated',
        'products.created',
        'products.updated',
        'products.deactivated',
        'products.activated',
        'products.deleted',
        'products.attributes_updated',
        'products.stock_minimums_updated',
        'products.template_uploaded',
        'products.template_removed',
        'products.combination_created',
        'products.combination_updated',
        'products.combination_deactivated',
        'products.combination_activated',
        'products.combination_deleted',
        'products.combo_created',
        'products.combo_updated',
        'products.combo_deactivated',
        'products.combo_activated',
        'products.combo_deleted',
    ]);
});

it('FND-022 gives every audited event a non-empty Spanish label', function () {
    expect(AuditAction::cases())->toHaveCount(48);

    foreach (AuditAction::cases() as $case) {
        expect($case->label())->toBeString()->not->toBe('');
    }
});

it('FND-022 gives distinct labels so the audit query can tell events apart', function () {
    $labels = array_map(fn (AuditAction $case) => $case->label(), AuditAction::cases());

    expect(array_unique($labels))->toHaveCount(48);
});
