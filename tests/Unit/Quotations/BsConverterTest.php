<?php

use App\Enums\ExchangeRateOrigin;
use App\Support\Pricing\RateSnapshot;
use App\Support\Quotations\Calculation\BsConverter;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

// COT-030, design Decision 17: the reference amount in Bs is the USD amount times the rate, rounded per
// amount HALF_UP to 2 decimals; the rate has 2 decimals (DEC-COT-60). Pure, no database.

function rate875(): RateSnapshot
{
    return new RateSnapshot(BigDecimal::of('875.65'), CarbonImmutable::parse('2026-10-12'), ExchangeRateOrigin::Api);
}

it('E-36 convierte el precio, el total y el anticipo con la tasa 875,65', function (string $usd, string $bs) {
    expect(BsConverter::convert(BigDecimal::of($usd), rate875())?->toString())->toBe($bs);
})->with([
    ['36.00', '31523.40'],
    ['360.00', '315234.00'],
    ['180.00', '157617.00'],
]);

it('DEC-COT-60 redondea cada importe en Bs hacia arriba en la mitad a 2 decimales', function () {
    $rate = new RateSnapshot(BigDecimal::of('10.05'), CarbonImmutable::parse('2026-10-12'), ExchangeRateOrigin::Manual);

    expect(BsConverter::convert(BigDecimal::of('0.50'), $rate)?->toString())->toBe('5.03');
});

it('E-65 sin tasa no hay importe en Bs', function () {
    expect(BsConverter::convert(BigDecimal::of('360.00'), null))->toBeNull();
});
