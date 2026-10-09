<?php

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\SupplyMode;
use App\Support\Products\Selection\AttributeSnapshot;
use App\Support\Products\Selection\CombinationSnapshot;
use App\Support\Products\Selection\LocationSnapshot;
use App\Support\Products\Selection\ProductSnapshot;
use App\Support\Products\Selection\ResolvedSelection;
use App\Support\Products\Selection\SelectionRules;
use App\Support\Products\Selection\ServiceSnapshot;
use App\Support\Products\Selection\ValueSnapshot;

/*
 * Hand-built snapshots, no database (design Decision 14). Attribute ids: 1 Tela (fabric axis),
 * 2 Modelo (axis), 3 Talla (order), 4 Color (order). Value ids: 11 ALG-OXF, 12 Drill, 13 Gabardina,
 * 14 Microfibra (inactive), 21 Columbia, 31 S, 32 M, 33 L, 34 2XL, 41 Azul marino, 42 Blanco,
 * 43 Verde. Combinations: 110 (ALG-OXF), 159-1 (Drill or Gabardina), 110-4 (Microfibra).
 */

function selValue(int $id, int $attributeId, string $name, bool $active = true, ?string $tone = null): ValueSnapshot
{
    return new ValueSnapshot($id, $attributeId, $name, null, $id, $active, $tone, null);
}

/**
 * @param  array<string, mixed>  $overrides  constructor arguments of `ProductSnapshot` to replace
 */
function selSnapshot(array $overrides = []): ProductSnapshot
{
    $values = [];

    foreach ([
        selValue(11, 1, 'ALG-OXF Pima'), selValue(12, 1, 'Drill'), selValue(13, 1, 'Gabardina'), selValue(14, 1, 'Microfibra', false),
        selValue(21, 2, 'Columbia especial'),
        selValue(31, 3, 'S'), selValue(32, 3, 'M'), selValue(33, 3, 'L'), selValue(34, 3, '2XL'),
        selValue(41, 4, 'Azul marino', true, '#1F2A44'), selValue(42, 4, 'Blanco', true, '#FFFFFF'), selValue(43, 4, 'Verde', true, '#2E7D32'),
    ] as $value) {
        $values[$value->id] = $value;
    }

    $defaults = [
        'id' => 1,
        'name' => 'Camisa corporativa',
        'active' => true,
        'categoryActive' => true,
        'supplyMode' => SupplyMode::OnDemand,
        'admitsCustomColor' => true,
        'attributes' => [
            new AttributeSnapshot(1, 'Tela', true, AttributeRole::Axis, 1, AttributePresentation::Text, AttributeSpecialUse::Fabric, [11, 12, 13, 14]),
            new AttributeSnapshot(2, 'Modelo', true, AttributeRole::Axis, 2, AttributePresentation::Text, null, [21]),
            new AttributeSnapshot(3, 'Talla', true, AttributeRole::Order, 3, AttributePresentation::Text, AttributeSpecialUse::Size, [31, 32, 33, 34]),
            new AttributeSnapshot(4, 'Color', true, AttributeRole::Order, 4, AttributePresentation::Color, null, []),
        ],
        'values' => $values,
        'fabricColors' => [11 => [41, 42, 43], 12 => [42], 13 => [41]],
        'combinations' => [
            new CombinationSnapshot(1, '110', [1 => [11], 2 => [21]], [], []),
            new CombinationSnapshot(2, '159-1', [1 => [12, 13], 2 => [21]], [], []),
            new CombinationSnapshot(3, '110-4', [1 => [14], 2 => [21]], [], []),
        ],
        'detailLocations' => [new LocationSnapshot(51, 'Pechera', null)],
        'customizations' => [new ServiceSnapshot(61, 'Bordado pequeño', true)],
        'templates' => [],
    ];

    return new ProductSnapshot(...[...$defaults, ...$overrides]);
}

