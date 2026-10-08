<?php

use App\Enums\AttributeRole;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

function structureEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

/**
 * Catalog of the camisa corporativa example (E-05): fabric, model, sleeve and gender as axes, size
 * and color as order attributes, plus the neck attribute used to add or remove an attribute. Value
 * names are unique across the fixture so tests address them by name.
 *
 * @return array{attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>}
 */
function structureCatalog(): array
{
    $attrs = [
        'Tela' => CatalogAttribute::factory()->fabric()->create(),
        'Modelo' => CatalogAttribute::factory()->create(['name' => 'Modelo']),
        'Manga' => CatalogAttribute::factory()->create(['name' => 'Manga']),
        'Género' => CatalogAttribute::factory()->gender()->create(),
        'Talla' => CatalogAttribute::factory()->size()->create(),
        'Color' => CatalogAttribute::factory()->color()->create(),
        'Cuello' => CatalogAttribute::factory()->create(['name' => 'Cuello']),
    ];

    $names = [
        'Tela' => ['Drill', 'Microfibra'],
        'Modelo' => ['Clásico'],
        'Manga' => ['Corta', 'Larga'],
        'Género' => ['Dama', 'Caballero'],
        'Talla' => ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'],
        'Cuello' => ['Mao'],
    ];

    $vals = [];

    foreach ($names as $attribute => $valueNames) {
        foreach ($valueNames as $name) {
            $vals[$name] = AttributeValue::factory()->for($attrs[$attribute], 'catalogAttribute')->create(['name' => $name]);
        }
    }

    foreach (['Azul' => '#1F3A93', 'Blanco' => '#FFFFFF'] as $name => $tone) {
        $vals[$name] = AttributeValue::factory()->for($attrs['Color'], 'catalogAttribute')->withTone($tone)->create(['name' => $name]);
    }

    return ['attrs' => $attrs, 'vals' => $vals];
}

/**
 * One entry of the `PUT /products/{id}/attributes` payload.
 *
 * @param  list<string>  $valueNames
 * @return array{attribute_id: int, role: string, allowed_value_ids: list<int>}
 */
function structureEntry(array $catalog, string $attribute, string $role, array $valueNames = []): array
{
    return [
        'attribute_id' => $catalog['attrs'][$attribute]->id,
        'role' => $role,
        'allowed_value_ids' => array_map(fn (string $name): int => $catalog['vals'][$name]->id, $valueNames),
    ];
}

/**
 * The E-05 structure as a payload: Color has no allowed values because the fabric is declared.
 *
 * @return list<array{attribute_id: int, role: string, allowed_value_ids: list<int>}>
 */
function fullStructure(array $catalog): array
{
    return [
        structureEntry($catalog, 'Tela', 'axis', ['Drill', 'Microfibra']),
        structureEntry($catalog, 'Modelo', 'axis', ['Clásico']),
        structureEntry($catalog, 'Manga', 'axis', ['Corta', 'Larga']),
        structureEntry($catalog, 'Género', 'axis', ['Dama', 'Caballero']),
        structureEntry($catalog, 'Talla', 'order', ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL']),
        structureEntry($catalog, 'Color', 'order'),
    ];
}

/**
 * Stores a payload as the current structure of a product, bypassing the Action.
 *
 * @param  list<array{attribute_id: int, role: string, allowed_value_ids: list<int>}>  $entries
 */
function declareStructure(Product $product, array $entries): void
{
    foreach ($entries as $position => $entry) {
        $declared = ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'catalog_attribute_id' => $entry['attribute_id'],
            'role' => AttributeRole::from($entry['role']),
            'sort_order' => $position + 1,
        ]);
        $declared->allowedValues()->attach($entry['allowed_value_ids']);
    }
}

/**
 * A combination with a registry code whose axis values (and order restrictions) are the given values.
 *
 * @param  list<string>  $valueNames
 */
function structureCombination(Product $product, array $catalog, string $code, array $valueNames): Combination
{
    $values = array_map(fn (string $name): AttributeValue => $catalog['vals'][$name], $valueNames);
    $combination = Combination::factory()->for($product)->withAxes($values)->create();
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => $code]);

    return $combination;
}

/**
 * Current structure as comparable data: attribute name, role, sort order and value names.
 *
 * @return list<array{attribute: string, role: string, sort_order: int, values: list<string>}>
 */
