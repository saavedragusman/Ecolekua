<?php

use App\Actions\Quotations\CalculateQuotation;
use App\Enums\CatalogStatus;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\CombinationPrice;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\ComboItemPrice;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\SizeGroup;
use App\Support\Quotations\Calculation\CalculationInput;
use Illuminate\Validation\ValidationException;

// COT-008, COT-009 steps 1-8, COT-011, COT-012 (requires advisor), COT-025, DT-02, design Decisions 14-17:
// the calculation of a quotation's lines. Fictitious catalog and prices only (AGENTS.md §8).

/**
 * One catalog for uniforms and diapers (the size and color attributes are unique per catalog):
 * - Uniformes scheme: Detal 1–6, 7–12, 13–49 and "50 o más" by negotiation;
 * - shirt `110` (Caballero) and `110-1` (Dama, sizes XS–XL, includes vinil, no 7–12 price), chemise
 *   `155-1`, trousers `184` with size groups 28–36 and 38–42 (44 in no group);
 * - diaper `11` (Pañales scheme), absorbent `16` (Absorbentes scheme), infant diaper `001G` (fixed 3,50),
 *   bed protector `30`, embroidery `157` (Servicios scheme) and the "Kit Oro antiderrame" combo.
 *
 * @return array<string, mixed>
 */