/**
 * A complete selection of 110 with size M and color Blanco; `$overrides` replaces whole keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function selChoice(array $overrides = []): array
{
    return [...['axes' => [1 => 11, 2 => 21], 'order' => [3 => 32, 4 => 42], 'custom_color' => null, 'details' => [], 'customizations' => []], ...$overrides];
}

/**
 * @return list<ValueSnapshot>
 */
function selPalette(): array
{
    return [selValue(41, 4, 'Azul marino', true, '#1F2A44'), selValue(42, 4, 'Blanco', true, '#FFFFFF')];
}

/**
 * @return list<string> codes of the reachable combinations
 */
function selReachable(ProductSnapshot $snapshot, array $chosen, ?array $restriction = null): array
{
    return array_map(fn (CombinationSnapshot $combination): string => $combination->code, SelectionRules::reachableCombinations($snapshot, $chosen, $restriction));
}

it('PRD-011 reachableCombinations returns the active combinations compatible with the chosen axes', function () {
    $snapshot = selSnapshot();

    expect(selReachable($snapshot, [1 => 11, 2 => 21]))->toBe(['110'])
        ->and(selReachable($snapshot, [1 => 12]))->toBe(['159-1'])
        ->and(selReachable($snapshot, [2 => 21]))->toBe(['110', '159-1'])
        ->and(selReachable($snapshot, [1 => 11, 2 => 99]))->toBe([]);
});

it('E-47 reachableCombinations counts every value of a multi-valued axis', function () {
    $snapshot = selSnapshot();

    // 159-1 admits Drill or Gabardina under the same code (DEC-PRD-33).
    expect(selReachable($snapshot, [1 => 12]))->toBe(['159-1'])
        ->and(selReachable($snapshot, [1 => 13]))->toBe(['159-1'])
        ->and(selReachable($snapshot, [1 => 11]))->toBe(['110']);
});

it('E-24 reachableCombinations skips a combination whose axis has no active value', function () {
    $snapshot = selSnapshot();

    // 110-4 uses only Microfibra, which is inactive: it is not reachable even with no chosen axes.
    expect(selReachable($snapshot, []))->toBe(['110', '159-1'])
        ->and(selReachable($snapshot, [1 => 14]))->toBe([]);
});

it('E-24 reachableCombinations skips a value that the product does not admit', function () {
    // Gabardina leaves the admitted values: 159-1 keeps Drill, so it stays reachable through Drill.
    $snapshot = selSnapshot();
    $attributes = $snapshot->attributes;
    $attributes[0] = new AttributeSnapshot(1, 'Tela', true, AttributeRole::Axis, 1, AttributePresentation::Text, AttributeSpecialUse::Fabric, [11, 12, 14]);

    expect(selReachable(selSnapshot(['attributes' => $attributes]), [1 => 12]))->toBe(['159-1']);

    $attributes[0] = new AttributeSnapshot(1, 'Tela', true, AttributeRole::Axis, 1, AttributePresentation::Text, AttributeSpecialUse::Fabric, [11, 14]);

    expect(selReachable(selSnapshot(['attributes' => $attributes]), [1 => 12]))->toBe([]);
});

it('PRD-010 reachableCombinations applies a component restriction on every axis', function () {
    $snapshot = selSnapshot();

    // The component admits only Drill on the fabric axis: 110 (ALG-OXF) has no admitted value.
    expect(selReachable($snapshot, [], [1 => [12]]))->toBe(['159-1'])
        ->and(selReachable($snapshot, [], [1 => [11]]))->toBe(['110'])
        ->and(selReachable($snapshot, [], [1 => [11, 12]]))->toBe(['110', '159-1'])
        ->and(selReachable($snapshot, [], [1 => [99]]))->toBe([])
        ->and(selReachable($snapshot, [], []))->toBe(['110', '159-1']);
});