function currentStructure(Product $product): array
{
    return $product->productAttributes()->with(['catalogAttribute', 'allowedValues'])->get()
        ->map(fn (ProductAttribute $declared): array => [
            'attribute' => $declared->catalogAttribute->name,
            'role' => $declared->role->value,
            'sort_order' => $declared->sort_order,
            'values' => $declared->allowedValues->pluck('name')->sort()->values()->all(),
        ])
        ->all();
}

/**
 * @return Collection<int, AuditLog>
 */
function structureAudit(): Collection
{
    return AuditLog::query()->where('action', AuditAction::ProductAttributesUpdated->value)->orderBy('id')->get();
}

function putStructure(mixed $test, User $actor, Product $product, array $entries): TestResponse
{
    return $test->actingAs($actor)->putJson("/products/{$product->id}/attributes", ['attributes' => $entries]);
}

// --- Declaring attributes (PRD-004) ----------------------------------------------------------

it('E-05 declares fabric, model, sleeve and gender as axes and size and color as order attributes, color without allowed values', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();

    putStructure($this, structureEditor(), $product, fullStructure($catalog))->assertRedirect();

    expect(currentStructure($product))->toBe([
        ['attribute' => 'Tela', 'role' => 'axis', 'sort_order' => 1, 'values' => ['Drill', 'Microfibra']],
        ['attribute' => 'Modelo', 'role' => 'axis', 'sort_order' => 2, 'values' => ['Clásico']],
        ['attribute' => 'Manga', 'role' => 'axis', 'sort_order' => 3, 'values' => ['Corta', 'Larga']],
        ['attribute' => 'Género', 'role' => 'axis', 'sort_order' => 4, 'values' => ['Caballero', 'Dama']],
        ['attribute' => 'Talla', 'role' => 'order', 'sort_order' => 5, 'values' => ['2XL', '3XL', 'L', 'M', 'S', 'XL', 'XS']],
        ['attribute' => 'Color', 'role' => 'order', 'sort_order' => 6, 'values' => []],
    ]);
});

it('PRD-004 requires a product with color and without fabric to supply its own color list', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Color', 'order')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.0.allowed_value_ids']);

    expect(currentStructure($product))->toBe([]);

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Color', 'order', ['Azul', 'Blanco'])])->assertRedirect();

    expect(currentStructure($product))->toBe([
        ['attribute' => 'Color', 'role' => 'order', 'sort_order' => 1, 'values' => ['Azul', 'Blanco']],
    ]);
});

it('DEC-PRD-35 rejects allowed values for the color of a product that declares the fabric', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();

    putStructure($this, structureEditor(), $product, [
        structureEntry($catalog, 'Tela', 'axis', ['Drill']),
        structureEntry($catalog, 'Color', 'order', ['Azul']),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.1.allowed_value_ids']);

    expect(currentStructure($product))->toBe([])
        ->and(structureAudit())->toHaveCount(0);
});

it('PRD-004 requires at least one allowed value for every attribute other than the color of a fabric product', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Tela', 'axis')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.0.allowed_value_ids']);

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Talla', 'order')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.0.allowed_value_ids']);
});

it('PRD-004 lets a product declare no attributes and clears the previous structure', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    declareStructure($product, [structureEntry($catalog, 'Talla', 'order', ['S', 'M'])]);

    putStructure($this, structureEditor(), $product, [])->assertRedirect();

    expect(currentStructure($product))->toBe([])
        ->and(structureAudit()->sole()->new_values)->toEqual(['attributes' => []]);
});

it('PRD-004 rejects a payload without the attributes list, an unknown role or an unknown attribute', function (array $payload, string $field) {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    $payload = $payload === ['bad-role'] ? ['attributes' => [[...structureEntry($catalog, 'Talla', 'order', ['S']), 'role' => 'sideways']]] : $payload;

    $this->actingAs(structureEditor())
        ->putJson("/products/{$product->id}/attributes", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'missing list' => [[], 'attributes'],
    'unknown role' => [['bad-role'], 'attributes.0.role'],
    'unknown attribute' => [['attributes' => [['attribute_id' => 999999, 'role' => 'order', 'allowed_value_ids' => [1]]]], 'attributes.0.attribute_id'],
]);

it('E-06 rejects declaring the same attribute twice, even with another role', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    declareStructure($product, [structureEntry($catalog, 'Talla', 'order', ['S', 'M'])]);

    putStructure($this, structureEditor(), $product, [
        structureEntry($catalog, 'Talla', 'order', ['S', 'M']),
        structureEntry($catalog, 'Talla', 'axis', ['S']),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.1.attribute_id']);

    expect(currentStructure($product))->toBe([
        ['attribute' => 'Talla', 'role' => 'order', 'sort_order' => 1, 'values' => ['M', 'S']],
    ]);
});

it('PRD-004 rejects an allowed value that belongs to another attribute', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Tela', 'axis', ['Drill', 'XS'])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.0.allowed_value_ids']);
});

