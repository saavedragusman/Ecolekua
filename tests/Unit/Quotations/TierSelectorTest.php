<?php

use App\Enums\PriceTierKind;
use App\Support\Quotations\Calculation\TierFacts;
use App\Support\Quotations\Calculation\TierSelector;

// COT-008, design Decision 15: the tier whose [min, max] contains the quantity. Pure, no database.

/**
 * The tiers of a fictitious uniforms scheme: Detal 1–6, 7–12, 13–49 and negotiation from 50.
 *
 * @return list<TierFacts>
 */
function uniformTiers(): array
{
    return [
        new TierFacts(id: 1, label: 'Detal', min: 1, max: 6, kind: PriceTierKind::Priced),
        new TierFacts(id: 2, label: '7–12', min: 7, max: 12, kind: PriceTierKind::Priced),
        new TierFacts(id: 3, label: '13–49', min: 13, max: 49, kind: PriceTierKind::Priced),
        new TierFacts(id: 4, label: '50 o más', min: 50, max: null, kind: PriceTierKind::Negotiation),
    ];
}

it('COT-008 elige el tramo que contiene la cantidad en cada límite', function (int $quantity, string $label) {
    expect(TierSelector::for(uniformTiers(), $quantity)?->label)->toBe($label);
})->with([
    [1, 'Detal'],
    [6, 'Detal'],
    [7, '7–12'],
    [12, '7–12'],
    [13, '13–49'],
    [49, '13–49'],
    [50, '50 o más'],
    [5000, '50 o más'],
]);

it('COT-008 no inventa un tramo cuando ninguno contiene la cantidad', function () {
    $tiers = array_slice(uniformTiers(), 0, 3);

    expect(TierSelector::for($tiers, 50))->toBeNull()
        ->and(TierSelector::for(uniformTiers(), 0))->toBeNull();
});

it('COT-008 un esquema de precio fijo usa su único tramo para cualquier cantidad', function (int $quantity) {
    $fixed = [new TierFacts(id: 9, label: 'Precio', min: 1, max: null, kind: PriceTierKind::Priced)];

    expect(TierSelector::for($fixed, $quantity)?->id)->toBe(9);
})->with([1, 50, 999]);

it('COT-008 elige el tramo sin depender del orden en que llegan', function () {
    expect(TierSelector::for(array_reverse(uniformTiers()), 7)?->id)->toBe(2);
});