it('PRD-010 autoApplied picks the attribute left with exactly one admitted active value', function () {
    $snapshot = selSnapshot();

    // Only the model has a single admitted value; the fabric has three active ones.
    expect(SelectionRules::autoApplied($snapshot, null))->toBe([2 => 21])
        // A component restriction narrows the size to one value; the fabric restricted to Microfibra
        // has no active value left, so it is not applied.
        ->and(SelectionRules::autoApplied($snapshot, [3 => [33], 1 => [14]]))->toBe([2 => 21, 3 => 33])
        // Two admitted values after the restriction: nothing is applied for that attribute.
        ->and(SelectionRules::autoApplied($snapshot, [3 => [31, 32]]))->toBe([2 => 21])
        // A restriction with no active value leaves nothing to apply either.
        ->and(SelectionRules::autoApplied($snapshot, [2 => [99]]))->toBe([]);
});

it('PRD-010 autoApplied leaves the color to the client when the options are not a single value', function () {
    $own = new AttributeSnapshot(4, 'Color', true, AttributeRole::Order, 4, AttributePresentation::Color, null, [41]);
    $withoutFabric = selSnapshot([
        'attributes' => [
            new AttributeSnapshot(2, 'Modelo', true, AttributeRole::Axis, 2, AttributePresentation::Text, null, [21]),
            $own,
        ],
        'admitsCustomColor' => false,
    ]);

    // One own color and no custom option: nothing else to choose.
    expect(SelectionRules::autoApplied($withoutFabric, null))->toBe([2 => 21, 4 => 41])
        // The "Personalizado" option is a second choice, so the color stays open.
        ->and(SelectionRules::autoApplied(selSnapshot([
            'attributes' => $withoutFabric->attributes,
            'admitsCustomColor' => true,
        ]), null))->toBe([2 => 21]);
});

it('E-14 validate resolves a valid selection to the code and the normalized selection', function () {
    $result = SelectionRules::validate(selSnapshot(), selChoice(), selPalette());

    expect($result)->toBeInstanceOf(ResolvedSelection::class)
        ->and($result->toArray())->toMatchArray([
            'kind' => 'product',
            'code' => '110',
            'combination_id' => 1,
            'product' => ['id' => 1, 'name' => 'Camisa corporativa'],
            'descriptive_name' => 'Camisa corporativa · ALG-OXF Pima · Columbia especial',
            'requires_advisor' => false,
            'details' => [],
            'customizations' => [],
            'included_customizations' => [],
        ])
        ->and($result->toArray()['axes'])->toBe([
            ['attribute_id' => 1, 'attribute' => 'Tela', 'value_id' => 11, 'value' => 'ALG-OXF Pima'],
            ['attribute_id' => 2, 'attribute' => 'Modelo', 'value_id' => 21, 'value' => 'Columbia especial'],
        ])
        ->and($result->toArray()['order'])->toBe([
            ['attribute_id' => 3, 'attribute' => 'Talla', 'value_id' => 32, 'value' => 'M'],
            ['attribute_id' => 4, 'attribute' => 'Color', 'value_id' => 42, 'value' => 'Blanco', 'tone' => '#FFFFFF'],
        ]);
});

it('E-47 validate keeps the chosen value of a multi-valued axis in the normalized selection', function () {
    $result = SelectionRules::validate(selSnapshot(), selChoice(['axes' => [1 => 13, 2 => 21], 'order' => [3 => 32, 4 => 41]]), selPalette());

    expect($result)->toBeInstanceOf(ResolvedSelection::class)
        ->and($result->toArray()['code'])->toBe('159-1')
        ->and($result->toArray()['axes'][0])->toMatchArray(['value_id' => 13, 'value' => 'Gabardina']);
});

it('E-16 validate reports one error per field and no combination', function () {
    $missingSize = SelectionRules::validate(selSnapshot(), selChoice(['order' => [4 => 42]]), selPalette());
    $badSize = SelectionRules::validate(selSnapshot(), selChoice(['order' => [3 => 99, 4 => 42]]), selPalette());
    $missingAxis = SelectionRules::validate(selSnapshot(), selChoice(['axes' => [2 => 21]]), selPalette());
    $several = SelectionRules::validate(selSnapshot(), selChoice(['axes' => [1 => 11], 'order' => []]), selPalette());

    expect($missingSize)->toBe(['order.3' => 'selection_value_required'])
        ->and($badSize)->toBe(['order.3' => 'selection_value_not_allowed'])
        ->and($missingAxis)->toBe(['axes.1' => 'selection_value_required'])
        ->and($several)->toBe(['axes.2' => 'selection_value_required', 'order.3' => 'selection_value_required', 'order.4' => 'selection_value_required']);
});

