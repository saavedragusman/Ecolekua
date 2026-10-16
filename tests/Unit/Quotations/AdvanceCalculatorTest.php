<?php

use App\Support\Quotations\Calculation\AdvanceCalculator;
use Brick\Math\BigDecimal;

// COT-010, design Decision 17: the required advance is the sum of three parts and is rounded once,
// HALF_UP to 2 decimals (DEC-COT-02). Pure: plain amounts, no database.

it('E-29 suma el porcentaje de las líneas bajo pedido y el 100 % de las de stock y combos', function () {
    $advance = AdvanceCalculator::required(
        percent: BigDecimal::of('50.00'),
        percentBase: BigDecimal::of('180.00'),
        fullBase: BigDecimal::of('53.00'),
        agreed: null,
    );

    expect($advance->automatic->toString())->toBe('90.00')
        ->and($advance->stock->toString())->toBe('53.00')
        ->and($advance->agreed)->toBeNull()
        ->and($advance->required->toString())->toBe('143.00');
});

it('E-30 redondea el anticipo requerido hacia arriba en la mitad', function () {
    $advance = AdvanceCalculator::required(
        percent: BigDecimal::of('50.00'),
        percentBase: BigDecimal::of('100.01'),
        fullBase: BigDecimal::zero(),
        agreed: null,
    );

    expect($advance->required->toString())->toBe('50.01')
        ->and($advance->automatic->toString())->toBe('50.01');
});

it('E-30 redondea una sola vez sobre la suma y las partes suman el anticipo requerido', function () {
    $advance = AdvanceCalculator::required(
        percent: BigDecimal::of('50.00'),
        percentBase: BigDecimal::of('100.01'),
        fullBase: BigDecimal::of('46.00'),
        agreed: BigDecimal::of('20.00'),
    );

    expect($advance->required->toString())->toBe('116.01')
        ->and($advance->automatic->plus($advance->stock)->plus($advance->agreed)->toString())->toBe('116.01');
});

it('E-62 suma el anticipo acordado de las líneas negociadas como tercera parte', function () {
    $advance = AdvanceCalculator::required(
        percent: BigDecimal::of('50.00'),
        percentBase: BigDecimal::zero(),
        fullBase: BigDecimal::zero(),
        agreed: BigDecimal::of('575.00'),
    );

    expect($advance->automatic->toString())->toBe('0.00')
        ->and($advance->stock->toString())->toBe('0.00')
        ->and($advance->agreed?->toString())->toBe('575.00')
        ->and($advance->required->toString())->toBe('575.00');
});

it('E-62 propone el porcentaje vigente por el subtotal negociado, redondeado', function () {
    expect(AdvanceCalculator::proposedAgreed(BigDecimal::of('50.00'), BigDecimal::of('1150.00'))->toString())->toBe('575.00')
        ->and(AdvanceCalculator::proposedAgreed(BigDecimal::of('50.00'), BigDecimal::of('100.01'))->toString())->toBe('50.01');
});

it('COT-010 sin líneas el anticipo requerido es cero', function () {
    $advance = AdvanceCalculator::required(BigDecimal::of('50.00'), BigDecimal::zero(), BigDecimal::zero(), null);

    expect($advance->required->toString())->toBe('0.00');
});
