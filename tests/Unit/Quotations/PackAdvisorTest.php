<?php

use App\Support\Quotations\Calculation\PackAdvisor;
use App\Support\Quotations\Calculation\PackLineFacts;
use Brick\Math\BigDecimal;

// COT-009 step 11, design Decision 15: the informative pack notice of a product line (DEC-COT-08). Pure:
// the line and the candidate packs (already admitted, DEC-PRD-42) come as plain facts, no database.

/**
 * A product line whose rows carry the given unit prices, one row per price.
 *
 * @param  list<string>  $unitPrices
 */
function packLine(int $quantity, array $unitPrices): PackLineFacts
{
    return new PackLineFacts(
        quantity: $quantity,
        unitPrices: array_map(fn (string $price) => BigDecimal::of($price), $unitPrices),
    );
}

/**
 * A candidate single-component combo (pack).
 *
 * @return array{code: string, name: string, quantity: int, item_price: BigDecimal}
 */
function candidatePack(string $code, int $quantity, string $itemPrice): array
{
    return ['code' => $code, 'name' => "Pack {$code}", 'quantity' => $quantity, 'item_price' => BigDecimal::of($itemPrice)];
}

it('E-69 avisa del pack con cuántos packs caben y el ahorro estimado', function () {
    $notice = PackAdvisor::best(packLine(6, ['40.00']), [candidatePack('P6', 6, '15.00')]);

    expect($notice?->comboCode)->toBe('P6')
        ->and($notice?->comboName)->toBe('Pack P6')
        ->and($notice?->packsFit)->toBe(1)
        ->and($notice?->estimatedSaving->toString())->toBe('150.00');
});

it('E-69 no avisa cuando la cantidad de la línea no alcanza la del pack', function () {
    expect(PackAdvisor::best(packLine(5, ['40.00']), [candidatePack('P6', 6, '15.00')]))->toBeNull();
});

it('DEC-COT-08 cuenta los packs que caben con división entera y el ahorro solo sobre esas unidades', function () {
    $notice = PackAdvisor::best(packLine(13, ['40.00']), [candidatePack('P6', 6, '15.00')]);

    expect($notice?->packsFit)->toBe(2)
        ->and($notice?->estimatedSaving->toString())->toBe('300.00');
});

it('DEC-COT-08 no avisa cuando el precio por ítem del pack no es menor que el precio unitario', function (string $itemPrice) {
    expect(PackAdvisor::best(packLine(6, ['40.00']), [candidatePack('P6', 6, $itemPrice)]))->toBeNull();
})->with(['40.00', '41.00']);

it('DEC-COT-47 devuelve un solo aviso: el pack de mayor ahorro estimado', function () {
    $notice = PackAdvisor::best(packLine(12, ['40.00']), [
        candidatePack('P6', 6, '30.00'),
        candidatePack('P12', 12, '35.00'),
        candidatePack('P3', 3, '20.00'),
    ]);

    expect($notice?->comboCode)->toBe('P3')
        ->and($notice?->estimatedSaving->toString())->toBe('240.00');
});

it('DEC-COT-47 si el ahorro empata elige el pack de más unidades', function () {
    $notice = PackAdvisor::best(packLine(12, ['40.00']), [
        candidatePack('P6', 6, '30.00'),
        candidatePack('P12', 12, '30.00'),
    ]);

    expect($notice?->comboCode)->toBe('P12')
        ->and($notice?->estimatedSaving->toString())->toBe('120.00');
});

it('DEC-COT-61 si también empatan en unidades elige el código de combo menor, sin importar el orden de carga', function (array $codes) {
    $packs = array_map(fn (string $code) => candidatePack($code, 6, '30.00'), $codes);

    expect(PackAdvisor::best(packLine(12, ['40.00']), $packs)?->comboCode)->toBe('PA6');
})->with([
    'menor primero' => [['PA6', 'PB6']],
    'menor después' => [['PB6', 'PA6']],
]);

it('E-71 una línea con más de un precio unitario distinto nunca recibe aviso', function () {
    $notice = PackAdvisor::best(packLine(12, ['40.00', '42.00']), [candidatePack('P6', 6, '15.00')]);

    expect($notice)->toBeNull();
});

it('DEC-COT-59 dos grupos con el mismo precio cuentan como un solo precio unitario', function () {
    $notice = PackAdvisor::best(packLine(12, ['40.00', '40.0']), [candidatePack('P6', 6, '15.00')]);

    expect($notice?->packsFit)->toBe(2)
        ->and($notice?->estimatedSaving->toString())->toBe('300.00');
});

it('DEC-COT-08 sin packs candidatos o sin precio en la línea no hay aviso', function () {
    expect(PackAdvisor::best(packLine(6, ['40.00']), []))->toBeNull()
        ->and(PackAdvisor::best(packLine(6, []), [candidatePack('P6', 6, '15.00')]))->toBeNull();
});