// --- Roles of fabric and color (DEC-PRD-50) --------------------------------------------------

it('DEC-PRD-50 rejects the fabric as an order attribute and the color as an axis', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Tela', 'order', ['Drill'])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.0.role']);

    putStructure($this, structureEditor(), $product, [
        structureEntry($catalog, 'Talla', 'order', ['S']),
        structureEntry($catalog, 'Color', 'axis', ['Azul']),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.1.role']);

    expect(currentStructure($product))->toBe([])
        ->and(structureAudit())->toHaveCount(0);
});

// --- Inactive attributes and values (E-69, DEC-PRD-51) ---------------------------------------

it('E-69 (inactive) rejects declaring an inactive attribute but keeps one already declared that was deactivated later', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    $catalog['attrs']['Cuello']->forceFill(['status' => 'inactive'])->save();

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Cuello', 'order', ['Mao'])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.0.attribute_id']);

    expect(currentStructure($product))->toBe([]);

    $catalog['attrs']['Cuello']->forceFill(['status' => 'active'])->save();
    declareStructure($product, [structureEntry($catalog, 'Cuello', 'order', ['Mao'])]);
    $catalog['attrs']['Cuello']->forceFill(['status' => 'inactive'])->save();

    putStructure($this, structureEditor(), $product, [
        structureEntry($catalog, 'Cuello', 'order', ['Mao']),
        structureEntry($catalog, 'Talla', 'order', ['S']),
    ])->assertRedirect();

    expect(array_column(currentStructure($product), 'attribute'))->toBe(['Cuello', 'Talla']);
});

it('PRD-004 requires one active allowed value and rejects newly allowing an inactive one, but keeps one deactivated later', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    $catalog['vals']['XS']->forceFill(['status' => 'inactive'])->save();

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Talla', 'order', ['XS'])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.0.allowed_value_ids']);

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Talla', 'order', ['XS', 'S'])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes.0.allowed_value_ids']);

    $catalog['vals']['XS']->forceFill(['status' => 'active'])->save();
    declareStructure($product, [structureEntry($catalog, 'Talla', 'order', ['XS', 'S'])]);
    $catalog['vals']['XS']->forceFill(['status' => 'inactive'])->save();

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Talla', 'order', ['XS', 'S', 'M'])])->assertRedirect();

    expect(currentStructure($product)[0]['values'])->toBe(['M', 'S', 'XS']);
});

// --- Freeze while combinations exist (E-60, DEC-PRD-41) --------------------------------------

/**
 * The camisa corporativa with the E-05 structure and combination `110` (Drill, Clásico, Corta, Dama).
 *
 * @return array{product: Product, catalog: array{attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>}}
 */
function productWithCombinations(): array
{
    $catalog = structureCatalog();
    $product = Product::factory()->create(['name' => 'Camisa corporativa']);
    declareStructure($product, fullStructure($catalog));
    structureCombination($product, $catalog, '110', ['Drill', 'Clásico', 'Corta', 'Dama']);

    return ['product' => $product, 'catalog' => $catalog];
}

it('E-60 rejects adding an axis, changing size to axis or changing sleeve to order while combinations exist', function (string $change) {
    ['product' => $product, 'catalog' => $catalog] = productWithCombinations();
    $before = currentStructure($product);

    $entries = match ($change) {
        'new axis' => [...fullStructure($catalog), structureEntry($catalog, 'Cuello', 'axis', ['Mao'])],
        'size to axis' => array_map(
            fn (array $entry): array => $entry['attribute_id'] === $catalog['attrs']['Talla']->id ? [...$entry, 'role' => 'axis'] : $entry,
            fullStructure($catalog),
        ),
        'sleeve to order' => array_map(
            fn (array $entry): array => $entry['attribute_id'] === $catalog['attrs']['Manga']->id ? [...$entry, 'role' => 'order'] : $entry,
            fullStructure($catalog),
        ),
    };

    $response = putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect($response->json('errors.attributes.0'))->toContain('combinaciones')
        ->and(currentStructure($product))->toBe($before)
        ->and(structureAudit())->toHaveCount(0);
})->with(['new axis', 'size to axis', 'sleeve to order']);

