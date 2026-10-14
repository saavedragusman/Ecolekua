<?php

use App\Support\Pricing\SchemeRules;

// COT-001, E-02 (design Decision 8): pure validation of the ordered tier list of a scheme.

/**
 * The valid Uniformes-like set of E-01.
 *
 * @return list<array{label: string, min: int, max: ?int, kind: string}>
 */
function validTierSet(): array
{
    return [
        ['label' => 'Detal', 'min' => 1, 'max' => 6, 'kind' => 'priced'],
        ['label' => '7–12', 'min' => 7, 'max' => 12, 'kind' => 'priced'],
        ['label' => '13–49', 'min' => 13, 'max' => 49, 'kind' => 'priced'],
        ['label' => 'Negociación', 'min' => 50, 'max' => null, 'kind' => 'negotiation'],
    ];
}

it('E-02 accepts a contiguous set that starts at 1 and ends open with a negotiation tier', function () {
    expect(SchemeRules::validateTiers(validTierSet()))->toBe([]);
});

it('E-02 accepts a single open priced tier and an open priced last tier', function () {
    $single = [['label' => 'Detal', 'min' => 1, 'max' => null, 'kind' => 'priced']];
    $twoPriced = [
        ['label' => 'Detal', 'min' => 1, 'max' => 19, 'kind' => 'priced'],
        ['label' => 'Especial', 'min' => 20, 'max' => null, 'kind' => 'priced'],
    ];

    expect(SchemeRules::validateTiers($single))->toBe([])
        ->and(SchemeRules::validateTiers($twoPriced))->toBe([]);
});

it('E-02 rejects each invalid tier set on the field that breaks the rule', function (Closure $mutate, string $key) {
    $errors = SchemeRules::validateTiers($mutate(validTierSet()));

    expect($errors)->toHaveKey($key)
        ->and($errors[$key])->toBeString()->not->toBe('');
})->with([
    'no tiers' => [fn (array $tiers): array => [], 'tiers'],
    'first tier does not start at 1' => [function (array $tiers): array {
        $tiers[0]['min'] = 2;

        return $tiers;
    }, 'tiers.0.min'],
    'gap between 6 and 8' => [function (array $tiers): array {
        $tiers[1]['min'] = 8;

        return $tiers;
    }, 'tiers.1.min'],
    'two tiers include 12' => [function (array $tiers): array {
        $tiers[2]['min'] = 12;

        return $tiers;
    }, 'tiers.2.min'],
    'intermediate tier without a maximum' => [function (array $tiers): array {
        $tiers[1]['max'] = null;

        return $tiers;
    }, 'tiers.1.max'],
    'maximum below the minimum' => [function (array $tiers): array {
        $tiers[1]['max'] = 5;

        return $tiers;
    }, 'tiers.1.max'],
    'last tier with a maximum' => [function (array $tiers): array {
        $tiers[3]['max'] = 80;

        return $tiers;
    }, 'tiers.3.max'],
    'negotiation tier that is not the last' => [function (array $tiers): array {
        $tiers[1]['kind'] = 'negotiation';

        return $tiers;
    }, 'tiers.1.kind'],
    'unknown kind' => [function (array $tiers): array {
        $tiers[0]['kind'] = 'free';

        return $tiers;
    }, 'tiers.0.kind'],
]);

it('E-02 reports every broken tier in one pass', function () {
    $tiers = validTierSet();
    $tiers[0]['min'] = 2;
    $tiers[2]['min'] = 20;

    expect(SchemeRules::validateTiers($tiers))->toHaveKeys(['tiers.0.min', 'tiers.2.min']);
});

it('E-02 accepts labels of 1 to 30 characters and rejects an empty or longer one', function (string $label, bool $valid) {
    $tiers = validTierSet();
    $tiers[0]['label'] = $label;
    $errors = SchemeRules::validateTiers($tiers);

    expect(array_key_exists('tiers.0.label', $errors))->toBe(! $valid);
})->with([
    'one character' => ['D', true],
    'thirty characters' => [str_repeat('á', 30), true],
    'thirty one characters' => [str_repeat('á', 31), false],
    'empty' => ['', false],
    'only spaces' => ['   ', false],
]);

it('E-02 rejects a label that repeats another one ignoring case and surrounding spaces', function () {
    $tiers = validTierSet();
    $tiers[2]['label'] = '  DETAL ';

    $errors = SchemeRules::validateTiers($tiers);

    expect($errors)->toHaveKey('tiers.2.label')
        ->and($errors)->not->toHaveKey('tiers.0.label');
});

it('E-02 keeps labels that differ only by an accent', function () {
    $tiers = validTierSet();
    $tiers[0]['label'] = 'Especial';
    $tiers[1]['label'] = 'Espécial';

    expect(SchemeRules::validateTiers($tiers))->toBe([]);
});

it('E-02 lists the ten reserved CSV headers', function () {
    expect(SchemeRules::RESERVED_HEADERS)->toBe([
        'codigo', 'grupo_talla', 'producto', 'nombre', 'estado',
        'precio', 'codigo_combo', 'componente', 'cantidad', 'precio_item',
    ]);
});

it('E-02 rejects each reserved CSV header as a label after lowercasing and trimming', function (string $header) {
    foreach ([$header, '  '.mb_strtoupper($header).' '] as $label) {
        $tiers = validTierSet();
        $tiers[1]['label'] = $label;

        expect(SchemeRules::validateTiers($tiers))->toHaveKey('tiers.1.label');
    }
})->with(SchemeRules::RESERVED_HEADERS);

it('E-02 accepts a label that only contains a reserved header', function () {
    $tiers = validTierSet();
    $tiers[0]['label'] = 'Precio especial';

    expect(SchemeRules::validateTiers($tiers))->toBe([]);
});
