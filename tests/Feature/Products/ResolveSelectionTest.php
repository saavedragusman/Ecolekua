<?php

use App\Actions\Products\ResolveSelection;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Models\Combination;
use App\Models\Combo;
use App\Models\DetailLocation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Products\Selection\CatalogSnapshotLoader;
use App\Support\Products\Selection\ProductSnapshot;
use App\Support\Products\Selection\ResolvedCombo;
use App\Support\Products\Selection\ResolvedSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Resolution of a product selection (PRD-011, DT-02), called at Action level: no HTTP route exists
 * in 003. The fixture is fictitious: a corporate shirt (fabric, model, sleeve and gender axes; size
 * and color order attributes), a pair of trousers, a cap and a polo.
 */

const RES_UNAVAILABLE = 'La selección no está disponible.';

/**
 * Selection input for a product. `$order` maps an attribute name to a value name or `custom`;
 * `$axes` replaces the axes of the combination `$code`.
 *
 * @param  array<string, mixed>  $catalog
 * @param  array<string, string>  $order
 * @param  array<string, mixed>  $extra
 * @param  array<string, string>  $axes
 * @return array<string, mixed>
 */
function resInput(array $catalog, string $product, string $code, array $order = [], array $extra = [], array $axes = []): array
{
    $ids = fn (array $map): array => collect($map)->mapWithKeys(fn (string $name, string $attribute): array => [
        $catalog['attrs'][$attribute]->id => $name === 'custom' ? 'custom' : $catalog['vals'][$name]->id,
    ])->all();

    return [...['product_id' => $catalog[$product]->id, 'axes' => $ids([...resAxesOf($code), ...$axes]), 'order' => $ids($order)], ...$extra];
}

/**
 * @param  array<string, mixed>  $input
 */
function resResolve(array $input): ResolvedSelection|ResolvedCombo
{
    return app(ResolveSelection::class)->handle($input);
}

/**
 * Errors the Action raises for an input that must not resolve.
 *
 * @param  array<string, mixed>  $input
 * @return array<string, list<string>>
 */
