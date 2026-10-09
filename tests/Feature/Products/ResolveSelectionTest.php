<?php

use App\Actions\Products\ResolveSelection;
use App\Enums\AttributeRole;
use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\DetailLocation;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Support\Products\Selection\CatalogSnapshotLoader;
use App\Support\Products\Selection\ProductSnapshot;
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
 * Declares attributes for a product. `$declared` maps an attribute name to its role and the names of
 * the admitted values (an empty list for the color of a product with fabric, DEC-PRD-35).
 *
 * @param  array{attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>}  $catalog
 * @param  array<string, array{0: string, 1: list<string>}>  $declared
 */
function resDeclare(array $catalog, Product $product, array $declared): void
{
    $position = 0;

    foreach ($declared as $attribute => [$role, $allowed]) {
        $row = ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'catalog_attribute_id' => $catalog['attrs'][$attribute]->id,
            'role' => AttributeRole::from($role),
            'sort_order' => ++$position,
        ]);
        $row->allowedValues()->attach(array_map(fn (string $name): int => $catalog['vals'][$name]->id, $allowed));
    }
}

/**
 * An active combination with its code. `$axes` and `$restrictions` map an attribute name to value
 * names; `$included` are service names the price already covers.
 *
 * @param  array<string, mixed>  $catalog
 * @param  array<string, list<string>>  $axes
 * @param  array<string, list<string>>  $restrictions
 * @param  list<string>  $included
 */
function resCombination(array $catalog, Product $product, string $code, array $axes, array $restrictions = [], array $included = [], bool $active = true): Combination
{
    $combination = Combination::factory()->create([
        'product_id' => $product->id,
        'status' => $active ? CatalogStatus::Active : CatalogStatus::Inactive,
    ]);
    CatalogCode::factory()->create(['code' => $code, 'combination_id' => $combination->id]);

    $pivot = [];

    foreach ([$axes, $restrictions] as $map) {
        foreach ($map as $attribute => $names) {
            foreach ($names as $name) {
                $pivot[$catalog['vals'][$name]->id] = ['catalog_attribute_id' => $catalog['attrs'][$attribute]->id];
            }
        }
    }

    $combination->values()->sync($pivot);
    $combination->customizations()->sync(array_map(fn (string $name): int => $catalog['services'][$name]->id, $included));

    return $combination;
}

/**
 * Axes of the shirt combinations by code (the first value of each axis).
 *
 * @return array<string, string>
 */
function resAxesOf(string $code): array
{
    $base = ['Tela' => 'ALG-OXF Pima', 'Modelo' => 'Columbia especial', 'Manga' => 'Manga corta', 'Género' => 'Dama'];

    return match ($code) {
        '110-1' => $base,
        '110' => [...$base, 'Género' => 'Caballero'],
        '110-2' => [...$base, 'Manga' => 'Manga larga'],
        '110-4' => [...$base, 'Tela' => 'Microfibra'],
        '159-1' => ['Tela' => 'Gabardina', 'Modelo' => 'Clásico', 'Manga' => 'Manga larga', 'Género' => 'Caballero'],
        default => [],
    };
}

/**
 * @return array{attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>, services: array<string, Product>, locations: array<string, DetailLocation>, camisa: Product, pantalon: Product, gorra: Product, polo: Product, combos: array<string, Combination>}
 */