it('E-24 validate rejects an inactive axis value and a value of another attribute', function () {
    expect(SelectionRules::validate(selSnapshot(), selChoice(['axes' => [1 => 14, 2 => 21]]), selPalette()))
        ->toBe(['axes.1' => 'selection_value_not_allowed'])
        ->and(SelectionRules::validate(selSnapshot(), selChoice(['axes' => [1 => 21, 2 => 21]]), selPalette()))
        ->toBe(['axes.1' => 'selection_value_not_allowed']);
});

it('E-17 validate answers "selection unavailable" for an inactive product, category or declared attribute', function () {
    $unavailable = ['product' => 'selection_unavailable'];
    $inactiveAttribute = selSnapshot()->attributes;
    $inactiveAttribute[1] = new AttributeSnapshot(2, 'Modelo', false, AttributeRole::Axis, 2, AttributePresentation::Text, null, [21]);

    expect(SelectionRules::validate(selSnapshot(['active' => false]), selChoice(), selPalette()))->toBe($unavailable)
        ->and(SelectionRules::validate(selSnapshot(['categoryActive' => false]), selChoice(), selPalette()))->toBe($unavailable)
        // DEC-PRD-51: the product declares an inactive attribute, so it is not selectable.
        ->and(SelectionRules::validate(selSnapshot(['attributes' => $inactiveAttribute]), selChoice(), selPalette()))->toBe($unavailable);
});

it('E-17 validate answers "selection unavailable" when no active combination matches the values', function () {
    // Every value is admitted and active, but no loaded (active) combination holds them.
    $snapshot = selSnapshot(['combinations' => [new CombinationSnapshot(2, '159-1', [1 => [12], 2 => [21]], [], [])]]);

    expect(SelectionRules::validate($snapshot, selChoice(), selPalette()))->toBe(['product' => 'selection_unavailable']);
});

it('PRD-011 validate reports a defensive error when two active combinations match', function () {
    $snapshot = selSnapshot(['combinations' => [
        new CombinationSnapshot(1, '110', [1 => [11], 2 => [21]], [], []),
        new CombinationSnapshot(9, '110-X', [1 => [11, 12], 2 => [21]], [], []),
    ]]);

    expect(SelectionRules::validate($snapshot, selChoice(), selPalette()))->toBe(['product' => 'selection_ambiguous']);
});

it('E-54 validate rejects an order value outside the combination restriction', function () {
    $restricted = selSnapshot(['combinations' => [new CombinationSnapshot(1, '110', [1 => [11], 2 => [21]], [3 => [31, 32, 33]], [])]]);

    expect(SelectionRules::validate($restricted, selChoice(['order' => [3 => 34, 4 => 42]]), selPalette()))->toBe(['order.3' => 'selection_value_restricted'])
        ->and(SelectionRules::validate($restricted, selChoice(['order' => [3 => 33, 4 => 42]]), selPalette()))->toBeInstanceOf(ResolvedSelection::class);
});

it('E-43 validate accepts only the colors offered by the chosen fabric', function () {
    // ALG-OXF offers Azul marino, Blanco and Verde. Drill (159-1) offers only Blanco.
    $red = selValue(44, 4, 'Rojo', true, '#C62828');
    $snapshot = selSnapshot(['values' => selSnapshot()->values + [44 => $red]]);

    expect(SelectionRules::validate($snapshot, selChoice(['order' => [3 => 32, 4 => 44]]), selPalette()))->toBe(['order.4' => 'selection_color_not_offered'])
        ->and(SelectionRules::validate($snapshot, selChoice(['order' => [3 => 32, 4 => 43]]), selPalette()))->toBeInstanceOf(ResolvedSelection::class)
        ->and(SelectionRules::validate($snapshot, selChoice(['axes' => [1 => 12, 2 => 21], 'order' => [3 => 32, 4 => 43]]), selPalette()))
        ->toBe(['order.4' => 'selection_color_not_offered']);
});