function resErrors(array $input): array
{
    try {
        resResolve($input);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new LogicException('The selection resolved but errors were expected.');
}

function resMessage(string $code): string
{
    return __('validation.'.$code);
}

it('E-14 resolves a valid selection to the code and the normalized selection', function () {
    $catalog = resCatalog();

    $result = resResolve(resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']));

    expect($result)->toBeInstanceOf(ResolvedSelection::class)
        ->and($result->toArray())->toMatchArray([
            'kind' => 'product',
            'code' => '110-1',
            'combination_id' => $catalog['combos']['110-1']->id,
            'product' => ['id' => $catalog['camisa']->id, 'name' => 'Camisa corporativa'],
            'descriptive_name' => 'Camisa corporativa · ALG-OXF Pima · Columbia especial · Manga corta · Dama',
            'requires_advisor' => false,
            'details' => [],
            'customizations' => [],
            'included_customizations' => [],
        ])
        ->and(array_column($result->toArray()['axes'], 'value'))->toBe(['ALG-OXF Pima', 'Columbia especial', 'Manga corta', 'Dama'])
        ->and(array_column($result->toArray()['order'], 'value'))->toBe(['M', 'Blanco'])
        ->and($result->toArray()['order'][1]['tone'])->toBe('#FFFFFF');
});

it('E-15 resolves trousers by size and rejects a size the product does not admit', function () {
    $catalog = resCatalog();
    $size = $catalog['attrs']['Talla']->id;

    $result = resResolve(resInput($catalog, 'pantalon', '184', ['Talla' => '38']));

    expect($result->toArray()['code'])->toBe('184')
        ->and($result->toArray()['axes'])->toBe([])
        ->and($result->toArray()['order'])->toBe([['attribute_id' => $size, 'attribute' => 'Talla', 'value_id' => $catalog['vals']['38']->id, 'value' => '38']])
        ->and(resErrors(resInput($catalog, 'pantalon', '184', ['Talla' => '46'])))->toBe(["order.$size" => [resMessage('selection_value_not_allowed')]]);
});

it('E-16 reports one error per field when the size is missing or the color is not admitted', function () {
    $catalog = resCatalog();
    $size = $catalog['attrs']['Talla']->id;
    $color = $catalog['attrs']['Color']->id;

    expect(resErrors(resInput($catalog, 'camisa', '110-1', ['Color' => 'Blanco'])))->toBe(["order.$size" => [resMessage('selection_value_required')]])
        // Rojo exists in the catalog but ALG-OXF Pima does not offer it.
        ->and(resErrors(resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Rojo'])))->toBe(["order.$color" => [resMessage('selection_color_not_offered')]])
        ->and(array_keys(resErrors(resInput($catalog, 'camisa', '110-1'))))->toBe(["order.$size", "order.$color"]);
});

it('E-16 reports a missing axis and a value of another attribute on the axis', function () {
    $catalog = resCatalog();
    $fabric = $catalog['attrs']['Tela']->id;
    $input = resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']);
    unset($input['axes'][$fabric]);

    expect(resErrors($input))->toBe(["axes.$fabric" => [resMessage('selection_value_required')]]);

    $input['axes'][$fabric] = $catalog['vals']['Clásico']->id;

    expect(resErrors($input))->toBe(["axes.$fabric" => [resMessage('selection_value_not_allowed')]]);
});

it('E-17 does not resolve an inactive combination, product or category', function () {
    $catalog = resCatalog();
    $input = resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']);
    $unavailable = ['product' => [RES_UNAVAILABLE]];

    expect(resResolve($input)->toArray()['code'])->toBe('110-1');

    $catalog['combos']['110-1']->update(['status' => CatalogStatus::Inactive]);
    expect(resErrors($input))->toBe($unavailable);

    $catalog['combos']['110-1']->update(['status' => CatalogStatus::Active]);
    $catalog['camisa']->update(['status' => CatalogStatus::Inactive]);
    expect(resErrors($input))->toBe($unavailable);

    $catalog['camisa']->update(['status' => CatalogStatus::Active]);
    ProductCategory::query()->whereKey($catalog['camisa']->product_category_id)->update(['status' => CatalogStatus::Inactive->value]);
    expect(resErrors($input))->toBe($unavailable);

    ProductCategory::query()->whereKey($catalog['camisa']->product_category_id)->update(['status' => CatalogStatus::Active->value]);
    expect(resResolve($input)->toArray()['code'])->toBe('110-1');
});

it('E-17 answers "selection unavailable" for a product that does not exist or a missing product id', function () {
    $catalog = resCatalog();
    $input = resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']);

    expect(resErrors([...$input, 'product_id' => 999_999]))->toBe(['product' => [RES_UNAVAILABLE]])
        ->and(resErrors([...$input, 'product_id' => null]))->toBe(['product' => [RES_UNAVAILABLE]])
        ->and(resErrors(['axes' => []]))->toBe(['product' => [RES_UNAVAILABLE]]);
});

it('E-19 rejects a detail on a location the product does not admit, inactive, or with an inactive color', function () {
    $catalog = resCatalog();
    $detail = fn (string $location, string $color): array => [['location_id' => $catalog['locations'][$location]->id, 'color_value_id' => $catalog['vals'][$color]->id]];
    $input = fn (array $details): array => resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco'], ['details' => $details]);

    $ok = resResolve($input($detail('Pechera', 'Rojo')));

    expect($ok->toArray()['details'])->toBe([[
        'location_id' => $catalog['locations']['Pechera']->id,
        'location' => 'Pechera',
        'color' => ['id' => $catalog['vals']['Rojo']->id, 'name' => 'Rojo', 'tone' => '#C62828'],
    ]])
        // The detail color comes from the whole palette, not from the fabric (PRD-007).
        ->and(resErrors($input($detail('Pie de cuello', 'Rojo'))))->toBe(['details.0.location_id' => [resMessage('selection_location_not_admitted')]])
        ->and(resErrors($input($detail('Orilla de mangas', 'Rojo'))))->toBe(['details.0.location_id' => [resMessage('selection_location_not_admitted')]])
        ->and(resErrors($input($detail('Pechera', 'Fucsia'))))->toBe(['details.0.color_value_id' => [resMessage('selection_location_color_invalid')]])
        // A value of another attribute is not a palette color either.
        ->and(resErrors($input($detail('Pechera', 'Dama'))))->toBe(['details.0.color_value_id' => [resMessage('selection_location_color_invalid')]])
        ->and(array_keys(resErrors($input([...$detail('Pechera', 'Rojo'), ...$detail('Pie de cuello', 'Fucsia')]))))->toBe(['details.1.location_id', 'details.1.color_value_id']);
});

it('E-24 (resolve) stops resolving 110-4 while Microfibra is inactive and resolves it again after', function () {
    $catalog = resCatalog();
    $fabric = $catalog['attrs']['Tela']->id;
    $input = resInput($catalog, 'camisa', '110-4', ['Talla' => 'M', 'Color' => 'Blanco']);

    expect(resResolve($input)->toArray()['code'])->toBe('110-4');

    $catalog['vals']['Microfibra']->update(['status' => CatalogStatus::Inactive]);

    expect(resErrors($input))->toBe(["axes.$fabric" => [resMessage('selection_value_not_allowed')]])
        // The other combinations of the product are not affected.
        ->and(resResolve(resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']))->toArray()['code'])->toBe('110-1');

    $catalog['vals']['Microfibra']->update(['status' => CatalogStatus::Active]);

    expect(resResolve($input)->toArray()['code'])->toBe('110-4');
});

it('E-25 (resolution) resolves nothing while the product is inactive and each combination keeps its own status after', function () {
    $catalog = resCatalog();
    $active = resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']);
    $inactive = resInput($catalog, 'camisa', '110', ['Talla' => 'M', 'Color' => 'Blanco']);
    $catalog['combos']['110']->update(['status' => CatalogStatus::Inactive]);

    $catalog['camisa']->update(['status' => CatalogStatus::Inactive]);

    expect(resErrors($active))->toBe(['product' => [RES_UNAVAILABLE]])
        ->and(resErrors($inactive))->toBe(['product' => [RES_UNAVAILABLE]]);

    $catalog['camisa']->update(['status' => CatalogStatus::Active]);

    expect(resResolve($active)->toArray()['code'])->toBe('110-1')
        ->and(resErrors($inactive))->toBe(['product' => [RES_UNAVAILABLE]]);
});

it('E-34 rejects a customization the product does not admit or that is inactive', function () {
    $catalog = resCatalog();
    $input = fn (string ...$services): array => resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco'], [
        'customizations' => array_map(fn (string $name): int => $catalog['services'][$name]->id, $services),
    ]);

    expect(resResolve($input('Bordado pequeño'))->toArray()['customizations'])->toBe([['id' => $catalog['services']['Bordado pequeño']->id, 'name' => 'Bordado pequeño']])
        ->and(resErrors($input('Vinil')))->toBe(['customizations.0' => [resMessage('selection_customization_not_admitted')]])
        ->and(resErrors($input('Bordado pequeño', 'Estampado')))->toBe(['customizations.1' => [resMessage('selection_customization_not_admitted')]]);

    // Admitted by the product, then deactivated: it can no longer be added as an extra (Decision 14, rule 5).
    $catalog['camisa']->customizations()->attach($catalog['services']['Estampado']->id);
    $catalog['services']['Estampado']->update(['status' => CatalogStatus::Inactive]);

    expect(resErrors($input('Estampado')))->toBe(['customizations.0' => [resMessage('selection_customization_not_admitted')]]);
});

it('E-43 resolves 110 with a color of its fabric and rejects one it does not offer', function () {
    $catalog = resCatalog();
    $color = $catalog['attrs']['Color']->id;

    expect(resResolve(resInput($catalog, 'camisa', '110', ['Talla' => 'M', 'Color' => 'Blanco']))->toArray()['code'])->toBe('110')
        ->and(resErrors(resInput($catalog, 'camisa', '110', ['Talla' => 'M', 'Color' => 'Rojo'])))->toBe(["order.$color" => [resMessage('selection_color_not_offered')]]);
});

it('E-44 resolves a cap with its own colors and rejects one it does not list', function () {
    $catalog = resCatalog();
    $color = $catalog['attrs']['Color']->id;

    expect(resResolve(resInput($catalog, 'gorra', 'G-01', ['Color' => 'Negro']))->toArray()['code'])->toBe('G-01')
        ->and(resErrors(resInput($catalog, 'gorra', 'G-01', ['Color' => 'Azul marino'])))->toBe(["order.$color" => [resMessage('selection_color_not_offered')]]);
});

it('E-46 (resolution) offers the colors of the fabric as they are after the team changes them', function () {
    $catalog = resCatalog();
    $color = $catalog['attrs']['Color']->id;
    $fabric = $catalog['vals']['ALG-OXF Pima'];

    // Gris perla is added to the fabric and Verde removed (the catalog write is tested in Phase 4).
    $fabric->offeredColors()->attach($catalog['vals']['Gris perla']->id);
    $fabric->offeredColors()->detach($catalog['vals']['Verde']->id);

    expect(resResolve(resInput($catalog, 'camisa', '110', ['Talla' => 'M', 'Color' => 'Gris perla']))->toArray()['order'][1]['value'])->toBe('Gris perla')
        ->and(resErrors(resInput($catalog, 'camisa', '110', ['Talla' => 'M', 'Color' => 'Verde'])))->toBe(["order.$color" => [resMessage('selection_color_not_offered')]]);

    // An inactive color offered by the fabric is not available either.
    $catalog['vals']['Gris perla']->update(['status' => CatalogStatus::Inactive]);

    expect(resErrors(resInput($catalog, 'camisa', '110', ['Talla' => 'M', 'Color' => 'Gris perla'])))->toBe(["order.$color" => [resMessage('selection_color_not_offered')]]);
});

it('E-47 resolves 159-1 with the chosen fabric and takes the colors from that fabric', function () {
    $catalog = resCatalog();
    $color = $catalog['attrs']['Color']->id;
    $gabardina = resInput($catalog, 'camisa', '159-1', ['Talla' => 'M', 'Color' => 'Negro']);
    $drill = resInput($catalog, 'camisa', '159-1', ['Talla' => 'M', 'Color' => 'Blanco'], [], ['Tela' => 'Drill']);

    $result = resResolve($gabardina)->toArray();

    expect($result['code'])->toBe('159-1')
        ->and($result['axes'][0])->toMatchArray(['attribute' => 'Tela', 'value' => 'Gabardina'])
        ->and($result['descriptive_name'])->toBe('Camisa corporativa · Gabardina · Clásico · Manga larga · Caballero')
        // The same code with the other fabric keeps the fabric chosen.
        ->and(resResolve($drill)->toArray()['axes'][0])->toMatchArray(['value' => 'Drill'])
        // Negro belongs to Gabardina only: with Drill it is not offered.
        ->and(resErrors(resInput($catalog, 'camisa', '159-1', ['Talla' => 'M', 'Color' => 'Negro'], [], ['Tela' => 'Drill'])))->toBe(["order.$color" => [resMessage('selection_color_not_offered')]]);
});

it('E-49 resolves a custom color with its tone and note and requires an advisor', function () {
    $catalog = resCatalog();
    $color = $catalog['attrs']['Color']->id;
    $custom = fn (mixed $customColor): array => resInput($catalog, 'camisa', '110', ['Talla' => 'M', 'Color' => 'custom'], ['custom_color' => $customColor]);

    $result = resResolve($custom(['tone' => '#7A9A3B', 'note' => 'verde oliva corporativo']))->toArray();

    expect($result['code'])->toBe('110')
        ->and($result['requires_advisor'])->toBeTrue()
        ->and($result['order'][1])->toBe([
            'attribute_id' => $color, 'attribute' => 'Color', 'value_id' => null, 'value' => null,
            'custom' => true, 'tone' => '#7A9A3B', 'note' => 'verde oliva corporativo',
        ])
        ->and(resErrors($custom(null)))->toBe(["order.$color" => [resMessage('selection_custom_tone_invalid')]])
        ->and(resErrors($custom(['tone' => 'verde oliva', 'note' => 'x'])))->toBe(["order.$color" => [resMessage('selection_custom_tone_invalid')]])
        ->and(resErrors($custom(['tone' => '#7A9A3B', 'note' => str_repeat('a', 101)])))->toBe(["order.$color" => [resMessage('selection_custom_note_too_long')]])
        ->and(resResolve($custom(['tone' => '#7A9A3B', 'note' => str_repeat('a', 100)]))->toArray()['requires_advisor'])->toBeTrue();
});

it('E-50 rejects a custom color for stock with minimum, for an on-demand product with the option off, and on a detail', function () {
    $catalog = resCatalog();
    $color = $catalog['attrs']['Color']->id;
    $tone = ['tone' => '#7A9A3B'];

    expect(resErrors(resInput($catalog, 'gorra', 'G-01', ['Color' => 'custom'], ['custom_color' => $tone])))->toBe(["order.$color" => [resMessage('selection_custom_color_not_admitted')]])
        ->and(resErrors(resInput($catalog, 'polo', 'P-01', ['Color' => 'custom'], ['custom_color' => $tone])))->toBe(["order.$color" => [resMessage('selection_custom_color_not_admitted')]])
        ->and(resErrors(resInput($catalog, 'camisa', '110', ['Talla' => 'M', 'Color' => 'Blanco'], [
            'details' => [['location_id' => $catalog['locations']['Pechera']->id, 'color_value_id' => 'custom']],
        ])))->toBe(['details.0.color_value_id' => [resMessage('selection_location_color_invalid')]]);

    // The same on-demand product with the option on admits it (triangulation of the polo above).
    $catalog['polo']->update(['allows_custom_color' => true]);

    expect(resResolve(resInput($catalog, 'polo', 'P-01', ['Color' => 'custom'], ['custom_color' => $tone]))->toArray()['requires_advisor'])->toBeTrue();
});

it('E-54 (resolve) rejects a size outside the combination restriction and accepts it elsewhere', function () {
    $catalog = resCatalog();
    $size = $catalog['attrs']['Talla']->id;

    expect(resErrors(resInput($catalog, 'camisa', '110-2', ['Talla' => '2XL', 'Color' => 'Blanco'])))->toBe(["order.$size" => [resMessage('selection_value_restricted')]])
        ->and(resResolve(resInput($catalog, 'camisa', '110-2', ['Talla' => 'XL', 'Color' => 'Blanco']))->toArray()['code'])->toBe('110-2')
        // 110-1 has no restriction, so the whole product range applies.
        ->and(resResolve(resInput($catalog, 'camisa', '110-1', ['Talla' => '2XL', 'Color' => 'Blanco']))->toArray()['code'])->toBe('110-1');
});

it('E-67 (resolve) returns an included customization separately and does not offer it as an extra', function () {
    $catalog = resCatalog();
    $vinil = ['id' => $catalog['services']['Vinil']->id, 'name' => 'Vinil'];

    $result = resResolve(resInput($catalog, 'camisa', '110-2', ['Talla' => 'M', 'Color' => 'Blanco']))->toArray();

    expect($result['included_customizations'])->toBe([$vinil])
        ->and($result['customizations'])->toBe([])
        // The shirt does not admit vinil as an extra, so asking for it is an error even though 110-2 includes it.
        ->and(resErrors(resInput($catalog, 'camisa', '110-2', ['Talla' => 'M', 'Color' => 'Blanco'], ['customizations' => [$vinil['id']]])))
        ->toBe(['customizations.0' => [resMessage('selection_customization_not_admitted')]])
        // A combination that includes nothing returns an empty list.
        ->and(resResolve(resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']))->toArray()['included_customizations'])->toBe([]);
});

it('DEC-PRD-51 does not select a product that declares an inactive attribute', function () {
    $catalog = resCatalog();
    $input = resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']);

    $catalog['attrs']['Manga']->update(['status' => CatalogStatus::Inactive]);

    expect(resErrors($input))->toBe(['product' => [RES_UNAVAILABLE]])
        // The pants declare no inactive attribute and keep resolving.
        ->and(resResolve(resInput($catalog, 'pantalon', '184', ['Talla' => '38']))->toArray()['code'])->toBe('184');

    // Nothing was cascaded: reactivating the attribute restores the product.
    $catalog['attrs']['Manga']->update(['status' => CatalogStatus::Active]);

    expect(resResolve($input)->toArray()['code'])->toBe('110-1');
});

it('PRD-011 reports a defensive error when two active combinations match, which the overlap rule prevents', function () {
    $catalog = resCatalog();
    // Written straight to the database: the Actions would reject the overlap (DEC-PRD-39).
    resCombination($catalog, $catalog['camisa'], '110-X', array_map(fn (string $name): array => [$name], resAxesOf('110-1')));

    expect(resErrors(resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco'])))->toBe(['product' => [resMessage('selection_ambiguous')]]);
});

it('DEC-PRD-77 rejects an attribute the product does not declare instead of ignoring it', function () {
    $catalog = resCatalog();
    $talla = $catalog['attrs']['Talla']->id;
    $base = resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco']);
    $message = [resMessage('selection_attribute_not_declared')];

    expect(resErrors([...$base, 'axes' => $base['axes'] + [999999 => 1]]))->toBe(['axes.999999' => $message])
        ->and(resErrors([...$base, 'order' => $base['order'] + [999999 => 1]]))->toBe(['order.999999' => $message])
        // A declared order attribute sent as an axis is not declared as an axis.
        ->and(resErrors([...$base, 'axes' => $base['axes'] + [$talla => $base['order'][$talla]]]))->toBe(["axes.$talla" => $message]);
});

it('DEC-PRD-78 rejects a detail location or a customization sent more than once', function () {
    $catalog = resCatalog();
    $pechera = $catalog['locations']['Pechera']->id;
    $detail = fn (string $color): array => ['location_id' => $pechera, 'color_value_id' => $catalog['vals'][$color]->id];
    $bordado = $catalog['services']['Bordado pequeño']->id;
    $input = fn (array $extra): array => resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco'], $extra);

    expect(resErrors($input(['details' => [$detail('Rojo'), $detail('Azul marino')]])))->toBe(['details.1.location_id' => [resMessage('selection_location_repeated')]])
        ->and(resErrors($input(['customizations' => [$bordado, $bordado]])))->toBe(['customizations.1' => [resMessage('selection_customization_repeated')]])
        ->and(resResolve($input(['details' => [$detail('Rojo')], 'customizations' => [$bordado]]))->toArray()['customizations'])->toHaveCount(1);
});

it('PRD-011 has a message in Spanish for every reason code the rules return', function () {
    $codes = [
        'selection_unavailable', 'selection_ambiguous', 'selection_value_required', 'selection_value_not_allowed',
        'selection_color_not_offered', 'selection_value_restricted', 'selection_custom_color_not_admitted',
        'selection_custom_tone_invalid', 'selection_custom_note_too_long', 'selection_location_not_admitted',
        'selection_location_color_invalid', 'selection_customization_not_admitted',
        'selection_attribute_not_declared', 'selection_location_repeated', 'selection_customization_repeated',
        'selection_component_restricted', 'selection_component_not_in_combo', 'selection_axis_out_of_order',
    ];

    foreach ($codes as $code) {
        expect(resMessage($code))->not->toStartWith('validation.');
    }

    expect(resMessage('selection_unavailable'))->toBe(RES_UNAVAILABLE);
});

it('DT-02 loads the snapshot of a product with its active combinations, roles and availability', function () {
    $catalog = resCatalog();
    $catalog['combos']['110']->update(['status' => CatalogStatus::Inactive]);
    $fabric = $catalog['attrs']['Tela']->id;
    $size = $catalog['attrs']['Talla']->id;

    $snapshot = app(CatalogSnapshotLoader::class)->forProduct($catalog['camisa']->id);
    $byCode = collect($snapshot->combinations)->keyBy('code');
    $ids = fn (string ...$names): array => array_map(fn (string $name): int => $catalog['vals'][$name]->id, $names);

    expect($snapshot)->toBeInstanceOf(ProductSnapshot::class)
        ->and(app(CatalogSnapshotLoader::class)->forProduct(999_999))->toBeNull()
        ->and($snapshot->name)->toBe('Camisa corporativa')
        ->and($snapshot->active)->toBeTrue()
        ->and($snapshot->categoryActive)->toBeTrue()
        ->and($snapshot->admitsCustomColor)->toBeTrue()
        ->and(array_map(fn ($attribute): string => $attribute->name, $snapshot->attributes))->toBe(['Tela', 'Modelo', 'Manga', 'Género', 'Talla', 'Color'])
        ->and($snapshot->attributes[0]->allowed)->toEqualCanonicalizing($ids('ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra'))
        // Only active combinations are loaded: 110 is inactive.
        ->and($byCode->keys()->all())->toEqualCanonicalizing(['110-1', '110-2', '110-4', '159-1'])
        // A multi-valued axis keeps both values (DEC-PRD-33).
        ->and($byCode['159-1']->axes[$fabric])->toEqualCanonicalizing($ids('Drill', 'Gabardina'))
        // The size is an order attribute: it is a restriction of 110-2, never an axis.
        ->and(array_keys($byCode['110-2']->restrictions))->toBe([$size])
        ->and($byCode['110-2']->restrictions[$size])->toEqualCanonicalizing($ids('S', 'M', 'L', 'XL'))
        ->and($byCode['110-2']->axes)->not->toHaveKey($size)
        ->and($byCode['110-1']->restrictions)->toBe([])
        ->and(array_map(fn ($service): string => $service->name, $byCode['110-2']->included))->toBe(['Vinil'])
        ->and($byCode['110-1']->included)->toBe([])
        // Fabric colors: ALG-OXF Pima offers three active colors.
        ->and($snapshot->fabricColors[$catalog['vals']['ALG-OXF Pima']->id])->toEqualCanonicalizing($ids('Azul marino', 'Blanco', 'Verde'))
        // Admitted and active details and customizations only: Orilla de mangas is inactive.
        ->and(array_map(fn ($location): string => $location->name, $snapshot->detailLocations))->toBe(['Pechera'])
        ->and(array_map(fn ($service): string => $service->name, $snapshot->customizations))->toBe(['Bordado pequeño'])
        ->and($snapshot->templates)->toBe([]);
});

it('DT-02 does not admit a custom color on a product that is not on demand or has it disabled', function () {
    $catalog = resCatalog();
    $loader = app(CatalogSnapshotLoader::class);

    expect($loader->forProduct($catalog['camisa']->id)->admitsCustomColor)->toBeTrue()
        ->and($loader->forProduct($catalog['gorra']->id)->admitsCustomColor)->toBeFalse()
        ->and($loader->forProduct($catalog['polo']->id)->admitsCustomColor)->toBeFalse()
        // An on-demand product that declares no color attribute has no custom color to offer.
        ->and($loader->forProduct(Product::factory()->create()->id)->admitsCustomColor)->toBeFalse();
});

it('DT-02 query bound: the snapshot costs the same number of queries for 3 and for 30 combinations', function () {
    $catalog = resCatalog();
    $few = resBulkProduct($catalog, 'Camisa de 3', 3);
    $many = resBulkProduct($catalog, 'Camisa de 30', 30);
    $loader = app(CatalogSnapshotLoader::class);
    $loaded = null;

    $fewQueries = resQueryCount(fn () => $loader->forProduct($few->id));
    $manyQueries = resQueryCount(function () use ($loader, $many, &$loaded): void {
        $loaded = $loader->forProduct($many->id);
    });

    expect($loaded->combinations)->toHaveCount(30)
        ->and($manyQueries)->toBe($fewQueries)
        ->and($manyQueries)->toBeLessThanOrEqual(10);
});

it('DT-02 query bound: resolving costs a bounded number of queries and never reads prices or stock', function () {
    $catalog = resCatalog();
    $many = resBulkProduct($catalog, 'Camisa de 30', 30);
    $many->detailLocations()->attach(DetailLocation::factory()->create()->id);
    $catalog['camisa'] = $many;
    // The first combination of the bulk product has the axes of 110-1: ALG-OXF Pima, Columbia, corta, Dama.
    $input = resInput($catalog, 'camisa', '110-1', ['Talla' => 'M', 'Color' => 'Blanco'], [
        'details' => [['location_id' => $many->detailLocations()->first()->id, 'color_value_id' => $catalog['vals']['Rojo']->id]],
    ]);
    $statements = [];
    $result = null;

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = resResolve($input);
    DB::disableQueryLog();

    foreach (DB::getQueryLog() as $query) {
        $statements[] = $query['query'];
    }

    expect($result->toArray()['code'])->toBe('Camisa de 30-1')
        ->and(count($statements))->toBeLessThanOrEqual(10)
        ->and(count($statements))->toBeGreaterThan(0)
        ->and(implode("\n", $statements))->not->toMatch('/price|stock/i');
});

/**
 * Combo selection input. `$choices` maps a product name to its `axes` and `order` choices (attribute
 * name => value name), applied to every component that holds the product.
 *
 * @param  array<string, mixed>  $catalog
 * @param  array<string, array{axes?: array<string, string>, order?: array<string, string>}>  $choices
 * @return array<string, mixed>
 */
function resComboInput(array $catalog, Combo $combo, array $choices): array
{
    $ids = fn (array $map): array => collect($map)->mapWithKeys(fn (string $name, string $attribute): array => [
        $catalog['attrs'][$attribute]->id => $catalog['vals'][$name]->id,
    ])->all();
    $components = [];

    foreach ($combo->components as $component) {
        $name = collect($catalog['products'])->search(fn (Product $product): bool => $product->id === $component->product_id);
        $components[$component->id] = ['axes' => $ids($choices[$name]['axes'] ?? []), 'order' => $ids($choices[$name]['order'] ?? [])];
    }

    return ['combo_id' => $combo->id, 'components' => $components];
}

it('E-23 resolves the kit: each component to its combination and the chosen size for the diaper', function () {
    $catalog = resKitCatalog();
    $combo = makeCombo($catalog);
    $input = resComboInput($catalog, $combo, [
        'Pañal antiderrame' => ['axes' => ['Talla' => '4XG']],
        'Absorbente' => ['order' => ['Talla' => '3XG', 'Color' => 'Blanco']],
    ]);

    $result = resResolve($input);
    $data = $result->toArray();

    expect($result)->toBeInstanceOf(ResolvedCombo::class)
        ->and($data['kind'])->toBe('combo')
        ->and($data['combo'])->toBe(['id' => $combo->id, 'name' => 'Kit Oro antiderrame', 'code' => 'K-ORO'])
        ->and(array_column($data['components'], 'component_id'))->toBe([resComponentId($catalog, $combo, 'Pañal antiderrame'), resComponentId($catalog, $combo, 'Absorbente'), resComponentId($catalog, $combo, 'Protector de cama')])
        ->and(array_column($data['components'], 'quantity'))->toBe([2, 3, 1])
        ->and(array_column(array_column($data['components'], 'selection'), 'code'))->toBe(['11', '20', '30'])
        ->and($data['components'][0]['selection']['axes'][0]['value'])->toBe('4XG')
        ->and($data['requires_advisor'])->toBeFalse();
});

it('E-23 reports each failing component under its own prefix and leaves the valid ones out', function () {
    $catalog = resKitCatalog();
    $combo = makeCombo($catalog);
    $diaper = resComponentId($catalog, $combo, 'Pañal antiderrame');
    $absorbent = resComponentId($catalog, $combo, 'Absorbente');
    $protector = resComponentId($catalog, $combo, 'Protector de cama');
    $size = $catalog['attrs']['Talla']->id;
    $color = $catalog['attrs']['Color']->id;
    $input = resComboInput($catalog, $combo, ['Pañal antiderrame' => ['axes' => ['Talla' => '2XG']]]);

    // 2XG exists in the catalog but the diaper does not admit it: the error is on that component.
    expect(resErrors($input))->toBe([
        "components.$diaper.axes.$size" => [resMessage('selection_value_not_allowed')],
        "components.$absorbent.order.$size" => [resMessage('selection_value_required')],
        "components.$absorbent.order.$color" => [resMessage('selection_value_required')],
    ]);

    $input['components'][$protector]['details'] = [['location_id' => 999_999, 'color_value_id' => 999_999]];

    expect(resErrors($input))->toHaveKeys(["components.$protector.details.0.location_id", "components.$protector.details.0.color_value_id"])
        ->and(resErrors($input)["components.$protector.details.0.location_id"])->toBe([resMessage('selection_location_not_admitted')]);
});

it('E-63 (resolve) rejects Azul for a component restricted to Blanco and accepts Blanco', function () {
    $catalog = resKitCatalog();
    $combo = makeCombo($catalog, ['components' => [comboComponent($catalog, 'Absorbente', 1, ['Color' => ['Blanco']])]]);
    $absorbent = resComponentId($catalog, $combo, 'Absorbente');
    $input = fn (string $color): array => resComboInput($catalog, $combo, ['Absorbente' => ['order' => ['Talla' => '3XG', 'Color' => $color]]]);

    expect(resErrors($input('Azul')))->toBe(["components.$absorbent.order.{$catalog['attrs']['Color']->id}" => [resMessage('selection_component_restricted')]])
        ->and(resResolve($input('Blanco'))->toArray()['components'][0]['selection']['order'][1]['value'])->toBe('Blanco');
});

it('DEC-PRD-87 does not admit the custom color in a component, with or without a color restriction', function () {
    $catalog = resKitCatalog();
    // On its own the absorbent admits the custom color: on demand, option on, and it declares Color.
    $catalog['products']['Absorbente']->update(['supply_mode' => SupplyMode::OnDemand, 'allows_custom_color' => true]);
    $open = makeCombo($catalog, ['name' => 'Libre', 'code' => 'K-FREE', 'components' => [comboComponent($catalog, 'Absorbente')]]);
    $restricted = makeCombo($catalog, ['name' => 'Blanco', 'code' => 'K-WHITE', 'components' => [comboComponent($catalog, 'Absorbente', 1, ['Color' => ['Blanco']])]]);
    $color = $catalog['attrs']['Color']->id;
    $withCustom = function (Combo $combo) use ($catalog, $color): array {
        $input = resComboInput($catalog, $combo, ['Absorbente' => ['order' => ['Talla' => '3XG', 'Color' => 'Blanco']]]);
        $id = resComponentId($catalog, $combo, 'Absorbente');
        $input['components'][$id]['order'][$color] = 'custom';
        $input['components'][$id]['custom_color'] = ['tone' => '#112233', 'note' => 'azul'];

        return $input;
    };

    expect(resResolve(resComboInput($catalog, $open, ['Absorbente' => ['order' => ['Talla' => '3XG', 'Color' => 'Blanco']]]))->toArray()['kind'])->toBe('combo')
        ->and(resErrors($withCustom($open)))->toBe(['components.'.resComponentId($catalog, $open, 'Absorbente').".order.$color" => [resMessage('selection_component_restricted')]])
        ->and(resErrors($withCustom($restricted)))->toBe(['components.'.resComponentId($catalog, $restricted, 'Absorbente').".order.$color" => [resMessage('selection_component_restricted')]]);
});

it('E-63 (resolve) narrows the color of a product with fabric to the restriction of the component', function () {
    $catalog = resKitCatalog();
    $combo = makeCombo($catalog, ['components' => [comboComponent($catalog, 'Pañal ecológico', 1, ['Color' => ['Crema']])]]);
    $eco = resComponentId($catalog, $combo, 'Pañal ecológico');
    $input = fn (string $color): array => resComboInput($catalog, $combo, ['Pañal ecológico' => ['axes' => ['Tela' => 'Algodón'], 'order' => ['Color' => $color]]]);

    // Algodón offers Blanco and Crema, but the component admits only Crema.
    expect(resErrors($input('Blanco')))->toBe(["components.$eco.order.{$catalog['attrs']['Color']->id}" => [resMessage('selection_component_restricted')]])
        ->and(resResolve($input('Crema'))->toArray()['components'][0]['selection']['code'])->toBe('41');
});

it('PRD-010 applies the only admitted value of an axis or order attribute, so the client sends nothing for it (DEC-PRD-81)', function () {
    $catalog = resKitCatalog();
    $combo = makeCombo($catalog, ['components' => [
        comboComponent($catalog, 'Pañal antiderrame', 2, ['Talla' => ['5XG']]),
        comboComponent($catalog, 'Absorbente', 1, ['Talla' => ['4XG'], 'Color' => ['Azul']]),
    ]]);
    $data = resResolve(resComboInput($catalog, $combo, []))->toArray();
    $selections = array_column($data['components'], 'selection');

    expect(array_column($selections, 'code'))->toBe(['12', '20'])
        ->and($selections[0]['axes'][0]['value'])->toBe('5XG')
        ->and(array_column($selections[1]['order'], 'value'))->toBe(['4XG', 'Azul']);

    // A value the client did send is validated, never replaced by the only admitted one.
    $input = resComboInput($catalog, $combo, ['Pañal antiderrame' => ['axes' => ['Talla' => '4XG']]]);

    expect(resErrors($input))->toBe(['components.'.resComponentId($catalog, $combo, 'Pañal antiderrame').".axes.{$catalog['attrs']['Talla']->id}" => [resMessage('selection_component_restricted')]]);
});

it('PRD-010 does not offer a combo that is inactive or has a component that cannot be resolved (DEC-PRD-64)', function () {
    $catalog = resKitCatalog();
    $combo = makeCombo($catalog, ['components' => [comboComponent($catalog, 'Pañal antiderrame'), comboComponent($catalog, 'Protector de cama')]]);
    $input = resComboInput($catalog, $combo, ['Pañal antiderrame' => ['axes' => ['Talla' => '4XG']]]);
    $unavailable = ['combo' => [RES_UNAVAILABLE]];
    $protector = $catalog['products']['Protector de cama'];

    expect(resResolve($input)->toArray()['kind'])->toBe('combo');

    $combo->update(['status' => CatalogStatus::Inactive]);
    expect(resErrors($input))->toBe($unavailable);

    $combo->update(['status' => CatalogStatus::Active]);
    $protector->update(['status' => CatalogStatus::Inactive]);
    expect(resErrors($input))->toBe($unavailable);

    $protector->update(['status' => CatalogStatus::Active]);
    $protector->combinations()->update(['status' => CatalogStatus::Inactive]);
    expect(resErrors($input))->toBe($unavailable);

    $protector->combinations()->update(['status' => CatalogStatus::Active]);
    expect(resResolve($input)->toArray()['kind'])->toBe('combo');
});

it('PRD-010 stops offering a combo whose component is left with no option, and keeps offering the others (DEC-PRD-70)', function () {
    $catalog = resKitCatalog();
    $restricted = makeCombo($catalog, ['name' => 'Solo 3XG', 'code' => 'K-3', 'components' => [comboComponent($catalog, 'Pañal antiderrame', 1, ['Talla' => ['3XG']])]]);
    $open = makeCombo($catalog, ['name' => 'Todas las tallas', 'code' => 'K-ALL', 'components' => [comboComponent($catalog, 'Pañal antiderrame')]]);
    $eco = makeCombo($catalog, ['name' => 'Algodón crema', 'code' => 'K-ALG', 'components' => [comboComponent($catalog, 'Pañal ecológico', 1, ['Tela' => ['Algodón'], 'Color' => ['Crema']])]]);
    $ecoInput = resComboInput($catalog, $eco, ['Pañal ecológico' => ['order' => ['Color' => 'Crema']]]);

    expect(resResolve(resComboInput($catalog, $restricted, []))->toArray()['components'][0]['selection']['code'])->toBe('10')
        ->and(resResolve($ecoInput)->toArray()['components'][0]['selection']['code'])->toBe('41');

    // The only size the component admits is deactivated, and the only color it admits is withdrawn from its fabric.
    $catalog['vals']['3XG']->update(['status' => CatalogStatus::Inactive]);
    $catalog['vals']['Algodón']->offeredColors()->detach($catalog['vals']['Crema']->id);

    expect(resErrors(resComboInput($catalog, $restricted, [])))->toBe(['combo' => [RES_UNAVAILABLE]])
        ->and(resErrors($ecoInput))->toBe(['combo' => [RES_UNAVAILABLE]])
        ->and(resResolve(resComboInput($catalog, $open, ['Pañal antiderrame' => ['axes' => ['Talla' => '4XG']]]))->toArray()['components'][0]['selection']['code'])->toBe('11');
});

it('PRD-011 answers "not available" for a combo that does not exist and rejects a component that is not in the combo', function () {
    $catalog = resKitCatalog();
    $combo = makeCombo($catalog, ['components' => [comboComponent($catalog, 'Protector de cama')]]);
    $input = resComboInput($catalog, $combo, []);

    expect(resErrors(['combo_id' => 999_999]))->toBe(['combo' => [RES_UNAVAILABLE]])
        ->and(resErrors(['combo_id' => 'x']))->toBe(['combo' => [RES_UNAVAILABLE]])
        ->and(resResolve([...$input, 'combo_id' => (string) $combo->id])->toArray()['combo']['id'])->toBe($combo->id)
        // DEC-PRD-77 by analogy: a component the combo does not have is an error, never ignored.
        ->and(resErrors([...$input, 'components' => $input['components'] + [987_654 => []]]))->toBe(['components.987654' => [resMessage('selection_component_not_in_combo')]]);
});

it('DT-02 query bound: a combo costs the same number of queries for 2 and for 6 components, and forProducts for 1 and 4 products', function () {
    $catalog = resKitCatalog();
    $few = makeCombo($catalog, ['name' => 'Corto', 'code' => 'K-2', 'components' => [comboComponent($catalog, 'Pañal antiderrame', 1, ['Talla' => ['3XG', '4XG']]), comboComponent($catalog, 'Pañal ecológico')]]);
    $many = makeCombo($catalog, ['name' => 'Largo', 'code' => 'K-6', 'components' => [
        comboComponent($catalog, 'Pañal antiderrame'), comboComponent($catalog, 'Absorbente'), comboComponent($catalog, 'Protector de cama'),
        comboComponent($catalog, 'Pañal ecológico'), comboComponent($catalog, 'Pañal antiderrame', 2), comboComponent($catalog, 'Absorbente', 4),
    ]]);
    $choices = ['Pañal antiderrame' => ['axes' => ['Talla' => '3XG']], 'Absorbente' => ['order' => ['Talla' => '3XG', 'Color' => 'Blanco']], 'Pañal ecológico' => ['axes' => ['Tela' => 'Microfibra'], 'order' => ['Color' => 'Blanco']]];
    $loader = app(CatalogSnapshotLoader::class);
    $ids = array_map(fn (Product $product): int => $product->id, array_values($catalog['products']));

    $loaded = $loader->forCombo($many->id);
    $fewLoad = resQueryCount(fn () => $loader->forCombo($few->id));
    $manyLoad = resQueryCount(fn () => $loader->forCombo($many->id));
    $fewResolve = resQueryCount(fn () => resResolve(resComboInput($catalog, $few, $choices)));
    $manyResolve = resQueryCount(fn () => resResolve(resComboInput($catalog, $many, $choices)));
    // The eco diaper has fabric and combinations, so it runs every query the others may run.
    $oneProduct = resQueryCount(fn () => $loader->forProducts([$catalog['products']['Pañal ecológico']->id]));
    $allProducts = resQueryCount(fn () => $loader->forProducts($ids));
    $snapshots = $loader->forProducts([$ids[0], 999_999, $ids[1]]);

    expect($loaded->code)->toBe('K-6')
        ->and($loaded->active)->toBeTrue()
        ->and($loaded->components)->toHaveCount(6)
        ->and($manyLoad)->toBe($fewLoad)
        ->and($manyLoad)->toBeLessThanOrEqual(10)
        ->and($manyResolve)->toBe($fewResolve)
        ->and($manyResolve)->toBeLessThanOrEqual(10)
        ->and($allProducts)->toBe($oneProduct)
        ->and($allProducts)->toBeLessThanOrEqual(10)
        ->and(array_keys($snapshots))->toBe([$ids[0], $ids[1]])
        ->and($loader->forProducts([]))->toBe([])
        ->and($loader->forCombo(999_999))->toBeNull();
});
