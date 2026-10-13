<?php

use App\Actions\Products\ListSelectionOptions;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Models\AttributeValue;
use App\Models\Combo;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Options for a partial selection (PRD-019, DT-02), called at Action level: no HTTP route exists in
 * 003. The fixtures are the ones of the resolution tests (`resCatalog()`, `resKitCatalog()`).
 */

/**
 * Axes by attribute and value name to `attributeId => valueId`.
 *
 * @param  array<string, mixed>  $catalog
 * @param  array<string, string>  $axes
 * @return array<int, int>
 */
function optAxes(array $catalog, array $axes): array
{
    return collect($axes)->mapWithKeys(fn (string $name, string $attribute): array => [$catalog['attrs'][$attribute]->id => $catalog['vals'][$name]->id])->all();
}

/**
 * Input for the options of a product with the axes of the combination `$code` cut to `$count` axes.
 *
 * @param  array<string, mixed>  $catalog
 * @return array<string, mixed>
 */
function optInput(array $catalog, string $product, string $code, ?int $count = null): array
{
    return ['product_id' => $catalog[$product]->id, 'axes' => optAxes($catalog, array_slice(resAxesOf($code), 0, $count))];
}

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function optRun(array $input): array
{
    return app(ListSelectionOptions::class)->handle($input)->toArray();
}

/**
 * @param  array<string, mixed>  $input
 * @return array<string, list<string>>
 */
