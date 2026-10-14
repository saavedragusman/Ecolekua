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
use Illuminate\Testing\TestResponse;

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
 * @param  list<array<string, mixed>>  $rows
 */
function putMinimums(mixed $test, User $actor, Product $product, array $rows): TestResponse
{
    return $test->actingAs($actor)->putJson("/products/{$product->id}/stock-minimums", ['overrides' => $rows]);
}

/**
 * @return Collection<int, AuditLog>
 */
function minimumAudit(AuditAction $action = AuditAction::ProductStockMinimumsUpdated): Collection
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

// --- Saving overrides (PRD-009) --------------------------------------------------------------

it('E-65 gives the article with an own value that value and every other size the default', function () {
    $fx = minimumFixture();

    putMinimums($this, minimumEditor(), $fx['product'], [
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4],
    ])->assertRedirect();

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

it('E-20 accepts an own value of 6 in stock_with_minimum and rejects any value in the other modes', function (string $state) {
    $fx = minimumFixture();
    $other = Product::factory()->for(ProductCategory::factory(), 'category')->{$state}()->create();
    $combination = Combination::factory()->for($other)->create();
    $row = ['combination_id' => $combination->id, 'size_value_id' => null, 'minimum' => 6];

    putMinimums($this, minimumEditor(), $other, [$row])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['overrides']);

    expect(storedOverrides())->toBe([]);

    putMinimums($this, minimumEditor(), $fx['product'], [['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 6]])
        ->assertRedirect();

    expect(storedOverrides())->toHaveCount(1);
})->with(['onDemand', 'stockDepletable', 'service']);

it('PRD-009 replaces the stored set and audits the previous and new rows as code, size and minimum', function () {
    $fx = minimumFixture();
    $editor = minimumEditor();
    $sizes = $fx['sizes'];

    putMinimums($this, $editor, $fx['product'], [
        ['combination_id' => $fx['free']->id, 'size_value_id' => $sizes['38']->id, 'minimum' => 4],
        ['combination_id' => $fx['restricted']->id, 'size_value_id' => $sizes['40']->id, 'minimum' => 0],
    ])->assertRedirect();

    putMinimums($this, $editor, $fx['product'], [
        ['combination_id' => $fx['free']->id, 'size_value_id' => $sizes['42']->id, 'minimum' => 9],
    ])->assertRedirect();

    expect(storedOverrides())->toBe([['combination_id' => $fx['free']->id, 'size_value_id' => $sizes['42']->id, 'minimum' => 9]]);

    $audit = minimumAudit();

    expect($audit)->toHaveCount(2)
        ->and($audit[0]->action)->toBe('products.stock_minimums_updated')
        ->and(minimumAudit(AuditAction::ProductUpdated))->toHaveCount(0)
        ->and($audit[0]->old_values)->toEqual(['stock_minimum_overrides' => []])
        ->and($audit[0]->new_values)->toEqual(['stock_minimum_overrides' => [
            ['code' => 'PAN-1', 'size' => '38', 'minimum' => 4],
            ['code' => 'PAN-2', 'size' => '40', 'minimum' => 0],
        ]])
        ->and($audit[1]->old_values['stock_minimum_overrides'])->toHaveCount(2)
        ->and($audit[1]->new_values)->toEqual(['stock_minimum_overrides' => [['code' => 'PAN-1', 'size' => '42', 'minimum' => 9]]]);
});

it('PRD-009 an empty list clears the overrides and the same set again writes no audit', function () {
    $fx = minimumFixture();
    $rows = [['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4]];

    putMinimums($this, minimumEditor(), $fx['product'], $rows)->assertRedirect();
    putMinimums($this, minimumEditor(), $fx['product'], $rows)->assertRedirect();

    expect(minimumAudit())->toHaveCount(1);

    putMinimums($this, minimumEditor(), $fx['product'], [])->assertRedirect();

    expect(storedOverrides())->toBe([])
        ->and(minimumAudit())->toHaveCount(2);
});

it('PRD-009 requires the size when the product declares it as an order attribute and limits it to the allowed values and the restriction', function (string $case) {
    $fx = minimumFixture();
    $unlisted = AttributeValue::factory()->for($fx['size'], 'catalogAttribute')->create(['name' => '44']);
    $row = match ($case) {
        'size missing' => ['combination_id' => $fx['free']->id, 'size_value_id' => null, 'minimum' => 3],
        'size not allowed by the product' => ['combination_id' => $fx['free']->id, 'size_value_id' => $unlisted->id, 'minimum' => 3],
        'size outside the combination restriction' => ['combination_id' => $fx['restricted']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 3],
    };

    putMinimums($this, minimumEditor(), $fx['product'], [$row])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['overrides.0.size_value_id']);

    expect(storedOverrides())->toBe([])
        ->and(minimumAudit())->toHaveCount(0);
})->with(['size missing', 'size not allowed by the product', 'size outside the combination restriction']);

it('PRD-009 rejects a size when the product does not declare the size attribute as an order attribute', function () {
    $size = CatalogAttribute::factory()->size()->create();
    $value = AttributeValue::factory()->for($size, 'catalogAttribute')->create();
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->stockWithMinimum(2)->create();
    $combination = Combination::factory()->for($product)->create();

    putMinimums($this, minimumEditor(), $product, [['combination_id' => $combination->id, 'size_value_id' => $value->id, 'minimum' => 3]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['overrides.0.size_value_id']);

    putMinimums($this, minimumEditor(), $product, [['combination_id' => $combination->id, 'size_value_id' => null, 'minimum' => 3]])->assertRedirect();

    expect(storedOverrides())->toBe([['combination_id' => $combination->id, 'size_value_id' => null, 'minimum' => 3]]);
});

it('PRD-009 rejects a combination of another product, a repeated article and an invalid minimum', function (string $case) {
    $fx = minimumFixture();
    $foreign = Combination::factory()->for(Product::factory()->stockWithMinimum(2))->create();
    $size = $fx['sizes']['38']->id;
    $rows = match ($case) {
        'foreign' => [['combination_id' => $foreign->id, 'size_value_id' => null, 'minimum' => 3]],
        'repeated' => [
            ['combination_id' => $fx['free']->id, 'size_value_id' => $size, 'minimum' => 3],
            ['combination_id' => $fx['free']->id, 'size_value_id' => $size, 'minimum' => 5],
        ],
        'negative' => [['combination_id' => $fx['free']->id, 'size_value_id' => $size, 'minimum' => -1]],
        'too large' => [['combination_id' => $fx['free']->id, 'size_value_id' => $size, 'minimum' => 10000]],
        'not a number' => [['combination_id' => $fx['free']->id, 'size_value_id' => $size, 'minimum' => 'many']],
        'missing minimum' => [['combination_id' => $fx['free']->id, 'size_value_id' => $size]],
    };

    putMinimums($this, minimumEditor(), $fx['product'], $rows)->assertUnprocessable();

    expect(storedOverrides())->toBe([]);
})->with(['foreign', 'repeated', 'negative', 'too large', 'not a number', 'missing minimum']);

it('PRD-009 requires the overrides list to be present', function () {
    $fx = minimumFixture();

    $this->actingAs(minimumEditor())->putJson("/products/{$fx['product']->id}/stock-minimums", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['overrides']);
});

it('PRD-016 requires products.update for the stock minimums and audits the denial', function () {
    $fx = minimumFixture();
    $viewer = userWithPermissions(PermissionName::ProductsView);

    putMinimums($this, $viewer, $fx['product'], [['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4]])
        ->assertForbidden();

    expect(storedOverrides())->toBe([])
        ->and(minimumAudit(AuditAction::AuthorizationDenied)->sole()->context['route'])->toBe('products.stock-minimums.update');
});

it('PRD-009 answers 404 for an unknown product', function () {
    $this->actingAs(minimumEditor())->putJson('/products/999999/stock-minimums', ['overrides' => []])->assertNotFound();
});

// --- Mode change (E-66) ----------------------------------------------------------------------

it('E-66 deletes every own minimum when the mode changes and audits the deleted rows, then requires a new default on return', function () {
    $fx = minimumFixture();
    $editor = minimumEditor();
    putMinimums($this, $editor, $fx['product'], [
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4],
        ['combination_id' => $fx['restricted']->id, 'size_value_id' => $fx['sizes']['40']->id, 'minimum' => 1],
    ])->assertRedirect();

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
        ->and(minimumAudit(AuditAction::ProductAttributesUpdated))->toHaveCount(0);

    $this->actingAs($editor)->putJson("/products/{$fx['product']->id}", $payload(['supply_mode' => 'stock_with_minimum']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock_default']);

    $this->actingAs($editor)->putJson("/products/{$fx['product']->id}", $payload(['supply_mode' => 'stock_with_minimum', 'min_stock_default' => 3]))->assertRedirect();

    expect(storedOverrides())->toBe([])
        ->and(StockMinimum::for($fx['free'], $fx['sizes']['38']->id))->toBe(3);
});

it('E-66 keeps the overrides when an edit stays in stock_with_minimum', function () {
    $fx = minimumFixture();
    putMinimums($this, minimumEditor(), $fx['product'], [['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4]])->assertRedirect();

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

it('E-71 removes the own minimum of a size taken out of the allowed values and audits the previous value', function () {
    $fx = minimumFixture();
    putMinimums($this, minimumEditor(), $fx['product'], [
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4],
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['42']->id, 'minimum' => 7],
    ])->assertRedirect();

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
    putMinimums($this, minimumEditor(), $fx['product'], [['combination_id' => $fx['restricted']->id, 'size_value_id' => $fx['sizes']['40']->id, 'minimum' => 4]])->assertRedirect();

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
    putMinimums($this, minimumEditor(), $fx['product'], [
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4],
        ['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['42']->id, 'minimum' => 7],
    ])->assertRedirect();

    $this->actingAs(minimumEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", ['attributes' => [minimumStructure($fx, ['38'])[0]]])
        ->assertRedirect();

    expect(storedOverrides())->toBe([])
        ->and(minimumAudit(AuditAction::ProductAttributesUpdated)->sole()->old_values['stock_minimum_overrides'])->toHaveCount(2);
});

it('E-71 leaves the overrides alone when a structure edit removes no size', function () {
    $fx = minimumFixture();
    putMinimums($this, minimumEditor(), $fx['product'], [['combination_id' => $fx['free']->id, 'size_value_id' => $fx['sizes']['38']->id, 'minimum' => 4]])->assertRedirect();

    $this->actingAs(minimumEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", ['attributes' => array_reverse(minimumStructure($fx, ['38', '40', '42']))])
        ->assertRedirect();

    expect(storedOverrides())->toHaveCount(1)
        ->and(minimumAudit(AuditAction::ProductAttributesUpdated)->sole()->old_values)->not->toHaveKey('stock_minimum_overrides');
});