function calcCatalog(): array
{
    $attrs = [
        'Género' => CatalogAttribute::factory()->gender()->create(),
        'Talla' => CatalogAttribute::factory()->size()->create(),
        'Color' => CatalogAttribute::factory()->color()->create(),
    ];

    $vals = [];
    $names = [
        'Género' => ['Dama', 'Caballero'],
        'Talla' => ['XS', 'S', 'M', 'L', 'XL', '2XL', '28', '30', '32', '34', '36', '38', '40', '42', '44'],
    ];

    foreach ($names as $attribute => $valueNames) {
        foreach ($valueNames as $name) {
            $vals[$name] = AttributeValue::factory()->for($attrs[$attribute], 'catalogAttribute')->create(['name' => $name, 'sort_order' => count($vals) + 1]);
        }
    }

    foreach (['Azul' => '#1F2A44', 'Blanco' => '#FFFFFF'] as $name => $tone) {
        $vals[$name] = AttributeValue::factory()->for($attrs['Color'], 'catalogAttribute')->withTone($tone)->create(['name' => $name, 'sort_order' => count($vals) + 1]);
    }

    $services = [
        'Bordado pequeño' => Product::factory()->service()->create(['name' => 'Bordado pequeño']),
        'Vinil' => Product::factory()->service()->create(['name' => 'Vinil']),
    ];

    $catalog = ['attrs' => $attrs, 'vals' => $vals, 'services' => $services];

    $schemes = [
        'Uniformes' => calcScheme('Uniformes', [['Detal', 1, 6], ['7–12', 7, 12], ['13–49', 13, 49], ['50 o más', 50, null, PriceTierKind::Negotiation]]),
        'Pañales' => calcScheme('Pañales', [['Detal', 1, 49], ['Mayor', 50, null]]),
        'Absorbentes' => calcScheme('Absorbentes', [['Especial', 1, 49], ['Mayor', 50, null]]),
        'Servicios' => calcScheme('Servicios', [['1–12', 1, 12], ['13–49', 13, 49], ['50 o más', 50, null]]),
        'Fijo' => PriceScheme::factory()->fixed()->create(['name' => 'Fijo']),
    ];

    $camisa = Product::factory()->onDemand()->create(['name' => 'Camisa corporativa', 'price_scheme_id' => $schemes['Uniformes']->id]);
    resDeclare($catalog, $camisa, [
        'Género' => ['axis', ['Dama', 'Caballero']],
        'Talla' => ['order', ['XS', 'S', 'M', 'L', 'XL', '2XL']],
        'Color' => ['order', ['Azul', 'Blanco']],
    ]);
    $camisa->customizations()->attach([$services['Bordado pequeño']->id]);

    $chemise = Product::factory()->onDemand()->create(['name' => 'Chemise', 'price_scheme_id' => $schemes['Uniformes']->id]);
    resDeclare($catalog, $chemise, ['Talla' => ['order', ['S', 'M', 'L']]]);

    $pantalon = Product::factory()->stockWithMinimum()->create(['name' => 'Pantalón de trabajo', 'price_scheme_id' => $schemes['Uniformes']->id]);
    resDeclare($catalog, $pantalon, ['Talla' => ['order', ['28', '30', '32', '34', '36', '38', '40', '42', '44']]]);

    $pañal = Product::factory()->diapers()->stockDepletable()->create(['name' => 'Pañal antiderrame', 'price_scheme_id' => $schemes['Pañales']->id]);
    $absorbente = Product::factory()->diapers()->stockDepletable()->create(['name' => 'Absorbente', 'price_scheme_id' => $schemes['Absorbentes']->id]);
    $infantil = Product::factory()->diapers()->stockDepletable()->create(['name' => 'Pañal infantil', 'price_scheme_id' => $schemes['Fijo']->id]);
    $protector = Product::factory()->diapers()->stockDepletable()->create(['name' => 'Protector de cama', 'price_scheme_id' => $schemes['Pañales']->id]);
    $services['Bordado pequeño']->forceFill(['price_scheme_id' => $schemes['Servicios']->id])->save();

    $combos = [
        '110' => resCombination($catalog, $camisa, '110', ['Género' => ['Caballero']]),
        '110-1' => resCombination($catalog, $camisa, '110-1', ['Género' => ['Dama']], ['Talla' => ['XS', 'S', 'M', 'L', 'XL']], ['Vinil']),
        '155-1' => resCombination($catalog, $chemise, '155-1', []),
        '184' => resCombination($catalog, $pantalon, '184', []),
        '11' => resCombination($catalog, $pañal, '11', []),
        '16' => resCombination($catalog, $absorbente, '16', []),
        '001G' => resCombination($catalog, $infantil, '001G', []),
        '30' => resCombination($catalog, $protector, '30', []),
        '157' => resCombination($catalog, $services['Bordado pequeño'], '157', []),
    ];

    $fx = [...$catalog, 'schemes' => $schemes, 'combos' => $combos, 'products' => [
        'camisa' => $camisa, 'chemise' => $chemise, 'pantalon' => $pantalon, 'pañal' => $pañal,
        'absorbente' => $absorbente, 'infantil' => $infantil, 'protector' => $protector, 'bordado' => $services['Bordado pequeño'],
    ]];

    calcPrice($fx, '110', 'Uniformes', ['Detal' => '40.00', '7–12' => '36.00', '13–49' => '32.00']);
    calcPrice($fx, '110-1', 'Uniformes', ['Detal' => '40.00', '13–49' => '32.00']);
    calcPrice($fx, '155-1', 'Uniformes', ['Detal' => '20.00', '7–12' => '18.00', '13–49' => '16.00']);
    calcPrice($fx, '11', 'Pañales', ['Detal' => '50.00', 'Mayor' => '45.00']);
    calcPrice($fx, '16', 'Absorbentes', ['Especial' => '15.00', 'Mayor' => '12.00']);
    calcPrice($fx, '001G', 'Fijo', ['Precio' => '3.50']);
    calcPrice($fx, '157', 'Servicios', ['1–12' => '2.00', '13–49' => '1.50']);

    $groups = calcGroups($fx, '184', [['28', '30', '32', '34', '36'], ['38', '40', '42']]);
    calcPrice($fx, '184', 'Uniformes', ['Detal' => '25.00', '13–49' => '21.00'], $groups[0]);
    calcPrice($fx, '184', 'Uniformes', ['Detal' => '28.00', '13–49' => '24.00'], $groups[1]);

    $kit = Combo::factory()->create(['name' => 'Kit Oro antiderrame']);
    CatalogCode::factory()->forCombo($kit)->create(['code' => 'K-ORO']);

    foreach ([[$pañal, 2, '30.00'], [$absorbente, 3, '20.00'], [$protector, 1, '35.00']] as $position => [$product, $quantity, $price]) {
        $component = ComboComponent::factory()->create(['combo_id' => $kit->id, 'product_id' => $product->id, 'quantity' => $quantity, 'sort_order' => $position + 1]);
        ComboItemPrice::factory()->create(['combo_component_id' => $component->id, 'unit_price' => $price]);
    }

    return [...$fx, 'kit' => $kit->fresh('components')];
}

/**
 * A tiered scheme with tiers given as `[label, min, max, kind = priced]`.
 *
 * @param  list<array{0: string, 1: int, 2: ?int, 3?: PriceTierKind}>  $tiers
 */