function resCatalog(): array
{
    $attrs = [
        'Tela' => CatalogAttribute::factory()->fabric()->create(),
        'Modelo' => CatalogAttribute::factory()->create(['name' => 'Modelo']),
        'Manga' => CatalogAttribute::factory()->create(['name' => 'Manga']),
        'Género' => CatalogAttribute::factory()->gender()->create(),
        'Talla' => CatalogAttribute::factory()->size()->create(),
        'Color' => CatalogAttribute::factory()->color()->create(),
    ];

    $names = [
        'Tela' => ['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra'],
        'Modelo' => ['Columbia especial', 'Clásico'],
        'Manga' => ['Manga corta', 'Manga larga'],
        'Género' => ['Dama', 'Caballero'],
        'Talla' => ['S', 'M', 'L', 'XL', '2XL', '28', '38', '44', '46'],
    ];
    $vals = [];

    foreach ($names as $attribute => $valueNames) {
        foreach ($valueNames as $name) {
            $vals[$name] = AttributeValue::factory()->for($attrs[$attribute], 'catalogAttribute')->create(['name' => $name, 'sort_order' => count($vals) + 1]);
        }
    }

    $colors = ['Azul marino' => '#1F2A44', 'Blanco' => '#FFFFFF', 'Verde' => '#2E7D32', 'Rojo' => '#C62828', 'Gris perla' => '#BFC5CC', 'Negro' => '#111111'];

    foreach ($colors as $name => $tone) {
        $vals[$name] = AttributeValue::factory()->for($attrs['Color'], 'catalogAttribute')->withTone($tone)->create(['name' => $name, 'sort_order' => count($vals) + 1]);
    }

    $vals['Fucsia'] = AttributeValue::factory()->for($attrs['Color'], 'catalogAttribute')->withTone('#FF00FF')->inactive()->create(['name' => 'Fucsia', 'sort_order' => count($vals) + 1]);

    // Colors the team offers in each fabric (DEC-PRD-32): ALG-OXF Pima in three, Gabardina in two.
    $offered = ['ALG-OXF Pima' => ['Azul marino', 'Blanco', 'Verde'], 'Drill' => ['Blanco'], 'Gabardina' => ['Negro', 'Blanco'], 'Microfibra' => ['Blanco']];

    foreach ($offered as $fabric => $colorNames) {
        $vals[$fabric]->offeredColors()->attach(array_map(fn (string $name): int => $vals[$name]->id, $colorNames));
    }

    $services = [
        'Bordado pequeño' => Product::factory()->service()->create(['name' => 'Bordado pequeño']),
        'Vinil' => Product::factory()->service()->create(['name' => 'Vinil']),
        'Estampado' => Product::factory()->service()->create(['name' => 'Estampado']),
    ];

    $locations = [
        'Pechera' => DetailLocation::factory()->create(['name' => 'Pechera']),
        'Orilla de mangas' => DetailLocation::factory()->create(['name' => 'Orilla de mangas', 'status' => CatalogStatus::Inactive]),
        'Pie de cuello' => DetailLocation::factory()->create(['name' => 'Pie de cuello']),
    ];

    $catalog = ['attrs' => $attrs, 'vals' => $vals, 'services' => $services, 'locations' => $locations];

    $camisa = Product::factory()->create(['name' => 'Camisa corporativa']);
    resDeclare($catalog, $camisa, [
        'Tela' => ['axis', ['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra']],
        'Modelo' => ['axis', ['Columbia especial', 'Clásico']],
        'Manga' => ['axis', ['Manga corta', 'Manga larga']],
        'Género' => ['axis', ['Dama', 'Caballero']],
        'Talla' => ['order', ['S', 'M', 'L', 'XL', '2XL']],
        'Color' => ['order', []],
    ]);
    $camisa->detailLocations()->attach([$locations['Pechera']->id, $locations['Orilla de mangas']->id]);
    $camisa->customizations()->attach([$services['Bordado pequeño']->id]);

    $pantalon = Product::factory()->create(['name' => 'Pantalón de trabajo']);
    resDeclare($catalog, $pantalon, ['Talla' => ['order', ['28', '38', '44']]]);

    $gorra = Product::factory()->stockWithMinimum()->create(['name' => 'Gorra dryfit']);
    resDeclare($catalog, $gorra, ['Color' => ['order', ['Negro', 'Blanco']]]);

    $polo = Product::factory()->onDemand()->create(['name' => 'Polo sin personalizado', 'allows_custom_color' => false]);
    resDeclare($catalog, $polo, ['Color' => ['order', ['Negro']]]);

    $combos = [];

    foreach (['110-1', '110', '110-4', '159-1'] as $code) {
        $axes = array_map(fn (string $name): array => [$name], resAxesOf($code));
        $combos[$code] = resCombination($catalog, $camisa, $code, $code === '159-1' ? [...$axes, 'Tela' => ['Drill', 'Gabardina']] : $axes);
    }

    // 110-2 restricts the size to S-XL (E-54) and includes vinil, which the shirt does not admit as an extra (E-67).
    $combos['110-2'] = resCombination($catalog, $camisa, '110-2', array_map(fn (string $name): array => [$name], resAxesOf('110-2')), ['Talla' => ['S', 'M', 'L', 'XL']], ['Vinil']);
    $combos['184'] = resCombination($catalog, $pantalon, '184', []);
    $combos['G-01'] = resCombination($catalog, $gorra, 'G-01', []);
    $combos['P-01'] = resCombination($catalog, $polo, 'P-01', []);

    return [...$catalog, 'camisa' => $camisa, 'pantalon' => $pantalon, 'gorra' => $gorra, 'polo' => $polo, 'combos' => $combos];
}

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
function resResolve(array $input): ResolvedSelection
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

/**
 * Number of queries that `$callback` runs.
 */
function resQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

/**
 * A shirt with `$count` active combinations over a fabric, model, sleeve and gender axis (32 at
 * most), every fifth one including a service.
 *
 * @param  array<string, mixed>  $catalog
 */
function resBulkProduct(array $catalog, string $name, int $count): Product
{
    $product = Product::factory()->create(['name' => $name]);
    resDeclare($catalog, $product, [
        'Tela' => ['axis', ['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra']],
        'Modelo' => ['axis', ['Columbia especial', 'Clásico']],
        'Manga' => ['axis', ['Manga corta', 'Manga larga']],
        'Género' => ['axis', ['Dama', 'Caballero']],
        'Talla' => ['order', ['S', 'M', 'L']],
        'Color' => ['order', []],
    ]);

    $index = 0;

    foreach (['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra'] as $fabric) {
        foreach (['Columbia especial', 'Clásico'] as $model) {
            foreach (['Manga corta', 'Manga larga'] as $sleeve) {
                foreach (['Dama', 'Caballero'] as $gender) {
                    if ($index === $count) {
                        return $product;
                    }

                    resCombination($catalog, $product, "$name-".++$index, ['Tela' => [$fabric], 'Modelo' => [$model], 'Manga' => [$sleeve], 'Género' => [$gender]], [], $index % 5 === 0 ? ['Vinil'] : []);
                }
            }
        }
    }

    return $product;
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
