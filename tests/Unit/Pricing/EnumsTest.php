<?php

use App\Enums\EmailDeliveryStatus;
use App\Enums\ExchangeRateOrigin;
use App\Enums\LineKind;
use App\Enums\PriceImportScope;
use App\Enums\PriceImportStatus;
use App\Enums\PriceOrigin;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Enums\QuotationOrigin;
use App\Enums\QuotationStatus;

// Design Decision 2: string-backed enums with English values and Spanish labels (constitution §21.1).

/**
 * @param  class-string<BackedEnum>  $enum
 * @param  array<string, string>  $expected  value => label
 */
function expectEnumMap(string $enum, array $expected): void
{
    $actual = [];

    foreach ($enum::cases() as $case) {
        $actual[$case->value] = $case->label();
    }

    expect($actual)->toBe($expected);
}

it('Decision 2 PriceSchemeType has tiered and fixed', function () {
    expectEnumMap(PriceSchemeType::class, ['tiered' => 'Por tramos', 'fixed' => 'Precio fijo']);
});

it('Decision 2 PriceTierKind has priced and negotiation', function () {
    expectEnumMap(PriceTierKind::class, ['priced' => 'Con precio', 'negotiation' => 'Negociación']);
});

it('Decision 2 QuotationStatus has the five statuses and COT-015 badge categories', function () {
    expectEnumMap(QuotationStatus::class, [
        'draft' => 'Borrador',
        'issued' => 'Emitida',
        'expired' => 'Vencida',
        'cancelled' => 'Anulada',
        'approved' => 'Aprobada',
    ]);

    expect(QuotationStatus::Draft->badge())->toBe('neutral')
        ->and(QuotationStatus::Issued->badge())->toBe('active')
        ->and(QuotationStatus::Expired->badge())->toBe('pending')
        ->and(QuotationStatus::Cancelled->badge())->toBe('neutral')
        ->and(QuotationStatus::Approved->badge())->toBe('done');
});

it('Decision 2 QuotationOrigin has advisor and web', function () {
    expectEnumMap(QuotationOrigin::class, ['advisor' => 'Asesora', 'web' => 'Web']);
});

it('Decision 2 PriceOrigin has tier, fixed, combo and negotiated', function () {
    expectEnumMap(PriceOrigin::class, ['tier' => 'Tramo', 'fixed' => 'Fijo', 'combo' => 'Combo', 'negotiated' => 'Negociado']);
});

it('Decision 2 ExchangeRateOrigin has api and manual', function () {
    expectEnumMap(ExchangeRateOrigin::class, ['api' => 'API', 'manual' => 'Manual']);
});

it('Decision 2 EmailDeliveryStatus has pending, sent and failed', function () {
    expectEnumMap(EmailDeliveryStatus::class, ['pending' => 'En cola', 'sent' => 'Enviado', 'failed' => 'Fallido']);
});

it('Decision 2 PriceImportScope has scheme prices and combo item prices', function () {
    expectEnumMap(PriceImportScope::class, [
        'scheme_prices' => 'Precios de combinaciones de un esquema',
        'combo_item_prices' => 'Precios de ítems de combos',
    ]);
});

it('Decision 2 PriceImportStatus has validated, applied and discarded and its badge categories', function () {
    expectEnumMap(PriceImportStatus::class, ['validated' => 'Validada', 'applied' => 'Aplicada', 'discarded' => 'Descartada']);

    expect(PriceImportStatus::Validated->badge())->toBe('pending')
        ->and(PriceImportStatus::Applied->badge())->toBe('done')
        ->and(PriceImportStatus::Discarded->badge())->toBe('neutral');
});

it('Decision 2 LineKind has product and combo', function () {
    expectEnumMap(LineKind::class, ['product' => 'Producto', 'combo' => 'Combo']);
});

it('Decision 2 every label of every 004 enum is unique within its enum', function (string $enum) {
    $labels = array_map(fn (BackedEnum $case): string => $case->label(), $enum::cases());

    expect($labels)->not->toBeEmpty()
        ->and(array_unique($labels))->toHaveCount(count($labels));
})->with([
    PriceSchemeType::class,
    PriceTierKind::class,
    QuotationStatus::class,
    QuotationOrigin::class,
    PriceOrigin::class,
    ExchangeRateOrigin::class,
    EmailDeliveryStatus::class,
    PriceImportScope::class,
    PriceImportStatus::class,
    LineKind::class,
]);