it('E-60 rejects removing an axis attribute while combinations exist', function () {
    ['product' => $product, 'catalog' => $catalog] = productWithCombinations();
    $before = currentStructure($product);

    $entries = array_values(array_filter(
        fullStructure($catalog),
        fn (array $entry): bool => $entry['attribute_id'] !== $catalog['attrs']['Género']->id,
    ));

    putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect(currentStructure($product))->toBe($before)
        ->and(structureAudit())->toHaveCount(0);
});

it('E-60 still lets a product with combinations add and remove order attributes and reorder its axes', function () {
    ['product' => $product, 'catalog' => $catalog] = productWithCombinations();

    putStructure($this, structureEditor(), $product, [
        structureEntry($catalog, 'Tela', 'axis', ['Drill', 'Microfibra']),
        structureEntry($catalog, 'Género', 'axis', ['Dama', 'Caballero']),
        structureEntry($catalog, 'Manga', 'axis', ['Corta', 'Larga']),
        structureEntry($catalog, 'Modelo', 'axis', ['Clásico']),
        structureEntry($catalog, 'Talla', 'order', ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL']),
        structureEntry($catalog, 'Cuello', 'order', ['Mao']),
    ])->assertRedirect();

    expect(array_column(currentStructure($product), 'attribute'))->toBe(['Tela', 'Género', 'Manga', 'Modelo', 'Talla', 'Cuello'])
        ->and(structureAudit())->toHaveCount(1);
});

it('E-60 does not freeze a product whose structure changes before it has any combination', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    declareStructure($product, [structureEntry($catalog, 'Talla', 'order', ['S', 'M'])]);

    putStructure($this, structureEditor(), $product, [structureEntry($catalog, 'Talla', 'axis', ['S', 'M'])])->assertRedirect();

    expect(currentStructure($product)[0]['role'])->toBe('axis');
});

// --- Allowed values in use (E-24, E-57, DEC-PRD-37) ------------------------------------------

it('E-24 (removal) rejects removing Microfibra from the allowed values while combination 110-4 uses it', function () {
    ['product' => $product, 'catalog' => $catalog] = productWithCombinations();
    structureCombination($product, $catalog, '110-4', ['Microfibra', 'Clásico', 'Larga', 'Caballero']);
    $before = currentStructure($product);

    $entries = fullStructure($catalog);
    $entries[0] = structureEntry($catalog, 'Tela', 'axis', ['Drill']);

    $response = putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect($response->json('errors.attributes.0'))->toContain('110-4')
        ->and(currentStructure($product))->toBe($before)
        ->and(structureAudit())->toHaveCount(0);
});

it('E-24 (removal) allows removing an axis value that no combination uses', function () {
    ['product' => $product, 'catalog' => $catalog] = productWithCombinations();

    $entries = fullStructure($catalog);
    $entries[0] = structureEntry($catalog, 'Tela', 'axis', ['Drill']);

    putStructure($this, structureEditor(), $product, $entries)->assertRedirect();

    expect(currentStructure($product)[0]['values'])->toBe(['Drill']);
});

it('E-57 rejects removing XS while a combination restricts the size to XS-XL and names the combination', function () {
    ['product' => $product, 'catalog' => $catalog] = productWithCombinations();
    structureCombination($product, $catalog, '110-D', ['Drill', 'Clásico', 'Corta', 'Dama', 'XS', 'S', 'M', 'L', 'XL']);
    $before = currentStructure($product);

    $entries = fullStructure($catalog);
    $entries[4] = structureEntry($catalog, 'Talla', 'order', ['S', 'M', 'L', 'XL', '2XL', '3XL']);

    $response = putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect($response->json('errors.attributes.0'))->toContain('110-D')
        ->and(currentStructure($product))->toBe($before);

    $entries[4] = structureEntry($catalog, 'Talla', 'order', ['XS', 'S', 'M', 'L', 'XL', '2XL']);

    putStructure($this, structureEditor(), $product, $entries)->assertRedirect();

    expect(currentStructure($product)[4]['values'])->toBe(['2XL', 'L', 'M', 'S', 'XL', 'XS']);
});

it('E-57 rejects removing a whole order attribute whose values a combination restriction uses', function () {
    ['product' => $product, 'catalog' => $catalog] = productWithCombinations();
    structureCombination($product, $catalog, '110-D', ['Drill', 'Clásico', 'Corta', 'Dama', 'XS', 'S']);
    $before = currentStructure($product);

    $entries = array_values(array_filter(
        fullStructure($catalog),
        fn (array $entry): bool => $entry['attribute_id'] !== $catalog['attrs']['Talla']->id,
    ));

    $response = putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect($response->json('errors.attributes.0'))->toContain('110-D')
        ->and(currentStructure($product))->toBe($before);
});