function calcScheme(string $name, array $tiers): PriceScheme
{
    $scheme = PriceScheme::factory()->create(['name' => $name, 'type' => PriceSchemeType::Tiered]);

    foreach ($tiers as $position => $tier) {
        $scheme->tiers()->create([
            'label' => $tier[0],
            'min_quantity' => $tier[1],
            'max_quantity' => $tier[2],
            'kind' => $tier[3] ?? PriceTierKind::Priced,
            'sort_order' => $position + 1,
        ]);
    }

    return $scheme;
}

/**
 * @param  array<string, string>  $prices  tier label => unit price
 */
function calcPrice(array $fx, string $code, string $scheme, array $prices, ?SizeGroup $group = null): void
{
    foreach ($prices as $tier => $price) {
        CombinationPrice::factory()->create([
            'combination_id' => $fx['combos'][$code]->id,
            'price_tier_id' => PriceTier::query()->where('price_scheme_id', $fx['schemes'][$scheme]->id)->where('label', $tier)->firstOrFail()->id,
            'size_group_id' => $group?->id,
            'unit_price' => $price,
        ]);
    }
}

/**
 * @param  list<list<string>>  $groups  size names of each group
 * @return list<SizeGroup>
 */
function calcGroups(array $fx, string $code, array $groups): array
{
    $combination = $fx['combos'][$code];
    $stored = [];

    foreach ($groups as $order => $names) {
        $group = SizeGroup::factory()->create(['combination_id' => $combination->id, 'sort_order' => $order + 1]);

        foreach ($names as $name) {
            $group->sizes()->attach($fx['vals'][$name]->id, ['combination_id' => $combination->id]);
        }

        $stored[] = $group;
    }

    return $stored;
}

/**
 * A product line. `$rows` maps a size name (or '' for no size) to its quantity; `$order` and `$axes` map
 * attribute names to value names.
 *
 * @param  array<string, int>  $rows
 * @param  array<string, string>  $axes
 * @param  array<string, string>  $order
 * @param  array<string, int>  $customizations  service name => quantity per garment
 * @return array<string, mixed>
 */
function calcLine(array $fx, string $product, array $rows, array $axes = [], array $order = [], array $customizations = [], array $selection = []): array
{
    $ids = fn (array $map): array => collect($map)->mapWithKeys(fn (string $value, string $attribute) => [$fx['attrs'][$attribute]->id => $fx['vals'][$value]->id])->all();

    return [
        'kind' => 'product',
        'product_id' => $fx['products'][$product]->id,
        'selection' => [...$selection, 'axes' => $ids($axes), 'order' => $ids($order) + ($selection['order'] ?? [])],
        'rows' => collect($rows)->map(fn (int $quantity, string $size) => [
            'size_value_id' => $size === '' ? null : $fx['vals'][$size]->id,
            'quantity' => $quantity,
        ])->values()->all(),
        'customizations' => collect($customizations)->map(fn (int $perGarment, string $service) => [
            'service_product_id' => $fx['services'][$service]->id,
            'quantity_per_garment' => $perGarment,
        ])->values()->all(),
    ];
}

/** A line of shirt `110` (Caballero, Azul) with `$quantity` units in size M. */
function calcShirts(array $fx, int $quantity, array $customizations = []): array
{
    return calcLine($fx, 'camisa', ['M' => $quantity], ['Género' => 'Caballero'], ['Color' => 'Azul'], $customizations);
}