function optErrors(array $input): array
{
    try {
        optRun($input);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new LogicException('The options were listed but errors were expected.');
}

function optMessage(string $code): string
{
    return __('validation.'.$code);
}

/**
 * Names of the options of the next axis.
 *
 * @param  array<string, mixed>  $result
 * @return list<string>
 */
function optAxisNames(array $result): array
{
    return array_column($result['axis']['options'], 'name');
}

/**
 * Names of the options of an order attribute, found by its name.
 *
 * @param  array<string, mixed>  $result
 * @return list<string>
 */
function optOrderNames(array $result, string $attribute): array
{
    return array_column(optOrderGroup($result, $attribute)['options'], 'name');
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function optOrderGroup(array $result, string $attribute): array
{
    $group = collect($result['order'])->firstWhere('attribute', $attribute);

    return $group ?? throw new LogicException("The order attribute $attribute is not in the result.");
}

/**
 * The corporate shirt of E-51: the fabric Algodón Egipto exists only in the combination 147-12
 * (Columbia especial, manga larga, dama).
 *
 * @param  array<string, mixed>  $catalog
 */
function optAddEgyptianCotton(array &$catalog): void
{
    $value = AttributeValue::factory()->for($catalog['attrs']['Tela'], 'catalogAttribute')->create(['name' => 'Algodón Egipto', 'sort_order' => 99]);
    $catalog['vals']['Algodón Egipto'] = $value;

    ProductAttribute::query()
        ->where('product_id', $catalog['camisa']->id)
        ->where('catalog_attribute_id', $catalog['attrs']['Tela']->id)
        ->firstOrFail()
        ->allowedValues()
        ->attach($value->id);

    resCombination($catalog, $catalog['camisa'], '147-12', ['Tela' => ['Algodón Egipto'], 'Modelo' => ['Columbia especial'], 'Manga' => ['Manga larga'], 'Género' => ['Dama']]);
}

it('E-51 offers only the values of the next axis that lead to an active combination, in the order of the axes', function () {
    $catalog = resCatalog();
    optAddEgyptianCotton($catalog);
    $tela = $catalog['attrs']['Tela'];
    $modelo = $catalog['attrs']['Modelo'];
    $manga = $catalog['attrs']['Manga'];
    $egypt = ['product_id' => $catalog['camisa']->id, 'axes' => optAxes($catalog, ['Tela' => 'Algodón Egipto'])];

    $first = optRun($egypt);

    expect($first['stage'])->toBe('axis')
        ->and($first['axis']['attribute_id'])->toBe($modelo->id)
        ->and($first['axis']['attribute'])->toBe('Modelo')
        ->and(optAxisNames($first))->toBe(['Columbia especial']);

    $egypt['axes'] += optAxes($catalog, ['Modelo' => 'Columbia especial']);
    $second = optRun($egypt);

    expect($second['axis']['attribute_id'])->toBe($manga->id)
        ->and(optAxisNames($second))->toBe(['Manga larga']);

    // Triangulation: nothing chosen asks for the first axis, and another fabric leads somewhere else.
    $none = optRun(['product_id' => $catalog['camisa']->id]);
    $gabardine = optRun(['product_id' => $catalog['camisa']->id, 'axes' => optAxes($catalog, ['Tela' => 'Gabardina'])]);
    $gender = optRun(optInput($catalog, 'camisa', '110-1', 3));

    expect($none['axis']['attribute_id'])->toBe($tela->id)
        ->and(optAxisNames($none))->toBe(['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra', 'Algodón Egipto'])
        ->and(optAxisNames($gabardine))->toBe(['Clásico'])
        ->and(optAxisNames($gender))->toBe(['Dama', 'Caballero']);
});

it('E-51 offers every value of an axis a combination lists (DEC-PRD-33) once the other axes match', function () {
    $catalog = resCatalog();

    // 159-1 lists Drill and Gabardina on the fabric axis: both lead to it, only it leads to Clásico.
    $fabrics = optRun(['product_id' => $catalog['camisa']->id]);
    $afterDrill = optRun(['product_id' => $catalog['camisa']->id, 'axes' => optAxes($catalog, ['Tela' => 'Drill'])]);
    $complete = optRun(['product_id' => $catalog['camisa']->id, 'axes' => optAxes($catalog, ['Tela' => 'Drill', 'Modelo' => 'Clásico', 'Manga' => 'Manga larga', 'Género' => 'Caballero'])]);

    expect(optAxisNames($fabrics))->toContain('Drill', 'Gabardina')
        ->and(optAxisNames($afterDrill))->toBe(['Clásico'])
        ->and($complete['code'])->toBe('159-1')
        ->and(optOrderNames($complete, 'Color'))->toBe(['Blanco']);
});

it('E-52 returns the code and the order options when every axis of 110 is chosen', function () {
    $catalog = resCatalog();
    $result = optRun(optInput($catalog, 'camisa', '110'));

    expect($result['stage'])->toBe('order')
        ->and($result['code'])->toBe('110')
        ->and($result['combination_id'])->toBe($catalog['combos']['110']->id)
        ->and(array_column($result['order'], 'attribute'))->toBe(['Talla', 'Color'])
        ->and(optOrderNames($result, 'Talla'))->toBe(['S', 'M', 'L', 'XL', '2XL'])
        ->and(optOrderNames($result, 'Color'))->toBe(['Azul marino', 'Blanco', 'Verde'])
        ->and(array_column(optOrderGroup($result, 'Color')['options'], 'tone'))->toBe(['#1F2A44', '#FFFFFF', '#2E7D32'])
        ->and(optOrderGroup($result, 'Color')['allows_custom_color'])->toBeTrue()
        ->and(optOrderGroup($result, 'Talla')['allows_custom_color'])->toBeFalse()
        // The location Orilla de mangas is inactive and Estampado is not admitted by the shirt.
        ->and(array_column($result['detail_locations'], 'name'))->toBe(['Pechera'])
        ->and(array_column($result['palette'], 'name'))->toBe(['Azul marino', 'Blanco', 'Verde', 'Rojo', 'Gris perla', 'Negro'])
        ->and(array_column($result['customizations'], 'name'))->toBe(['Bordado pequeño']);
});

it('E-52 offers the colors of the chosen fabric only, active, and the custom option only when the product admits it', function () {
    $catalog = resCatalog();
    $catalog['vals']['Azul marino']->update(['status' => CatalogStatus::Inactive]);
    $catalog['camisa']->update(['allows_custom_color' => false]);

    $pima = optRun(optInput($catalog, 'camisa', '110'));
    $gabardine = optRun(optInput($catalog, 'camisa', '159-1'));

    expect(optOrderNames($pima, 'Color'))->toBe(['Blanco', 'Verde'])
        ->and(optOrderGroup($pima, 'Color')['allows_custom_color'])->toBeFalse()
        ->and(optOrderNames($gabardine, 'Color'))->toBe(['Blanco', 'Negro']);
});

it('E-52 offers the colors of a product without fabric from the product, active, and adds the custom option only for on-demand products', function () {
    $catalog = resCatalog();

    $cap = optRun(['product_id' => $catalog['gorra']->id]);
    $polo = optRun(['product_id' => $catalog['polo']->id]);
    $catalog['vals']['Blanco']->update(['status' => CatalogStatus::Inactive]);
    $capWithoutWhite = optRun(['product_id' => $catalog['gorra']->id]);
    $catalog['gorra']->update(['supply_mode' => SupplyMode::OnDemand, 'min_stock_default' => null, 'allows_custom_color' => true]);
    $onDemandCap = optRun(['product_id' => $catalog['gorra']->id]);

    expect($cap['code'])->toBe('G-01')
        ->and(optOrderNames($cap, 'Color'))->toBe(['Blanco', 'Negro'])
        ->and(optOrderGroup($cap, 'Color')['allows_custom_color'])->toBeFalse()
        ->and(optOrderNames($polo, 'Color'))->toBe(['Negro'])
        ->and(optOrderNames($capWithoutWhite, 'Color'))->toBe(['Negro'])
        ->and(optOrderGroup($onDemandCap, 'Color')['allows_custom_color'])->toBeTrue();
});

it('E-53 does not offer a value that only an inactive combination or an inactive value reaches', function () {
    $catalog = resCatalog();
    $before = optRun(['product_id' => $catalog['camisa']->id]);

    $catalog['combos']['110-4']->update(['status' => CatalogStatus::Inactive]);
    $afterCombination = optRun(['product_id' => $catalog['camisa']->id]);
    $catalog['vals']['Drill']->update(['status' => CatalogStatus::Inactive]);
    $afterValue = optRun(['product_id' => $catalog['camisa']->id]);

    expect(optAxisNames($before))->toBe(['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra'])
        ->and(optAxisNames($afterCombination))->toBe(['ALG-OXF Pima', 'Drill', 'Gabardina'])
        ->and(optAxisNames($afterValue))->toBe(['ALG-OXF Pima', 'Gabardina']);
});

it('E-54 (options) returns only the sizes the combination admits', function () {
    $catalog = resCatalog();

    $restricted = optRun(optInput($catalog, 'camisa', '110-2'));
    $open = optRun(optInput($catalog, 'camisa', '110-1'));

    expect(optOrderNames($restricted, 'Talla'))->toBe(['S', 'M', 'L', 'XL'])
        ->and(optOrderNames($open, 'Talla'))->toBe(['S', 'M', 'L', 'XL', '2XL']);
});

it('E-55 returns every size of the product when the combination has no restriction, and the sizes of a product without axes', function () {
    $catalog = resCatalog();
    $trousers = optRun(['product_id' => $catalog['pantalon']->id]);

    expect(optOrderNames(optRun(optInput($catalog, 'camisa', '110')), 'Talla'))->toBe(['S', 'M', 'L', 'XL', '2XL'])
        ->and($trousers['code'])->toBe('184')
        ->and(optOrderNames($trousers, 'Talla'))->toBe(['28', '38', '44']);

    // A size the product stops admitting or that is deactivated is not offered.
    $catalog['vals']['2XL']->update(['status' => CatalogStatus::Inactive]);

    expect(optOrderNames(optRun(optInput($catalog, 'camisa', '110')), 'Talla'))->toBe(['S', 'M', 'L', 'XL']);
});

it('E-63 (options) returns only Blanco for the component restricted to Blanco and never the custom color', function () {
    $catalog = resKitCatalog();
    $catalog['products']['Absorbente']->update(['supply_mode' => SupplyMode::OnDemand, 'min_stock_default' => null, 'allows_custom_color' => true]);
    $restricted = makeCombo($catalog, ['name' => 'Blanco', 'code' => 'K-WHITE', 'components' => [comboComponent($catalog, 'Absorbente', 1, ['Color' => ['Blanco']])]]);
    $open = makeCombo($catalog, ['name' => 'Libre', 'code' => 'K-FREE', 'components' => [comboComponent($catalog, 'Absorbente')]]);

    $white = optRun(['combo_id' => $restricted->id, 'component_id' => resComponentId($catalog, $restricted, 'Absorbente')]);
    $free = optRun(['combo_id' => $open->id, 'component_id' => resComponentId($catalog, $open, 'Absorbente')]);
    $alone = optRun(['product_id' => $catalog['products']['Absorbente']->id]);

    expect($white['code'])->toBe('20')
        ->and(optOrderNames($white, 'Color'))->toBe(['Blanco'])
        ->and(optOrderGroup($white, 'Color')['allows_custom_color'])->toBeFalse()
        ->and(optOrderNames($free, 'Color'))->toEqualCanonicalizing(['Blanco', 'Azul'])
        // DEC-PRD-87: no component of a combo admits the custom color, although the product does on its own.
        ->and(optOrderGroup($free, 'Color')['allows_custom_color'])->toBeFalse()
        ->and(optOrderGroup($alone, 'Color')['allows_custom_color'])->toBeTrue();
});

it('DEC-PRD-94 marks a group as auto applied only when exactly one value is admitted, and lists that value anyway', function () {
    $catalog = resCatalog();

    $several = optRun(['product_id' => $catalog['pantalon']->id]);
    $catalog['vals']['28']->update(['status' => CatalogStatus::Inactive]);
    $catalog['vals']['38']->update(['status' => CatalogStatus::Inactive]);
    $single = optRun(['product_id' => $catalog['pantalon']->id]);

    expect(optOrderNames($several, 'Talla'))->toBe(['28', '38', '44'])
        ->and(optOrderGroup($several, 'Talla')['auto_applied'])->toBeFalse()
        ->and(optOrderNames($single, 'Talla'))->toBe(['44'])
        ->and(optOrderGroup($single, 'Talla')['auto_applied'])->toBeTrue();
});

it('DEC-PRD-94 leaves the color open when the product offers the custom option, even with one color listed', function () {
    $catalog = resCatalog();
    $catalog['vals']['Blanco']->update(['status' => CatalogStatus::Inactive]);
    $withoutCustom = optRun(['product_id' => $catalog['gorra']->id]);
    $catalog['gorra']->update(['supply_mode' => SupplyMode::OnDemand, 'min_stock_default' => null, 'allows_custom_color' => true]);
    $withCustom = optRun(['product_id' => $catalog['gorra']->id]);

    expect(optOrderNames($withoutCustom, 'Color'))->toBe(['Negro'])
        ->and(optOrderGroup($withoutCustom, 'Color')['auto_applied'])->toBeTrue()
        ->and(optOrderNames($withCustom, 'Color'))->toBe(['Negro'])
        ->and(optOrderGroup($withCustom, 'Color')['allows_custom_color'])->toBeTrue()
        ->and(optOrderGroup($withCustom, 'Color')['auto_applied'])->toBeFalse();
});

it('DEC-PRD-94 marks the size as auto applied when the combination restricts it to one value', function () {
    $catalog = resCatalog();
    $open = optRun(['product_id' => $catalog['pantalon']->id]);
    $catalog['combos']['184']->values()->sync([$catalog['vals']['38']->id => ['catalog_attribute_id' => $catalog['attrs']['Talla']->id]]);
    $restricted = optRun(['product_id' => $catalog['pantalon']->id]);

    expect(optOrderNames($open, 'Talla'))->toBe(['28', '38', '44'])
        ->and(optOrderGroup($open, 'Talla')['auto_applied'])->toBeFalse()
        ->and(optOrderNames($restricted, 'Talla'))->toBe(['38'])
        ->and(optOrderGroup($restricted, 'Talla')['auto_applied'])->toBeTrue();
});

it('DEC-PRD-94 marks the color of a fabric that offers one color as auto applied, unless the custom option is offered', function () {
    $catalog = resCatalog();
    // Microfibra offers only Blanco (DEC-PRD-35).
    $catalog['camisa']->update(['allows_custom_color' => false]);
    $withoutCustom = optRun(optInput($catalog, 'camisa', '110-4'));
    $catalog['camisa']->update(['allows_custom_color' => true]);
    $withCustom = optRun(optInput($catalog, 'camisa', '110-4'));
    $several = optRun(optInput($catalog, 'camisa', '110-1'));

    expect(optOrderNames($withoutCustom, 'Color'))->toBe(['Blanco'])
        ->and(optOrderGroup($withoutCustom, 'Color')['auto_applied'])->toBeTrue()
        ->and(optOrderNames($withCustom, 'Color'))->toBe(['Blanco'])
        ->and(optOrderGroup($withCustom, 'Color')['auto_applied'])->toBeFalse()
        ->and(optOrderNames($several, 'Color'))->toEqualCanonicalizing(['Azul marino', 'Blanco', 'Verde'])
        ->and(optOrderGroup($several, 'Color')['auto_applied'])->toBeFalse();
});

it('DEC-PRD-94 E-63 marks the color of a component restricted to Blanco as auto applied, and an open component as not', function () {
    $catalog = resKitCatalog();
    $catalog['products']['Absorbente']->update(['supply_mode' => SupplyMode::OnDemand, 'min_stock_default' => null, 'allows_custom_color' => true]);
    $restricted = makeCombo($catalog, ['name' => 'Blanco', 'code' => 'K-WHITE', 'components' => [comboComponent($catalog, 'Absorbente', 1, ['Color' => ['Blanco']])]]);
    $open = makeCombo($catalog, ['name' => 'Libre', 'code' => 'K-FREE', 'components' => [comboComponent($catalog, 'Absorbente')]]);

    $white = optRun(['combo_id' => $restricted->id, 'component_id' => resComponentId($catalog, $restricted, 'Absorbente')]);
    $free = optRun(['combo_id' => $open->id, 'component_id' => resComponentId($catalog, $open, 'Absorbente')]);
    $alone = optRun(['product_id' => $catalog['products']['Absorbente']->id]);

    expect(optOrderNames($white, 'Color'))->toBe(['Blanco'])
        ->and(optOrderGroup($white, 'Color')['auto_applied'])->toBeTrue()
        ->and(optOrderGroup($free, 'Color')['auto_applied'])->toBeFalse()
        ->and(optOrderGroup($alone, 'Color')['auto_applied'])->toBeFalse();
});

it('E-63 (options) narrows the axes and the colors of a component with fabric to what the component admits', function () {
    $catalog = resKitCatalog();
    $restricted = makeCombo($catalog, ['name' => 'Algodón crema', 'code' => 'K-ALG', 'components' => [comboComponent($catalog, 'Pañal ecológico', 1, ['Tela' => ['Algodón'], 'Color' => ['Crema']])]]);
    $open = makeCombo($catalog, ['name' => 'Todas las telas', 'code' => 'K-ECO', 'components' => [comboComponent($catalog, 'Pañal ecológico')]]);
    $restrictedId = resComponentId($catalog, $restricted, 'Pañal ecológico');
    $openId = resComponentId($catalog, $open, 'Pañal ecológico');
    $tela = $catalog['attrs']['Tela'];

    $fabricsOfRestricted = optRun(['combo_id' => $restricted->id, 'component_id' => $restrictedId]);
    $fabricsOfOpen = optRun(['combo_id' => $open->id, 'component_id' => $openId]);
    $colors = optRun(['combo_id' => $restricted->id, 'component_id' => $restrictedId, 'axes' => [$tela->id => $catalog['vals']['Algodón']->id]]);
    $openColors = optRun(['combo_id' => $open->id, 'component_id' => $openId, 'axes' => [$tela->id => $catalog['vals']['Algodón']->id]]);

    expect(optAxisNames($fabricsOfRestricted))->toBe(['Algodón'])
        ->and(optAxisNames($fabricsOfOpen))->toEqualCanonicalizing(['Microfibra', 'Algodón'])
        ->and($colors['code'])->toBe('41')
        // Algodón offers Blanco and Crema, but the component admits only Crema.
        ->and(optOrderNames($colors, 'Color'))->toBe(['Crema'])
        ->and(optOrderNames($openColors, 'Color'))->toEqualCanonicalizing(['Blanco', 'Crema']);
});

it('PRD-019 does not offer a combo that is not offered, a component the combo does not have, or a combo that does not exist', function () {
    $catalog = resKitCatalog();
    $combo = makeCombo($catalog, ['components' => [comboComponent($catalog, 'Protector de cama')]]);
    $component = resComponentId($catalog, $combo, 'Protector de cama');
    $unavailable = ['combo' => [optMessage('selection_unavailable')]];

    expect(optRun(['combo_id' => $combo->id, 'component_id' => $component])['code'])->toBe('30')
        ->and(optErrors(['combo_id' => $combo->id, 'component_id' => 987_654]))->toBe(['component_id' => [optMessage('selection_component_not_in_combo')]])
        ->and(optErrors(['combo_id' => $combo->id]))->toBe(['component_id' => [optMessage('selection_component_not_in_combo')]])
        ->and(optErrors(['combo_id' => 999_999, 'component_id' => $component]))->toBe($unavailable)
        ->and(optErrors(['combo_id' => 'x', 'component_id' => $component]))->toBe($unavailable);

    $combo->update(['status' => CatalogStatus::Inactive]);
    expect(optErrors(['combo_id' => $combo->id, 'component_id' => $component]))->toBe($unavailable);

    $combo->update(['status' => CatalogStatus::Active]);
    $catalog['products']['Protector de cama']->update(['status' => CatalogStatus::Inactive]);
    expect(optErrors(['combo_id' => $combo->id, 'component_id' => $component]))->toBe($unavailable);
});

it('PRD-019 rejects chosen axes that are not a prefix of the axis order, a value that is not an axis value and an attribute that is not an axis', function () {
    $catalog = resCatalog();
    $tela = $catalog['attrs']['Tela']->id;
    $modelo = $catalog['attrs']['Modelo']->id;
    $genero = $catalog['attrs']['Género']->id;
    $talla = $catalog['attrs']['Talla']->id;
    $ids = fn (array $axes): array => ['product_id' => $catalog['camisa']->id, 'axes' => optAxes($catalog, $axes)];

    expect(optErrors($ids(['Modelo' => 'Columbia especial'])))->toBe(["axes.$modelo" => [optMessage('selection_axis_out_of_order')]])
        ->and(optErrors($ids(['Tela' => 'ALG-OXF Pima', 'Género' => 'Dama'])))->toBe(["axes.$genero" => [optMessage('selection_axis_out_of_order')]])
        ->and(optErrors($ids(['Talla' => 'M'])))->toBe(["axes.$talla" => [optMessage('selection_attribute_not_declared')]])
        // A value of another attribute, an inactive value and an unknown id are not values of the axis.
        ->and(optErrors($ids(['Tela' => 'Dama'])))->toBe(["axes.$tela" => [optMessage('selection_value_not_allowed')]])
        ->and(optErrors(['product_id' => $catalog['camisa']->id, 'axes' => [$tela => 999_999]]))->toBe(["axes.$tela" => [optMessage('selection_value_not_allowed')]]);

    // A prefix whose values lead to no active combination is not available.
    expect(optErrors($ids(['Tela' => 'Microfibra', 'Modelo' => 'Clásico'])))->toBe(['product' => [optMessage('selection_unavailable')]]);

    $catalog['vals']['Drill']->update(['status' => CatalogStatus::Inactive]);
    expect(optErrors($ids(['Tela' => 'Drill'])))->toBe(["axes.$tela" => [optMessage('selection_value_not_allowed')]]);
});

it('PRD-019 does not offer a product that is unavailable (DEC-PRD-51, E-17)', function () {
    $catalog = resCatalog();
    $unavailable = ['product' => [optMessage('selection_unavailable')]];

    expect(optRun(['product_id' => $catalog['camisa']->id])['stage'])->toBe('axis')
        ->and(optErrors([]))->toBe($unavailable)
        ->and(optErrors(['product_id' => 999_999]))->toBe($unavailable);

    $catalog['attrs']['Manga']->update(['status' => CatalogStatus::Inactive]);
    expect(optErrors(['product_id' => $catalog['camisa']->id]))->toBe($unavailable);

    $catalog['attrs']['Manga']->update(['status' => CatalogStatus::Active]);
    $catalog['camisa']->update(['status' => CatalogStatus::Inactive]);
    expect(optErrors(['product_id' => $catalog['camisa']->id]))->toBe($unavailable);

    $catalog['camisa']->update(['status' => CatalogStatus::Active]);
    $catalog['camisa']->category->update(['status' => CatalogStatus::Inactive]);
    expect(optErrors(['product_id' => $catalog['camisa']->id]))->toBe($unavailable);
});

it('PRD-019 gives every option id, name, sort order, description, image URLs, tone and layer', function () {
    $catalog = resCatalog();
    $short = $catalog['vals']['Manga corta'];
    $white = $catalog['vals']['Blanco'];
    $short->update(['description' => 'Manga al codo', 'svg_layer' => 'manga-corta']);
    $white->update(['description' => 'Blanco óptico', 'svg_layer' => 'color-blanco']);
    $pechera = $catalog['locations']['Pechera'];
    $pechera->update(['svg_layer' => 'pechera']);

    $sleeves = optRun(optInput($catalog, 'camisa', '110', 2));
    $order = optRun(optInput($catalog, 'camisa', '110'));
    $option = fn (AttributeValue $value, ?string $tone): array => [
        'id' => $value->id, 'name' => $value->name, 'sort_order' => $value->sort_order, 'description' => $value->description,
        'image_urls' => [], 'tone' => $tone, 'layer' => $value->svg_layer,
    ];

    expect($sleeves['axis']['options'][0])->toBe($option($short, null))
        ->and($sleeves['axis']['options'][1]['layer'])->toBeNull()
        ->and($sleeves['axis']['options'][1]['description'])->toBeNull()
        ->and(optOrderGroup($order, 'Color')['options'][1])->toBe($option($white, '#FFFFFF'))
        ->and($order['palette'][1])->toBe($option($white, '#FFFFFF'))
        ->and($order['detail_locations'][0])->toBe(['id' => $pechera->id, 'name' => 'Pechera', 'layer' => 'pechera', 'image_urls' => []])
        ->and($order['customizations'][0])->toBe(['id' => $catalog['services']['Bordado pequeño']->id, 'name' => 'Bordado pequeño']);
});

it('DT-02 query bound: the options cost the same number of queries for 3 and for 30 combinations and never read prices or stock', function () {
    $catalog = resCatalog();
    $few = resBulkProduct($catalog, 'Camisa de 3', 3);
    $many = resBulkProduct($catalog, 'Camisa de 30', 30);
    $query = fn (Product $product, int $axes): array => ['product_id' => $product->id, 'axes' => optAxes($catalog, array_slice(resAxesOf('110-1'), 0, $axes))];

    foreach ([0, 2, 4] as $axes) {
        $fewCount = resQueryCount(fn () => optRun($query($few, $axes)));
        $manyCount = resQueryCount(fn () => optRun($query($many, $axes)));

        expect($manyCount)->toBe($fewCount)
            ->and($manyCount)->toBeLessThanOrEqual(10)
            ->and($manyCount)->toBeGreaterThan(0);
    }

    $statements = [];
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = optRun($query($many, 4));
    DB::disableQueryLog();

    foreach (DB::getQueryLog() as $logged) {
        $statements[] = $logged['query'];
    }

    expect($result['code'])->toBe('Camisa de 30-1')
        ->and(implode("\n", $statements))->not->toMatch('/price|stock/i');
});

it('DT-02 query bound: the options of a component cost the same number of queries for 2 and for 6 components', function () {
    $catalog = resKitCatalog();
    $few = makeCombo($catalog, ['name' => 'Corto', 'code' => 'K-2', 'components' => [comboComponent($catalog, 'Pañal ecológico'), comboComponent($catalog, 'Absorbente')]]);
    $many = makeCombo($catalog, ['name' => 'Largo', 'code' => 'K-6', 'components' => [
        comboComponent($catalog, 'Pañal ecológico'), comboComponent($catalog, 'Absorbente'), comboComponent($catalog, 'Protector de cama'),
        comboComponent($catalog, 'Pañal antiderrame'), comboComponent($catalog, 'Pañal antiderrame', 2), comboComponent($catalog, 'Absorbente', 4),
    ]]);
    $input = fn (Combo $combo, array $axes): array => ['combo_id' => $combo->id, 'component_id' => resComponentId($catalog, $combo, 'Pañal ecológico'), 'axes' => $axes];
    $tela = [$catalog['attrs']['Tela']->id => $catalog['vals']['Algodón']->id];

    foreach ([[], $tela] as $axes) {
        // The inputs are built outside the measured callback: finding the component id is a query too.
        [$fewInput, $manyInput] = [$input($few, $axes), $input($many, $axes)];
        $fewCount = resQueryCount(fn () => optRun($fewInput));
        $manyCount = resQueryCount(fn () => optRun($manyInput));

        expect($manyCount)->toBe($fewCount)
            ->and($manyCount)->toBeLessThanOrEqual(10);
    }
});