it('E-44 validate takes the colors of the product when it declares no fabric', function () {
    $capAttributes = [
        new AttributeSnapshot(4, 'Color', true, AttributeRole::Order, 1, AttributePresentation::Color, null, [41, 42]),
    ];
    $cap = selSnapshot([
        'name' => 'Gorra dryfit',
        'attributes' => $capAttributes,
        'fabricColors' => [],
        'combinations' => [new CombinationSnapshot(7, '184-G', [], [], [])],
    ]);

    expect(SelectionRules::validate($cap, selChoice(['axes' => [], 'order' => [4 => 41]]), selPalette()))->toBeInstanceOf(ResolvedSelection::class)
        ->and(SelectionRules::validate($cap, selChoice(['axes' => [], 'order' => [4 => 43]]), selPalette()))->toBe(['order.4' => 'selection_color_not_offered']);
});

it('E-49 validate returns the custom color with its tone and note, and requires an advisor', function () {
    $result = SelectionRules::validate(
        selSnapshot(),
        selChoice(['order' => [3 => 32, 4 => 'custom'], 'custom_color' => ['tone' => '#7a9a3b', 'note' => 'verde oliva corporativo']]),
        selPalette(),
    );

    expect($result)->toBeInstanceOf(ResolvedSelection::class)
        ->and($result->toArray()['requires_advisor'])->toBeTrue()
        ->and($result->toArray()['order'][1])->toBe([
            'attribute_id' => 4, 'attribute' => 'Color', 'value_id' => null, 'value' => null,
            'custom' => true, 'tone' => '#7A9A3B', 'note' => 'verde oliva corporativo',
        ]);
});

it('E-49 validate rejects a custom color without a valid tone or with a note over 100 characters', function () {
    $invalid = fn (mixed $customColor): array|ResolvedSelection => SelectionRules::validate(
        selSnapshot(),
        selChoice(['order' => [3 => 32, 4 => 'custom'], 'custom_color' => $customColor]),
        selPalette(),
    );

    expect($invalid(null))->toBe(['order.4' => 'selection_custom_tone_invalid'])
        ->and($invalid(['tone' => '', 'note' => 'x']))->toBe(['order.4' => 'selection_custom_tone_invalid'])
        ->and($invalid(['tone' => 'verde']))->toBe(['order.4' => 'selection_custom_tone_invalid'])
        ->and($invalid(['tone' => '#12345']))->toBe(['order.4' => 'selection_custom_tone_invalid'])
        ->and($invalid(['tone' => '#7A9A3B', 'note' => str_repeat('a', 101)]))->toBe(['order.4' => 'selection_custom_note_too_long'])
        ->and($invalid(['tone' => '#7A9A3B', 'note' => str_repeat('a', 100)]))->toBeInstanceOf(ResolvedSelection::class)
        ->and($invalid(['tone' => '#7A9A3B']))->toBeInstanceOf(ResolvedSelection::class);
});

it('E-50 validate rejects a custom color that the product does not admit', function () {
    $choice = selChoice(['order' => [3 => 32, 4 => 'custom'], 'custom_color' => ['tone' => '#7A9A3B']]);

    expect(SelectionRules::validate(selSnapshot(['admitsCustomColor' => false]), $choice, selPalette()))->toBe(['order.4' => 'selection_custom_color_not_admitted'])
        ->and(SelectionRules::validate(selSnapshot(['admitsCustomColor' => true]), $choice, selPalette()))->toBeInstanceOf(ResolvedSelection::class);
});

