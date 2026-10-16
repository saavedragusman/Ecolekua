<?php

use App\Enums\BusinessLine;
use App\Enums\LineKind;
use App\Enums\PriceSchemeType;
use App\Support\Quotations\Calculation\LineFacts;
use App\Support\Quotations\Calculation\PieceCounter;

// COT-008, design Decision 15: the pieces that choose the tier. Pure: the counter reads plain line facts,
// no database. Customizations added to a garment (DEC-COT-13) are not part of `LineFacts`, so they can
// never add pieces; the engine-level case is E-24 (Phase 10a).

/**
 * A product line with the given row quantities; `$invalid` lists the positions of rows with a selection error.
 *
 * @param  list<int>  $quantities
 * @param  list<int>  $invalid
 */
function productLine(
    int $productId,
    BusinessLine $line,
    array $quantities,
    PriceSchemeType $scheme = PriceSchemeType::Tiered,
    bool $service = false,
    array $invalid = [],
): LineFacts {
    $rows = [];

    foreach ($quantities as $position => $quantity) {
        $rows[] = ['quantity' => $quantity, 'valid' => ! in_array($position, $invalid, true)];
    }

    return new LineFacts(
        kind: LineKind::Product,
        productId: $productId,
        businessLine: $line,
        isService: $service,
        schemeType: $scheme,
        rows: $rows,
    );
}

it('COT-008 suma las filas de productos por tramos por línea de negocio, aunque sean productos y esquemas distintos', function () {
    $count = PieceCounter::count([
        productLine(1, BusinessLine::Diapers, [4]),
        productLine(2, BusinessLine::Diapers, [3]),
        productLine(3, BusinessLine::Uniforms, [10, 5]),
    ]);

    expect($count['lines'])->toBe(['uniforms' => 15, 'diapers' => 7])
        ->and($count['services'])->toBe([]);
});

it('COT-008 devuelve cero para una línea de negocio sin piezas', function () {
    expect(PieceCounter::count([])['lines'])->toBe(['uniforms' => 0, 'diapers' => 0]);
});

it('COT-008 no cuenta las líneas de productos con esquema de precio fijo', function () {
    $count = PieceCounter::count([
        productLine(1, BusinessLine::Uniforms, [10]),
        productLine(2, BusinessLine::Uniforms, [40], PriceSchemeType::Fixed),
    ]);

    expect($count['lines']['uniforms'])->toBe(10);
});

it('COT-008 no cuenta las líneas de productos sin esquema', function () {
    $line = new LineFacts(
        kind: LineKind::Product,
        productId: 1,
        businessLine: BusinessLine::Uniforms,
        isService: false,
        schemeType: null,
        rows: [['quantity' => 12, 'valid' => true]],
    );

    expect(PieceCounter::count([$line])['lines']['uniforms'])->toBe(0);
});

it('DEC-COT-07 no cuenta las unidades de los combos', function () {
    $combo = new LineFacts(
        kind: LineKind::Combo,
        productId: null,
        businessLine: BusinessLine::Diapers,
        isService: false,
        schemeType: null,
        rows: [['quantity' => 5, 'valid' => true]],
    );

    $count = PieceCounter::count([productLine(1, BusinessLine::Diapers, [6]), $combo]);

    expect($count['lines']['diapers'])->toBe(6);
});

it('DEC-COT-14 cuenta los servicios vendidos solos aparte, por producto, sin sumar a su línea de negocio', function () {
    $count = PieceCounter::count([
        productLine(1, BusinessLine::Uniforms, [8]),
        productLine(157, BusinessLine::Uniforms, [12], service: true),
        productLine(157, BusinessLine::Uniforms, [8], service: true),
        productLine(158, BusinessLine::Uniforms, [3], PriceSchemeType::Fixed, service: true),
    ]);

    expect($count['lines']['uniforms'])->toBe(8)
        ->and($count['services'])->toBe([157 => 20, 158 => 3]);
});

it('COT-008 no cuenta las filas con error de selección (N-2)', function () {
    $count = PieceCounter::count([
        productLine(1, BusinessLine::Uniforms, [10, 4, 6], invalid: [1]),
        productLine(157, BusinessLine::Uniforms, [5, 7], service: true, invalid: [0]),
    ]);

    expect($count['lines']['uniforms'])->toBe(16)
        ->and($count['services'])->toBe([157 => 7]);
});
