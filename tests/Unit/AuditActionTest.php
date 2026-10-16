<?php

use App\Enums\AuditAction;

it('FND-022, CLI-016, PRD-017 and COT-023 define exactly the 74 audited events of the design contracts', function () {
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
        'pricing.scheme_created',
        'pricing.scheme_updated',
        'pricing.scheme_deactivated',
        'pricing.scheme_activated',
        'pricing.product_scheme_assigned',
        'pricing.price_created',
        'pricing.price_updated',
        'pricing.price_deleted',
        'pricing.size_groups_updated',
        'pricing.combo_item_price_created',
        'pricing.combo_item_price_updated',
        'pricing.combo_item_price_deleted',
        'pricing.parameters_updated',
        'pricing.exchange_rate_recorded',
        'pricing.import_applied',
        'pricing.import_discarded',
        'quotations.created',
        'quotations.issued',
        'quotations.duplicated',
        'quotations.cancelled',
        'quotations.discarded',
        'quotations.expired',
        'quotations.negotiated_price_set',
        'quotations.agreed_advance_set',
        'quotations.advisor_changed',
        'quotations.recipient_changed',
    ]);
});

it('FND-022 gives every audited event a non-empty Spanish label', function () {
    expect(AuditAction::cases())->toHaveCount(74);

    foreach (AuditAction::cases() as $case) {
        expect($case->label())->toBeString()->not->toBe('');
    }
});

it('FND-022 gives distinct labels so the audit query can tell events apart', function () {
    $labels = array_map(fn (AuditAction $case) => $case->label(), AuditAction::cases());

    expect(array_unique($labels))->toHaveCount(74);
});

it('COT-023 uses the Spanish labels of the design audit table', function () {
    expect(AuditAction::from('pricing.scheme_created')->label())->toBe('Esquema de precio creado')
        ->and(AuditAction::from('pricing.scheme_updated')->label())->toBe('Esquema de precio modificado')
        ->and(AuditAction::from('pricing.scheme_deactivated')->label())->toBe('Esquema de precio desactivado')
        ->and(AuditAction::from('pricing.scheme_activated')->label())->toBe('Esquema de precio reactivado')
        ->and(AuditAction::from('pricing.product_scheme_assigned')->label())->toBe('Esquema de precio asignado a un producto')
        ->and(AuditAction::from('pricing.price_created')->label())->toBe('Precio de combinación registrado')
        ->and(AuditAction::from('pricing.price_updated')->label())->toBe('Precio de combinación modificado')
        ->and(AuditAction::from('pricing.price_deleted')->label())->toBe('Precio de combinación eliminado')
        ->and(AuditAction::from('pricing.size_groups_updated')->label())->toBe('Grupos de talla modificados')
        ->and(AuditAction::from('pricing.combo_item_price_created')->label())->toBe('Precio de ítem de combo registrado')
        ->and(AuditAction::from('pricing.combo_item_price_updated')->label())->toBe('Precio de ítem de combo modificado')
        ->and(AuditAction::from('pricing.combo_item_price_deleted')->label())->toBe('Precio de ítem de combo eliminado')
        ->and(AuditAction::from('pricing.parameters_updated')->label())->toBe('Parámetros comerciales modificados')
        ->and(AuditAction::from('pricing.exchange_rate_recorded')->label())->toBe('Tasa de cambio registrada manualmente')
        ->and(AuditAction::from('pricing.import_applied')->label())->toBe('Actualización masiva de precios aplicada')
        ->and(AuditAction::from('pricing.import_discarded')->label())->toBe('Actualización masiva de precios descartada')
        ->and(AuditAction::from('quotations.created')->label())->toBe('Cotización creada')
        ->and(AuditAction::from('quotations.issued')->label())->toBe('Cotización emitida')
        ->and(AuditAction::from('quotations.duplicated')->label())->toBe('Cotización duplicada')
        ->and(AuditAction::from('quotations.cancelled')->label())->toBe('Cotización anulada')
        ->and(AuditAction::from('quotations.discarded')->label())->toBe('Cotización descartada')
        ->and(AuditAction::from('quotations.expired')->label())->toBe('Cotización vencida')
        ->and(AuditAction::from('quotations.negotiated_price_set')->label())->toBe('Precio negociado o confirmado en una cotización')
        ->and(AuditAction::from('quotations.agreed_advance_set')->label())->toBe('Anticipo acordado registrado')
        ->and(AuditAction::from('quotations.advisor_changed')->label())->toBe('Asesora responsable cambiada')
        ->and(AuditAction::from('quotations.recipient_changed')->label())->toBe('Destinatario de cotización cambiado');
});