it('PRD-011 validate checks detail locations against the admitted ones and the active palette', function () {
    $valid = SelectionRules::validate(selSnapshot(), selChoice(['details' => [['location_id' => 51, 'color_value_id' => 41]]]), selPalette());

    expect($valid)->toBeInstanceOf(ResolvedSelection::class)
        ->and($valid->toArray()['details'])->toBe([
            ['location_id' => 51, 'location' => 'Pechera', 'color' => ['id' => 41, 'name' => 'Azul marino', 'tone' => '#1F2A44']],
        ])
        ->and(SelectionRules::validate(selSnapshot(), selChoice(['details' => [['location_id' => 99, 'color_value_id' => 41]]]), selPalette()))
        ->toBe(['details.0.location_id' => 'selection_location_not_admitted'])
        ->and(SelectionRules::validate(selSnapshot(), selChoice(['details' => [['location_id' => 51, 'color_value_id' => 43]]]), selPalette()))
        ->toBe(['details.0.color_value_id' => 'selection_location_color_invalid'])
        // E-50: the custom color is for the garment only, never for a detail.
        ->and(SelectionRules::validate(selSnapshot(), selChoice(['details' => [['location_id' => 51, 'color_value_id' => 'custom']]]), selPalette()))
        ->toBe(['details.0.color_value_id' => 'selection_location_color_invalid']);
});

it('E-34 validate rejects a customization the product does not admit and E-67 returns the included ones', function () {
    $included = [new ServiceSnapshot(62, 'Vinil', true)];
    $snapshot = selSnapshot(['combinations' => [new CombinationSnapshot(1, '110', [1 => [11], 2 => [21]], [], $included)]]);
    $ok = SelectionRules::validate($snapshot, selChoice(['customizations' => [61]]), selPalette());

    expect($ok)->toBeInstanceOf(ResolvedSelection::class)
        ->and($ok->toArray()['customizations'])->toBe([['id' => 61, 'name' => 'Bordado pequeño']])
        ->and($ok->toArray()['included_customizations'])->toBe([['id' => 62, 'name' => 'Vinil']])
        // The included vinil is not admitted as an extra, and a service the product never admitted is rejected too.
        ->and(SelectionRules::validate($snapshot, selChoice(['customizations' => [62]]), selPalette()))->toBe(['customizations.0' => 'selection_customization_not_admitted'])
        ->and(SelectionRules::validate($snapshot, selChoice(['customizations' => [61, 99]]), selPalette()))->toBe(['customizations.1' => 'selection_customization_not_admitted']);
});

it('DEC-PRD-77 validate rejects an attribute the product does not declare, in axes or in order', function () {
    $validate = fn (array $overrides): mixed => SelectionRules::validate(selSnapshot(), selChoice($overrides), selPalette());

    expect($validate(['axes' => [1 => 11, 2 => 21, 9 => 5]]))->toBe(['axes.9' => 'selection_attribute_not_declared'])
        ->and($validate(['order' => [3 => 32, 4 => 42, 9 => 5]]))->toBe(['order.9' => 'selection_attribute_not_declared'])
        // A declared attribute sent under the other role is not declared there.
        ->and($validate(['axes' => [1 => 11, 2 => 21, 3 => 32]]))->toBe(['axes.3' => 'selection_attribute_not_declared'])
        ->and($validate(['order' => [3 => 32, 4 => 42, 1 => 11]]))->toBe(['order.1' => 'selection_attribute_not_declared'])
        ->and($validate(['axes' => [1 => 11, 2 => 21, 'x' => 1]]))->toBe(['axes.x' => 'selection_attribute_not_declared']);
});

it('DEC-PRD-78 validate rejects a detail location or a customization sent more than once', function () {
    $detail = ['location_id' => 51, 'color_value_id' => 41];
    $validate = fn (array $overrides): mixed => SelectionRules::validate(selSnapshot(), selChoice($overrides), selPalette());

    expect($validate(['details' => [$detail, ['location_id' => 51, 'color_value_id' => 42]]]))->toBe(['details.1.location_id' => 'selection_location_repeated'])
        ->and($validate(['customizations' => [61, 61]]))->toBe(['customizations.1' => 'selection_customization_repeated'])
        // A single entry of each stays valid.
        ->and($validate(['details' => [$detail], 'customizations' => [61]]))->toBeInstanceOf(ResolvedSelection::class);
});

