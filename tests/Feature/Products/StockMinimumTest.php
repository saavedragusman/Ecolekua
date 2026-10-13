<?php

use App\Enums\AttributeRole;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\StockMinimumOverride;
use App\Models\User;
use App\Support\Products\StockMinimum;
use Illuminate\Support\Collection;

function minimumEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

/**
 * A product in `stock_with_minimum` (default 2) with a model axis and the size attribute as an order
 * attribute admitting 38, 40 and 42. `PAN-1` is not restricted by size; `PAN-2` is restricted to 40.
 *
 * @return array{product: Product, size: CatalogAttribute, sizes: array<string, AttributeValue>, free: Combination, restricted: Combination}
 */
function minimumFixture(): array
{
    $size = CatalogAttribute::factory()->size()->create();
    $model = CatalogAttribute::factory()->create(['name' => 'Modelo']);
    $sizes = [];

    foreach (['38', '40', '42'] as $name) {
        $sizes[$name] = AttributeValue::factory()->for($size, 'catalogAttribute')->create(['name' => $name]);
    }

    $classic = AttributeValue::factory()->for($model, 'catalogAttribute')->create(['name' => 'Clásico']);
    $sport = AttributeValue::factory()->for($model, 'catalogAttribute')->create(['name' => 'Deportivo']);

    $product = Product::factory()->for(ProductCategory::factory(), 'category')->stockWithMinimum(2)->create();

    ProductAttribute::factory()->create(['product_id' => $product->id, 'catalog_attribute_id' => $model->id, 'role' => AttributeRole::Axis, 'sort_order' => 1])
        ->allowedValues()->attach([$classic->id, $sport->id]);
    ProductAttribute::factory()->create(['product_id' => $product->id, 'catalog_attribute_id' => $size->id, 'role' => AttributeRole::Order, 'sort_order' => 2])
        ->allowedValues()->attach(array_map(fn (AttributeValue $value): int => $value->id, $sizes));

    $free = Combination::factory()->for($product)->withAxes([$classic])->create();
    CatalogCode::factory()->create(['combination_id' => $free->id, 'code' => 'PAN-1']);
    $restricted = Combination::factory()->for($product)->withAxes([$sport, $sizes['40']])->create();
    CatalogCode::factory()->create(['combination_id' => $restricted->id, 'code' => 'PAN-2']);

    return ['product' => $product, 'size' => $size, 'sizes' => $sizes, 'free' => $free, 'restricted' => $restricted];
}

/**
 * Stores own minimums directly: the endpoint that edits them arrives in the next slice.
 *
 * @param  list<array{combination_id: int, size_value_id: int|null, minimum: int}>  $rows
 */
function seedOverrides(array $rows): void
{
    foreach ($rows as $row) {
        StockMinimumOverride::query()->create($row);
    }
}

/**
 * @return Collection<int, AuditLog>
 */
function minimumAudit(AuditAction $action): Collection
{
    return AuditLog::query()->where('action', $action->value)->orderBy('id')->get();
}

/**
 * @return list<array{combination_id: int, size_value_id: int|null, minimum: int}>
 */
function storedOverrides(): array
{
    return StockMinimumOverride::query()->orderBy('id')->get()
        ->map(fn (StockMinimumOverride $row): array => ['combination_id' => $row->combination_id, 'size_value_id' => $row->size_value_id, 'minimum' => $row->minimum])
        ->all();
}

// --- Minimum of an article (E-65) ------------------------------------------------------------