it('E-57 only looks at the combinations of the same product', function () {
    ['product' => $product, 'catalog' => $catalog] = productWithCombinations();
    $other = Product::factory()->create();
    declareStructure($other, [structureEntry($catalog, 'Talla', 'order', ['XS', 'S'])]);
    structureCombination($other, $catalog, '999', ['XS']);

    $entries = fullStructure($catalog);
    $entries[4] = structureEntry($catalog, 'Talla', 'order', ['S', 'M', 'L', 'XL', '2XL', '3XL']);

    putStructure($this, structureEditor(), $product, $entries)->assertRedirect();

    expect(currentStructure($product)[4]['values'])->not->toContain('XS');
});

// --- Allowed values restricted by a combo component (E-24, E-57, DEC-PRD-37, DEC-PRD-44) --------

/**
 * A diaper product with the E-05 structure and no combinations, so only the combo guard can stop a
 * structure change.
 *
 * @return array{product: Product, catalog: array{attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>}}
 */
function diaperWithStructure(): array
{
    $catalog = structureCatalog();
    $product = Product::factory()->diapers()->create(['name' => 'Pañal antiderrame']);
    declareStructure($product, fullStructure($catalog));

    return ['product' => $product, 'catalog' => $catalog];
}

/**
 * A combo with one component of the product that admits only the given values (by name).
 *
 * @param  list<string>  $valueNames
 */
function structureComboComponent(Product $product, array $catalog, string $comboName, string $code, array $valueNames): Combo
{
    $combo = Combo::factory()->create(['name' => $comboName]);
    CatalogCode::factory()->forCombo($combo)->create(['code' => $code]);
    $component = ComboComponent::factory()->for($combo)->for($product)->create();

    foreach ($valueNames as $name) {
        DB::table('combo_component_values')->insert([
            'combo_component_id' => $component->id,
            'catalog_attribute_id' => $catalog['vals'][$name]->catalog_attribute_id,
            'attribute_value_id' => $catalog['vals'][$name]->id,
        ]);
    }

    return $combo;
}

it('E-24 (combo) rejects removing a size that a combo component restricts and names the combo', function () {
    ['product' => $product, 'catalog' => $catalog] = diaperWithStructure();
    structureComboComponent($product, $catalog, 'Kit Oro antiderrame', 'K-ORO', ['XS', 'S']);
    $before = currentStructure($product);

    $entries = fullStructure($catalog);
    $entries[4] = structureEntry($catalog, 'Talla', 'order', ['S', 'M', 'L', 'XL', '2XL', '3XL']);

    $response = putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect($response->json('errors.attributes.0'))->toContain('«K-ORO (Kit Oro antiderrame)»')
        ->and(currentStructure($product))->toBe($before)
        ->and(structureAudit())->toHaveCount(0);

    // A size no component restricts leaves without trouble.
    $entries[4] = structureEntry($catalog, 'Talla', 'order', ['XS', 'S', 'M', 'L', 'XL', '2XL']);

    putStructure($this, structureEditor(), $product, $entries)->assertRedirect();

    expect(currentStructure($product)[4]['values'])->not->toContain('3XL');
});

it('E-24 (combo) rejects removing an axis value that a combo component restricts', function () {
    ['product' => $product, 'catalog' => $catalog] = diaperWithStructure();
    structureComboComponent($product, $catalog, 'Kit Plata', 'K-02', ['Microfibra']);
    $before = currentStructure($product);

    $entries = fullStructure($catalog);
    $entries[0] = structureEntry($catalog, 'Tela', 'axis', ['Drill']);

    $response = putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect($response->json('errors.attributes.0'))->toContain('«K-02 (Kit Plata)»')
        ->and(currentStructure($product))->toBe($before);

    // Drill is not restricted by the component.
    $entries[0] = structureEntry($catalog, 'Tela', 'axis', ['Microfibra']);

    putStructure($this, structureEditor(), $product, $entries)->assertRedirect();

    expect(currentStructure($product)[0]['values'])->toBe(['Microfibra']);
});