it('DT-02 keeps the rules and the snapshots pure: only the loader may touch the framework or the models', function () {
    $files = glob(dirname(__DIR__, 3).'/app/Support/Products/Selection/*.php');
    $pure = array_values(array_filter($files, fn (string $file): bool => basename($file) !== 'CatalogSnapshotLoader.php'));

    // The glob must find the DTOs and the rules, otherwise the loop below would prove nothing.
    expect(count($pure))->toBeGreaterThanOrEqual(9);

    foreach ($pure as $file) {
        expect(file_get_contents($file))->not->toContain('Illuminate\\')->not->toContain('App\\Models\\')->not->toContain('DB::');
    }
});

it('E-63 validate rejects a value outside the component restriction on an order attribute, an axis and the color', function () {
    $snapshot = selSnapshot(['admitsCustomColor' => false]);
    $validate = fn (array $overrides, array $restriction): mixed => SelectionRules::validate($snapshot, selChoice($overrides), [], $restriction);

    expect($validate([], [3 => [31, 32]]))->toBeInstanceOf(ResolvedSelection::class)
        ->and($validate(['order' => [3 => 33, 4 => 42]], [3 => [31, 32]]))->toBe(['order.3' => 'selection_component_restricted'])
        // The color of a product with fabric comes from the fabric and the restriction narrows it (DEC-PRD-65).
        ->and($validate(['order' => [3 => 32, 4 => 41]], [4 => [42]]))->toBe(['order.4' => 'selection_component_restricted'])
        ->and($validate([], [4 => [42]]))->toBeInstanceOf(ResolvedSelection::class)
        ->and($validate(['axes' => [1 => 12, 2 => 21], 'order' => [3 => 32, 4 => 42]], [1 => [11]]))->toBe(['axes.1' => 'selection_component_restricted'])
        // A value the product does not admit at all keeps the product's own reason.
        ->and($validate(['order' => [3 => 99, 4 => 42]], [3 => [31]]))->toBe(['order.3' => 'selection_value_not_allowed']);
});

it('DEC-PRD-87 validate does not admit the custom color in any component, restricted or not', function () {
    $snapshot = selSnapshot();
    $custom = selChoice(['order' => [3 => 32, 4 => 'custom'], 'custom_color' => ['tone' => '#112233']]);

    expect(SelectionRules::validate($snapshot, $custom, [], [4 => [42]]))->toBe(['order.4' => 'selection_component_restricted'])
        // A component without any color restriction does not admit it either.
        ->and(SelectionRules::validate($snapshot, $custom, [], [3 => [32]]))->toBe(['order.4' => 'selection_component_restricted'])
        ->and(SelectionRules::validate($snapshot, $custom, [], []))->toBe(['order.4' => 'selection_component_restricted'])
        // The product alone keeps admitting it (E-49).
        ->and(SelectionRules::validate($snapshot, $custom, selPalette()))->toBeInstanceOf(ResolvedSelection::class)
        // The error of the custom color itself comes first, whatever the component does.
        ->and(SelectionRules::validate(selSnapshot(['admitsCustomColor' => false]), $custom, [], []))->toBe(['order.4' => 'selection_custom_color_not_admitted'])
        ->and(SelectionRules::validate(selSnapshot(['admitsCustomColor' => false]), $custom, [], [4 => [42]]))->toBe(['order.4' => 'selection_custom_color_not_admitted']);
});

it('DEC-PRD-87 autoApplied and componentOffered ignore the custom color of a component', function () {
    $modelo = new AttributeSnapshot(2, 'Modelo', true, AttributeRole::Axis, 2, AttributePresentation::Text, null, [21]);
    $own = new AttributeSnapshot(4, 'Color', true, AttributeRole::Order, 4, AttributePresentation::Color, null, [41]);
    $snapshot = selSnapshot(['attributes' => [$modelo, $own], 'admitsCustomColor' => true, 'combinations' => [new CombinationSnapshot(1, 'M-1', [2 => [21]], [], [])]]);
    $withoutColors = selSnapshot([
        'attributes' => [$modelo, new AttributeSnapshot(4, 'Color', true, AttributeRole::Order, 4, AttributePresentation::Color, null, [])],
        'admitsCustomColor' => true,
        'combinations' => [new CombinationSnapshot(1, 'M-1', [2 => [21]], [], [])],
    ]);

    // The product alone keeps the color open for the "Personalizado" option; a component has its only color applied.
    expect(SelectionRules::autoApplied($snapshot, null))->toBe([2 => 21])
        ->and(SelectionRules::autoApplied($snapshot, []))->toBe([2 => 21, 4 => 41])
        // A component whose product lists no color has nothing to choose, even if the product admits the custom one.
        ->and(SelectionRules::componentOffered($snapshot, []))->toBeTrue()
        ->and(SelectionRules::componentOffered($withoutColors, []))->toBeFalse();
});