/** @return array<string, mixed> */
function calcKit(array $fx, int $quantity): array
{
    return [
        'kind' => 'combo',
        'combo_id' => $fx['kit']->id,
        'combo_quantity' => $quantity,
        'components' => $fx['kit']->components->mapWithKeys(fn (ComboComponent $component) => [$component->id => []])->all(),
    ];
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @return array<string, mixed>
 */
function calculate(array $lines): array
{
    return app(CalculateQuotation::class)->handle(CalculationInput::fromArray(['lines' => $lines]))->toArray();
}

function calcUnit(array $line, int $row = 0): ?string
{
    return $line['rows'][$row]['unit_price']['value'] ?? null;
}

it('E-09 cotiza cada talla con el precio de su grupo de talla', function () {
    $fx = calcCatalog();

    $result = calculate([calcLine($fx, 'pantalon', ['34' => 2, '40' => 2])]);
    $line = $result['lines'][0];

    expect($line['tier_label'])->toBe('Detal')
        ->and(calcUnit($line, 0))->toBe('25.00')
        ->and(calcUnit($line, 1))->toBe('28.00')
        ->and($line['rows'][0]['size_group_label'])->toBe('28–36')
        ->and($line['rows'][1]['size_group_label'])->toBe('38–42')
        ->and($line['subtotal']['value'])->toBe('106.00')
        ->and($line['subtotal']['display'])->toBe('106,00')
        ->and($result['pieces_by_line'])->toBe(['uniforms' => 4, 'diapers' => 0]);
});

it('COT-025 una talla que no está en ningún grupo deja la línea sin precio', function () {
    $fx = calcCatalog();

    $line = calculate([calcLine($fx, 'pantalon', ['34' => 2, '44' => 1])])['lines'][0];

    expect($line['errors'])->toBe(['lines.0' => 'sin precio'])
        ->and($line['subtotal'])->toBeNull();
});

it('E-10 cotiza el combo con el precio de sus ítems por la cantidad de combos y nunca requiere asesora', function () {
    $fx = calcCatalog();

    $result = calculate([calcKit($fx, 2)]);
    $line = $result['lines'][0];

    expect($line['errors'])->toBe([])
        ->and($line['code'])->toBe('K-ORO')
        ->and($line['price_origin'])->toBe('combo')
        ->and($line['unit_price']['value'])->toBe('155.00')
        ->and($line['subtotal']['value'])->toBe('310.00')
        ->and($line['requires_advisor'])->toBeFalse()
        ->and($result['total']['value'])->toBe('310.00');
});

it('COT-025 un combo con un componente sin precio queda sin precio', function () {
    $fx = calcCatalog();
    ComboItemPrice::query()->where('combo_component_id', $fx['kit']->components->first()->id)->delete();

    $line = calculate([calcKit($fx, 1)])['lines'][0];

    expect($line['errors'])->toBe(['lines.0' => 'sin precio'])
        ->and($line['subtotal'])->toBeNull();
});

it('E-14 elige el tramo con el total de piezas de la línea de negocio', function () {
    $fx = calcCatalog();

    $result = calculate([
        calcShirts($fx, 5),
        calcLine($fx, 'chemise', ['M' => 5]),
        calcLine($fx, 'pantalon', ['32' => 3]),
    ]);

    expect($result['pieces_by_line']['uniforms'])->toBe(13)
        ->and(array_column($result['lines'], 'tier_label'))->toBe(['13–49', '13–49', '13–49'])
        ->and(array_map(fn (array $line) => calcUnit($line), $result['lines']))->toBe(['32.00', '16.00', '21.00'])
        ->and($result['total']['value'])->toBe('303.00');
});

it('E-15 con el mismo total cada producto usa el tramo de su propio esquema', function () {
    $fx = calcCatalog();

    $result = calculate([calcLine($fx, 'pañal', ['' => 4]), calcLine($fx, 'absorbente', ['' => 3])]);

    expect($result['pieces_by_line']['diapers'])->toBe(7)
        ->and($result['lines'][0]['tier_label'])->toBe('Detal')
        ->and(calcUnit($result['lines'][0]))->toBe('50.00')
        ->and($result['lines'][1]['tier_label'])->toBe('Especial')
        ->and(calcUnit($result['lines'][1]))->toBe('15.00');
});

it('E-16 en un pedido mixto cada línea de negocio cuenta aparte y el precio fijo no cuenta', function () {
    $fx = calcCatalog();

    $result = calculate([
        calcShirts($fx, 15),
        calcLine($fx, 'pañal', ['' => 10]),
        calcLine($fx, 'infantil', ['' => 2]),
    ]);

    expect($result['pieces_by_line'])->toBe(['uniforms' => 15, 'diapers' => 10])
        ->and($result['lines'][0]['tier_label'])->toBe('13–49')
        ->and($result['lines'][1]['tier_label'])->toBe('Detal')
        ->and($result['lines'][2]['price_origin'])->toBe('fixed')
        ->and(calcUnit($result['lines'][2]))->toBe('3.50')
        ->and($result['lines'][2]['subtotal']['value'])->toBe('7.00');
});

it('E-17 la séptima unidad pasa la línea del tramo Detal al 7–12', function () {
    $fx = calcCatalog();

    $six = calculate([calcShirts($fx, 6)])['lines'][0];
    $seven = calculate([calcShirts($fx, 7)])['lines'][0];

    expect([$six['tier_label'], calcUnit($six)])->toBe(['Detal', '40.00'])
        ->and([$seven['tier_label'], calcUnit($seven)])->toBe(['7–12', '36.00'])
        ->and($seven['price_origin'])->toBe('tier');
});

it('E-18 con 50 piezas las líneas quedan sin precio automático y requieren asesora', function () {
    $fx = calcCatalog();

    $result = calculate([calcShirts($fx, 30), calcLine($fx, 'chemise', ['M' => 20])]);

    foreach ($result['lines'] as $line) {
        expect($line['tier_label'])->toBe('50 o más')
            ->and(calcUnit($line))->toBeNull()
            ->and($line['subtotal'])->toBeNull()
            ->and($line['requires_advisor'])->toBeTrue()
            ->and($line['reason'])->toBe('50 piezas de uniformes o más')
            ->and($line['errors'])->toBe([]);
    }

    expect($result['requires_advisor'])->toBeTrue()
        ->and($result['total']['value'])->toBe('0.00')
        ->and($result['complete'])->toBeFalse();
});

it('E-18 con 49 piezas las líneas se cotizan en el tramo 13–49', function () {
    $fx = calcCatalog();

    $result = calculate([calcShirts($fx, 30), calcLine($fx, 'chemise', ['M' => 19])]);

    expect(array_column($result['lines'], 'tier_label'))->toBe(['13–49', '13–49'])
        ->and(array_column($result['lines'], 'requires_advisor'))->toBe([false, false])
        ->and($result['requires_advisor'])->toBeFalse();
});

it('E-23 el color personalizado muestra el precio del tramo como referencia y requiere asesora', function () {
    $fx = calcCatalog();
    $color = $fx['attrs']['Color']->id;

    $line = calcLine($fx, 'camisa', ['M' => 10], ['Género' => 'Caballero'], selection: [
        'order' => [$color => 'custom'],
        'custom_color' => ['tone' => '#123456', 'note' => 'Tono corporativo'],
    ]);
    $result = calculate([$line]);

    expect(calcUnit($result['lines'][0]))->toBe('36.00')
        ->and($result['lines'][0]['requires_advisor'])->toBeTrue()
        ->and($result['lines'][0]['reason'])->toBe('color personalizado')
        ->and($result['requires_advisor'])->toBeTrue()
        ->and($result['total']['value'])->toBe('0.00')
        ->and($result['complete'])->toBeFalse();
});

it('E-24 las personalizaciones añadidas e incluidas no se cobran ni cuentan piezas', function () {
    $fx = calcCatalog();

    $result = calculate([calcShirts($fx, 10, ['Bordado pequeño' => 2])]);
    $line = $result['lines'][0];

    expect($result['pieces_by_line']['uniforms'])->toBe(10)
        ->and($line['customizations'])->toBe([[
            'id' => $fx['services']['Bordado pequeño']->id,
            'name' => 'Bordado pequeño',
            'quantity_per_garment' => 2,
            'charge' => 'sin cargo',
        ]])
        ->and($line['subtotal']['value'])->toBe('360.00');

    $dama = calculate([calcLine($fx, 'camisa', ['M' => 2], ['Género' => 'Dama'], ['Color' => 'Azul'])])['lines'][0];

    expect($dama['included_customizations'])->toBe([['id' => $fx['services']['Vinil']->id, 'name' => 'Vinil']])
        ->and($dama['subtotal']['value'])->toBe('80.00');
});

it('E-25 un servicio vendido solo usa su propia cantidad y no suma a la línea de negocio', function () {
    $fx = calcCatalog();

    $result = calculate([calcShirts($fx, 10), calcLine($fx, 'bordado', ['' => 20])]);

    expect($result['pieces_by_line']['uniforms'])->toBe(10)
        ->and(calcUnit($result['lines'][0]))->toBe('36.00')
        ->and($result['lines'][1]['tier_label'])->toBe('13–49')
        ->and(calcUnit($result['lines'][1]))->toBe('1.50')
        ->and($result['lines'][1]['subtotal']['value'])->toBe('30.00');
});

it('E-26 una combinación sin precio en su tramo devuelve "sin precio" sin inventar valores', function () {
    $fx = calcCatalog();

    $result = calculate([calcLine($fx, 'camisa', ['M' => 8], ['Género' => 'Dama'], ['Color' => 'Azul'])]);
    $line = $result['lines'][0];

    expect($line['tier_label'])->toBe('7–12')
        ->and($line['errors'])->toBe(['lines.0' => 'sin precio'])
        ->and(calcUnit($line))->toBeNull()
        ->and($line['subtotal'])->toBeNull()
        ->and($result['total']['value'])->toBe('0.00')
        ->and($result['complete'])->toBeFalse();
});

it('E-27 un producto sin esquema devuelve "sin precio" y no cuenta piezas', function () {
    $fx = calcCatalog();
    $fx['products']['chemise']->forceFill(['price_scheme_id' => null])->save();

    $result = calculate([calcShirts($fx, 6), calcLine($fx, 'chemise', ['M' => 3])]);

    expect($result['lines'][1]['errors'])->toBe(['lines.1' => 'sin precio'])
        ->and($result['pieces_by_line']['uniforms'])->toBe(6)
        ->and($result['lines'][0]['tier_label'])->toBe('Detal');
});

it('DEC-COT-62 un producto con esquema inactivo queda sin precio y no cuenta para el tramo', function () {
    $fx = calcCatalog();
    $inactive = calcScheme('Uniformes viejo', [['Detal', 1, 6], ['7–12', 7, 12]]);
    $inactive->update(['status' => CatalogStatus::Inactive]);
    $fx['products']['chemise']->forceFill(['price_scheme_id' => $inactive->id])->save();

    $result = calculate([calcShirts($fx, 6), calcLine($fx, 'chemise', ['M' => 3])]);

    expect($result['lines'][1]['errors'])->toBe(['lines.1' => 'sin precio'])
        ->and($result['pieces_by_line']['uniforms'])->toBe(6)
        ->and($result['lines'][0]['tier_label'])->toBe('Detal')
        ->and(calcUnit($result['lines'][0]))->toBe('40.00');
});

it('E-28 una fila de talla inválida devuelve su error y la fila válida se calcula', function () {
    $fx = calcCatalog();
    $talla = $fx['attrs']['Talla']->id;

    $result = calculate([calcLine($fx, 'camisa', ['M' => 3, '2XL' => 2], ['Género' => 'Dama'], ['Color' => 'Azul'])]);
    $line = $result['lines'][0];

    expect(array_keys($line['errors']))->toBe(["lines.0.rows.1.order.{$talla}"])
        ->and(calcUnit($line, 0))->toBe('40.00')
        ->and($line['rows'][0]['subtotal']['value'])->toBe('120.00')
        ->and(calcUnit($line, 1))->toBeNull()
        ->and($result['pieces_by_line']['uniforms'])->toBe(3)
        ->and($result['total']['value'])->toBe('0.00');
});

it('E-49 las unidades de los combos no cuentan para el tramo', function () {
    $fx = calcCatalog();

    $result = calculate([calcKit($fx, 1), calcLine($fx, 'pañal', ['' => 15])]);

    expect($result['pieces_by_line']['diapers'])->toBe(15)
        ->and($result['lines'][1]['tier_label'])->toBe('Detal')
        ->and(calcUnit($result['lines'][1]))->toBe('50.00');
});

it('COT-009 devuelve el código y el nombre descriptivo de la combinación resuelta', function () {
    $fx = calcCatalog();

    $line = calculate([calcShirts($fx, 2)])['lines'][0];

    expect($line['code'])->toBe('110')
        ->and($line['descriptive_name'])->toBeString()->not->toBe('');
});

it('COT-009 la estructura mal formada se rechaza como error de validación', function () {
    CalculationInput::fromArray(['lines' => [['kind' => 'otra']]]);
})->throws(ValidationException::class);

it('DT-02 el número de consultas no depende de las líneas ni de las filas de un mismo producto', function () {
    $fx = calcCatalog();
    $sizes = ['XS' => 1, 'S' => 1, 'M' => 1, 'L' => 1, 'XL' => 1, '2XL' => 1];
    $shirtLine = fn (array $rows) => calcLine($fx, 'camisa', $rows, ['Género' => 'Caballero'], ['Color' => 'Azul']);
    $chemiseLine = fn (array $rows) => calcLine($fx, 'chemise', $rows);

    $one = resQueryCount(fn () => calculate([$shirtLine(['M' => 1])]));
    $many = resQueryCount(fn () => calculate(array_fill(0, 5, $shirtLine($sizes))));
    $oneWithSecond = resQueryCount(fn () => calculate([$shirtLine(['M' => 1]), $chemiseLine(['M' => 1])]));
    $manyWithSecond = resQueryCount(fn () => calculate([...array_fill(0, 5, $shirtLine($sizes)), ...array_fill(0, 5, $chemiseLine(['S' => 1, 'M' => 1, 'L' => 1]))]));

    expect($many)->toBe($one)
        ->and($manyWithSecond - $many)->toBe($oneWithSecond - $one);
});