it('E-57 (combo) rejects removing a whole order attribute whose values a combo component restricts', function () {
    ['product' => $product, 'catalog' => $catalog] = diaperWithStructure();
    structureComboComponent($product, $catalog, 'Kit Oro antiderrame', 'K-ORO', ['XS']);
    $before = currentStructure($product);

    $entries = array_values(array_filter(
        fullStructure($catalog),
        fn (array $entry): bool => $entry['attribute_id'] !== $catalog['attrs']['Talla']->id,
    ));

    $response = putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect($response->json('errors.attributes.0'))->toContain('«K-ORO (Kit Oro antiderrame)»')
        ->and(currentStructure($product))->toBe($before);
});

it('E-57 (combo) rejects removing the color attribute of a fabric product when a component restricts the color', function () {
    ['product' => $product, 'catalog' => $catalog] = diaperWithStructure();
    // The color of a product with fabric has no allowed values of its own, so only the attribute is removed.
    structureComboComponent($product, $catalog, 'Kit Oro antiderrame', 'K-ORO', ['Blanco']);
    $before = currentStructure($product);

    $entries = array_values(array_filter(
        fullStructure($catalog),
        fn (array $entry): bool => $entry['attribute_id'] !== $catalog['attrs']['Color']->id,
    ));

    $response = putStructure($this, structureEditor(), $product, $entries)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect($response->json('errors.attributes.0'))->toContain('«K-ORO (Kit Oro antiderrame)»')
        ->and(currentStructure($product))->toBe($before);
});

it('E-24 (combo) names every combo that restricts the value, sorted, and only looks at components of the same product', function () {
    ['product' => $product, 'catalog' => $catalog] = diaperWithStructure();
    structureComboComponent($product, $catalog, 'Kit Plata', 'K-02', ['XS']);
    structureComboComponent($product, $catalog, 'Kit Oro antiderrame', 'K-ORO', ['XS', 'S']);

    $other = Product::factory()->diapers()->create(['name' => 'Absorbente']);
    declareStructure($other, [structureEntry($catalog, 'Talla', 'order', ['XS', 'S'])]);
    structureComboComponent($other, $catalog, 'Kit juvenil', 'K-JUV', ['XS']);

    $entries = fullStructure($catalog);
    $entries[4] = structureEntry($catalog, 'Talla', 'order', ['S', 'M', 'L', 'XL', '2XL', '3XL']);

    $response = putStructure($this, structureEditor(), $product, $entries)->assertUnprocessable();

    expect($response->json('errors.attributes.0'))->toContain('«K-02 (Kit Plata)», «K-ORO (Kit Oro antiderrame)»')
        ->and($response->json('errors.attributes.0'))->not->toContain('Kit juvenil');
});

// --- Audit and authorization -----------------------------------------------------------------

it('PRD-004 audits products.attributes_updated with the previous and new structure by name', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    $actor = structureEditor();
    declareStructure($product, [structureEntry($catalog, 'Talla', 'order', ['S', 'M'])]);

    putStructure($this, $actor, $product, [
        structureEntry($catalog, 'Tela', 'axis', ['Drill']),
        structureEntry($catalog, 'Talla', 'order', ['M', 'L']),
    ])->assertRedirect();

    $audit = structureAudit()->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($product->id)
        ->and($audit->old_values)->toEqual(['attributes' => [
            ['attribute' => 'Talla', 'role' => 'order', 'values' => ['M', 'S']],
        ]])
        ->and($audit->new_values)->toEqual(['attributes' => [
            ['attribute' => 'Tela', 'role' => 'axis', 'values' => ['Drill']],
            ['attribute' => 'Talla', 'role' => 'order', 'values' => ['L', 'M']],
        ]]);
});

it('PRD-004 writes no audit row when the submitted structure equals the current one', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    declareStructure($product, fullStructure($catalog));

    putStructure($this, structureEditor(), $product, fullStructure($catalog))->assertRedirect();

    expect(structureAudit())->toHaveCount(0);
});

it('PRD-016 denies the structure change to a user without products.update and audits the denial', function () {
    $catalog = structureCatalog();
    $product = Product::factory()->create();
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsDeactivate, PermissionName::ProductsCatalog);

    putStructure($this, $actor, $product, [structureEntry($catalog, 'Talla', 'order', ['S'])])->assertForbidden();

    expect(currentStructure($product))->toBe([])
        ->and(structureAudit())->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->context['route'])->toBe('products.attributes.update');
});

it('PRD-004 answers 404 for an unknown product', function () {
    $this->actingAs(structureEditor())->putJson('/products/999999/attributes', ['attributes' => []])->assertNotFound();
});