it('PRD-010 validate applies the only admitted value of an attribute to a component and never to a product alone (DEC-PRD-81)', function () {
    $snapshot = selSnapshot(['admitsCustomColor' => false]);
    $choice = selChoice(['axes' => [], 'order' => [4 => 42]]);

    // Fabric restricted to ALG-OXF, size to M, and Modelo has a single allowed value: nothing is chosen by the client.
    $resolved = SelectionRules::validate($snapshot, $choice, [], [1 => [11], 3 => [32]]);

    expect($resolved)->toBeInstanceOf(ResolvedSelection::class)
        ->and($resolved->code)->toBe('110')
        ->and($resolved->order[0]['value_id'])->toBe(32)
        // Without a restriction (a product alone) nothing is applied: the client has to choose.
        ->and(SelectionRules::validate($snapshot, $choice, []))->toBe(['axes.1' => 'selection_value_required', 'axes.2' => 'selection_value_required', 'order.3' => 'selection_value_required'])
        // A value the client did send is validated, never replaced by the only admitted one.
        ->and(SelectionRules::validate($snapshot, selChoice(['order' => [3 => 31, 4 => 42]]), [], [3 => [32]]))->toBe(['order.3' => 'selection_component_restricted']);
});

it('PRD-010 componentOffered is false when the product or the component is left with no option (DEC-PRD-64, DEC-PRD-70)', function () {
    $snapshot = selSnapshot(['admitsCustomColor' => false]);

    expect(SelectionRules::componentOffered($snapshot, []))->toBeTrue()
        ->and(SelectionRules::componentOffered($snapshot, [4 => [42]]))->toBeTrue()
        // The restriction admits only Microfibra, which is inactive.
        ->and(SelectionRules::componentOffered($snapshot, [1 => [14]]))->toBeFalse()
        // A size the product does not admit, or a color no fabric offers, leaves nothing to choose.
        ->and(SelectionRules::componentOffered($snapshot, [3 => [99]]))->toBeFalse()
        ->and(SelectionRules::componentOffered($snapshot, [4 => [99]]))->toBeFalse()
        // The custom color is never an option of a component (DEC-PRD-87).
        ->and(SelectionRules::componentOffered(selSnapshot(['admitsCustomColor' => true]), []))->toBeTrue()
        ->and(SelectionRules::componentOffered(selSnapshot(['admitsCustomColor' => true]), [4 => [99]]))->toBeFalse()
        ->and(SelectionRules::componentOffered(selSnapshot(['active' => false]), []))->toBeFalse()
        ->and(SelectionRules::componentOffered(selSnapshot(['combinations' => []]), []))->toBeFalse();
});

it('PRD-011 validate ignores malformed ids instead of failing', function () {
    expect(SelectionRules::validate(selSnapshot(), selChoice(['axes' => [1 => 'x', 2 => [21]], 'order' => [3 => '32', 4 => 42.5]]), selPalette()))
        ->toBe(['axes.1' => 'selection_value_not_allowed', 'axes.2' => 'selection_value_not_allowed', 'order.4' => 'selection_color_not_offered'])
        ->and(SelectionRules::validate(selSnapshot(), selChoice(['axes' => [1 => '11', 2 => '21'], 'order' => [3 => '32', 4 => '42']]), selPalette()))
        ->toBeInstanceOf(ResolvedSelection::class);
});