it('E-65 gives the article with an own value that value and every other size the default', function () {
    $fx = minimumFixture();

    seedOverrides([['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4]]);

    expect(StockMinimum::for($fx['free'], $fx['sizes']['38']->id))->toBe(4)
        ->and(StockMinimum::for($fx['free'], $fx['sizes']['40']->id))->toBe(2)
        ->and(StockMinimum::for($fx['restricted'], $fx['sizes']['40']->id))->toBe(2);
});

it('E-65 rejects saving a product in stock_with_minimum without a default minimum', function () {
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->stockWithMinimum(2)->create();

    $this->actingAs(minimumEditor())
        ->putJson("/products/{$product->id}", [
            'name' => $product->name,
            'product_category_id' => $product->product_category_id,
            'business_line' => $product->business_line->value,
            'supply_mode' => 'stock_with_minimum',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock_default']);

    expect($product->fresh()->min_stock_default)->toBe(2);
});

// --- Mode change (E-66) ----------------------------------------------------------------------

it('E-66 deletes every own minimum when the mode changes and audits the deleted rows in products.updated, then requires a new default on return', function () {
    $fx = minimumFixture();
    $editor = minimumEditor();
    seedOverrides([
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4],
        ['combination_id' => $fx['restricted']->id, 'size_value_id' => $fx['sizes']['40']->id, 'minimum' => 1],
    ]);

    $payload = fn (array $extra): array => [
        'name' => $fx['product']->name,
        'product_category_id' => $fx['product']->product_category_id,
        'business_line' => 'uniforms',
        ...$extra,
    ];

    $this->actingAs($editor)->putJson("/products/{$fx['product']->id}", $payload(['supply_mode' => 'on_demand']))->assertRedirect();

    $audit = minimumAudit(AuditAction::ProductUpdated)->sole();

    expect(storedOverrides())->toBe([])
        ->and($audit->action)->toBe('products.updated')
        ->and($audit->old_values['stock_minimum_overrides'])->toEqual([
            ['code' => 'PAN-1', 'size' => '38', 'minimum' => 4],
            ['code' => 'PAN-2', 'size' => '40', 'minimum' => 1],
        ])
        ->and($audit->new_values['stock_minimum_overrides'])->toEqual([])
        ->and(AuditLog::query()->whereIn('action', ['products.attributes_updated', 'products.stock_minimums_updated'])->count())->toBe(0);

    $this->actingAs($editor)->putJson("/products/{$fx['product']->id}", $payload(['supply_mode' => 'stock_with_minimum']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock_default']);

    $this->actingAs($editor)->putJson("/products/{$fx['product']->id}", $payload(['supply_mode' => 'stock_with_minimum', 'min_stock_default' => 3]))->assertRedirect();

    expect(storedOverrides())->toBe([])
        ->and(StockMinimum::for($fx['free'], $fx['sizes']['38']->id))->toBe(3);
});

it('E-66 keeps the overrides when an edit stays in stock_with_minimum', function () {
    $fx = minimumFixture();
    seedOverrides([['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4]]);

    $this->actingAs(minimumEditor())->putJson("/products/{$fx['product']->id}", [
        'name' => $fx['product']->name,
        'product_category_id' => $fx['product']->product_category_id,
        'business_line' => 'uniforms',
        'supply_mode' => 'stock_with_minimum',
        'min_stock_default' => 5,
    ])->assertRedirect();

    expect(storedOverrides())->toHaveCount(1)
        ->and(minimumAudit(AuditAction::ProductUpdated)->sole()->old_values)->toEqual(['min_stock_default' => 2]);
});

// --- Removing a size (E-71, DEC-PRD-52) ------------------------------------------------------

/**
 * The structure payload of the fixture with the given size names admitted.
 *
 * @param  list<string>  $sizeNames
 * @return list<array{attribute_id: int, role: string, allowed_value_ids: list<int>}>
 */
function minimumStructure(array $fx, array $sizeNames): array
{
    $model = $fx['product']->productAttributes()->where('role', AttributeRole::Axis->value)->firstOrFail();

    return [
        ['attribute_id' => $model->catalog_attribute_id, 'role' => 'axis', 'allowed_value_ids' => $model->allowedValues->modelKeys()],
        ['attribute_id' => $fx['size']->id, 'role' => 'order', 'allowed_value_ids' => array_map(fn (string $name): int => $fx['sizes'][$name]->id, $sizeNames)],
    ];
}

it('E-71 removes the own minimum of a size taken out of the allowed values and audits the previous value in products.attributes_updated', function () {
    $fx = minimumFixture();
    seedOverrides([
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4],
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['42']->id, 'minimum' => 7],
    ]);

    $this->actingAs(minimumEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", ['attributes' => minimumStructure($fx, ['40', '42'])])
        ->assertRedirect();

    $audit = minimumAudit(AuditAction::ProductAttributesUpdated)->sole();

    expect(storedOverrides())->toBe([['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['42']->id, 'minimum' => 7]])
        ->and($audit->action)->toBe('products.attributes_updated')
        ->and($audit->old_values['stock_minimum_overrides'])->toEqual([['code' => 'PAN-1', 'size' => '38', 'minimum' => 4]])
        ->and($audit->new_values)->not->toHaveKey('stock_minimum_overrides')
        ->and(minimumAudit(AuditAction::ProductUpdated))->toHaveCount(0);
});

it('E-71 rejects first, and keeps the override, when a combination restriction still uses the size', function () {
    $fx = minimumFixture();
    seedOverrides([['combination_id' => $fx['restricted']->id, 'size_value_id' => $fx['sizes']['40']->id, 'minimum' => 4]]);

    $this->actingAs(minimumEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", ['attributes' => minimumStructure($fx, ['38', '42'])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attributes']);

    expect(storedOverrides())->toHaveCount(1)
        ->and(minimumAudit(AuditAction::ProductAttributesUpdated))->toHaveCount(0);
});

it('N-6 removes every size-keyed minimum when the whole size attribute leaves the product', function () {
    $fx = minimumFixture();
    // PAN-2 is restricted to 40, which would block the removal (E-57): use only the free combination.
    $fx['restricted']->values()->detach($fx['sizes']['40']->id);
    seedOverrides([
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4],
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['42']->id, 'minimum' => 7],
    ]);

    $this->actingAs(minimumEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", ['attributes' => [minimumStructure($fx, ['38'])[0]]])
        ->assertRedirect();

    expect(storedOverrides())->toBe([])
        ->and(minimumAudit(AuditAction::ProductAttributesUpdated)->sole()->old_values['stock_minimum_overrides'])->toHaveCount(2);
});

it('E-71 leaves the overrides alone when a structure edit removes no size', function () {
    $fx = minimumFixture();
    seedOverrides([['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4]]);

    $this->actingAs(minimumEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", ['attributes' => array_reverse(minimumStructure($fx, ['38', '40', '42']))])
        ->assertRedirect();

    expect(storedOverrides())->toHaveCount(1)
        ->and(minimumAudit(AuditAction::ProductAttributesUpdated)->sole()->old_values)->not->toHaveKey('stock_minimum_overrides');
});
