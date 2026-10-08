<?php

use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\DetailLocation;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raw inserts, so the database guarantees of design Decision 3 are tested without the models.
 *
 * @param  array<string, mixed>  $overrides
 */
function schemaCategoryRow(array $overrides = []): int
{
    return DB::table('product_categories')->insertGetId(array_merge([
        'name' => 'Camisas',
        'sort_order' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function schemaAttributeRow(array $overrides = []): int
{
    return DB::table('catalog_attributes')->insertGetId(array_merge([
        'name' => 'Manga',
        'presentation' => 'text',
        'sort_order' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function schemaValueRow(int $attributeId, array $overrides = []): int
{
    return DB::table('attribute_values')->insertGetId(array_merge([
        'catalog_attribute_id' => $attributeId,
        'name' => 'Corta',
        'sort_order' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function schemaLocationRow(array $overrides = []): int
{
    return DB::table('detail_locations')->insertGetId(array_merge([
        'name' => 'Pechera',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('E-45 (DB) rejects two attributes with the same special use', function () {
    schemaAttributeRow(['name' => 'Tela', 'special_use' => 'fabric']);

    expect(fn () => schemaAttributeRow(['name' => 'Material', 'special_use' => 'fabric']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('DEC-PRD-49 (DB) rejects a second attribute with the size or the gender use', function (string $use) {
    schemaAttributeRow(['name' => 'Primero', 'special_use' => $use]);

    expect(fn () => schemaAttributeRow(['name' => 'Segundo', 'special_use' => $use]))
        ->toThrow(UniqueConstraintViolationException::class);
})->with(['size', 'gender']);

it('E-45 (DB) allows many attributes without a special use and one attribute per use', function () {
    schemaAttributeRow(['name' => 'Manga']);
    schemaAttributeRow(['name' => 'Cuello']);
    schemaAttributeRow(['name' => 'Tela', 'special_use' => 'fabric']);
    schemaAttributeRow(['name' => 'Talla', 'special_use' => 'size']);
    $gender = schemaAttributeRow(['name' => 'Género', 'special_use' => 'gender']);

    expect(DB::table('catalog_attributes')->count())->toBe(5)
        ->and(DB::table('catalog_attributes')->where('id', $gender)->value('special_use'))->toBe('gender');
});

it('E-58 (DB) rejects two attributes with the color presentation', function () {
    schemaAttributeRow(['name' => 'Color', 'presentation' => 'color']);

    expect(fn () => schemaAttributeRow(['name' => 'Tono', 'presentation' => 'color']))
        ->toThrow(UniqueConstraintViolationException::class);

    // Other presentations are not constrained.
    schemaAttributeRow(['name' => 'Estampado', 'presentation' => 'image']);
    schemaAttributeRow(['name' => 'Cuello', 'presentation' => 'image']);

    expect(DB::table('catalog_attributes')->count())->toBe(3);
});

it('DEC-PRD-45 (DB) compares category names without regard to letter case', function () {
    schemaCategoryRow(['name' => 'Camisas']);

    expect(fn () => schemaCategoryRow(['name' => 'camisas']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('DEC-PRD-45 (DB) compares attribute names without regard to letter case', function () {
    schemaAttributeRow(['name' => 'Manga']);

    expect(fn () => schemaAttributeRow(['name' => 'MANGA']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('DEC-PRD-45 (DB) compares detail location names without regard to letter case', function () {
    schemaLocationRow(['name' => 'Pechera']);

    expect(fn () => schemaLocationRow(['name' => 'pechera']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('DEC-PRD-45 (DB) keeps accents significant in names', function () {
    schemaCategoryRow(['name' => 'Camisa']);

    expect(schemaCategoryRow(['name' => 'Camisá']))->toBeInt();
});

it('DEC-PRD-45 (DB) compares value names within an attribute only', function () {
    $sleeve = schemaAttributeRow(['name' => 'Manga']);
    $cuff = schemaAttributeRow(['name' => 'Puño']);
    schemaValueRow($sleeve, ['name' => 'Corta']);

    expect(fn () => schemaValueRow($sleeve, ['name' => 'corta']))
        ->toThrow(UniqueConstraintViolationException::class);

    // The same name under another attribute is a different value.
    expect(schemaValueRow($cuff, ['name' => 'Corta']))->toBeInt();
});

it('Decision 3 (DB) exposes the composite key (id, catalog_attribute_id) on attribute values', function () {
    $uniqueIndexes = collect(Schema::getIndexes('attribute_values'))
        ->filter(fn (array $index) => $index['unique'])
        ->map(fn (array $index) => $index['columns'])
        ->values()
        ->all();

    expect($uniqueIndexes)->toContain(['id', 'catalog_attribute_id']);
});

it('E-46 (DB) enforces the primary key of the offered colors of a fabric', function () {
    $fabric = schemaAttributeRow(['name' => 'Tela', 'special_use' => 'fabric']);
    $color = schemaAttributeRow(['name' => 'Color', 'presentation' => 'color']);
    $drill = schemaValueRow($fabric, ['name' => 'Drill']);
    $green = schemaValueRow($color, ['name' => 'Verde', 'tone' => '#7A9A3B']);

    DB::table('fabric_offered_colors')->insert(['fabric_value_id' => $drill, 'color_value_id' => $green]);

    expect(fn () => DB::table('fabric_offered_colors')->insert(['fabric_value_id' => $drill, 'color_value_id' => $green]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('Decision 3 (DB) restricts deleting an attribute that has values', function () {
    $attribute = schemaAttributeRow();
    schemaValueRow($attribute);

    expect(fn () => DB::table('catalog_attributes')->where('id', $attribute)->delete())
        ->toThrow(QueryException::class);
});

it('Decision 3 builds catalog models and factories with enum casts and relations', function () {
    $fabric = CatalogAttribute::factory()->fabric()->create();
    $color = CatalogAttribute::factory()->color()->create();
    $size = CatalogAttribute::factory()->size()->create();
    $gender = CatalogAttribute::factory()->gender()->inactive()->create();
    $drill = AttributeValue::factory()->for($fabric, 'catalogAttribute')->create();
    $green = AttributeValue::factory()->for($color, 'catalogAttribute')->withTone()->create();
    $drill->offeredColors()->attach($green);

    $category = ProductCategory::factory()->inactive()->create();
    $location = DetailLocation::factory()->create(['name' => 'Pechera']);

    expect($fabric->fresh()->special_use)->toBe(AttributeSpecialUse::Fabric)
        ->and($color->fresh()->presentation)->toBe(AttributePresentation::Color)
        ->and($color->fresh()->color_marker)->toBe(1)
        ->and($size->fresh()->color_marker)->toBeNull()
        ->and($gender->fresh()->status)->toBe(CatalogStatus::Inactive)
        ->and($drill->catalogAttribute->is($fabric))->toBeTrue()
        ->and($fabric->values)->toHaveCount(1)
        ->and($drill->offeredColors->pluck('id')->all())->toBe([$green->id])
        ->and($green->tone)->toBe('#7A9A3B')
        ->and(ProductCategory::query()->withStatus(CatalogStatus::Inactive)->pluck('id')->all())->toBe([$category->id])
        ->and(ProductCategory::query()->withStatus(CatalogStatus::Active)->count())->toBe(0)
        ->and(DetailLocation::query()->search('pechéra')->pluck('id')->all())->toBe([$location->id])
        ->and(DetailLocation::query()->search('pech')->pluck('id')->all())->toBe([$location->id])
        ->and(CatalogAttribute::query()->search('')->count())->toBe(4);
});

it('Decision 3 (DB) restricts deleting a value used as offered color or fabric', function () {
    $fabric = schemaAttributeRow(['name' => 'Tela', 'special_use' => 'fabric']);
    $color = schemaAttributeRow(['name' => 'Color', 'presentation' => 'color']);
    $drill = schemaValueRow($fabric, ['name' => 'Drill']);
    $green = schemaValueRow($color, ['name' => 'Verde', 'tone' => '#7A9A3B']);
    DB::table('fabric_offered_colors')->insert(['fabric_value_id' => $drill, 'color_value_id' => $green]);

    expect(fn () => DB::table('attribute_values')->where('id', $drill)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('attribute_values')->where('id', $green)->delete())->toThrow(QueryException::class);
});

/**
 * Raw product insert (products part of the schema, task 8.1). Defaults to a valid `on_demand` row.
 *
 * @param  array<string, mixed>  $overrides
 */
function schemaProductRow(int $categoryId, array $overrides = []): int
{
    return DB::table('products')->insertGetId(array_merge([
        'name' => 'Camisa corporativa',
        'product_category_id' => $categoryId,
        'business_line' => 'uniforms',
        'supply_mode' => 'on_demand',
        'min_stock_default' => null,
        'allows_custom_color' => 0,
        'portal_visible' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('DEC-PRD-46 (DB) accepts a default minimum only in stock_with_minimum and requires it there', function () {
    $category = schemaCategoryRow();

    // Accepted: stock_with_minimum with its default (E-20 accepts 6).
    $valid = schemaProductRow($category, ['name' => 'Pañal adulto', 'supply_mode' => 'stock_with_minimum', 'min_stock_default' => 6]);

    expect(DB::table('products')->where('id', $valid)->value('min_stock_default'))->toBe(6);

    // Rejected: a minimum in any other mode, and no minimum in stock_with_minimum.
    foreach (['on_demand', 'stock_depletable', 'service'] as $mode) {
        expect(fn () => schemaProductRow($category, ['name' => 'Con mínimo '.$mode, 'supply_mode' => $mode, 'min_stock_default' => 6]))
            ->toThrow(QueryException::class);
    }

    expect(fn () => schemaProductRow($category, ['name' => 'Sin mínimo', 'supply_mode' => 'stock_with_minimum', 'min_stock_default' => null]))
        ->toThrow(QueryException::class);
});

it('DEC-PRD-34 (DB) accepts custom color only in on_demand', function () {
    $category = schemaCategoryRow();

    $valid = schemaProductRow($category, ['name' => 'Camisa', 'supply_mode' => 'on_demand', 'allows_custom_color' => 1]);

    expect(DB::table('products')->where('id', $valid)->value('allows_custom_color'))->toBe(1);

    foreach (['stock_depletable', 'service'] as $mode) {
        expect(fn () => schemaProductRow($category, ['name' => 'Color '.$mode, 'supply_mode' => $mode, 'allows_custom_color' => 1]))
            ->toThrow(QueryException::class);
    }

    expect(fn () => schemaProductRow($category, ['name' => 'Color con mínimo', 'supply_mode' => 'stock_with_minimum', 'min_stock_default' => 6, 'allows_custom_color' => 1]))
        ->toThrow(QueryException::class);
});

it('DEC-PRD-45 (DB) compares product names without regard to letter case', function () {
    $category = schemaCategoryRow();
    schemaProductRow($category, ['name' => 'Camisa corporativa']);

    expect(fn () => schemaProductRow($category, ['name' => 'camisa corporativa']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('Decision 3 (DB) restricts deleting a category that has products', function () {
    $category = schemaCategoryRow();
    schemaProductRow($category);

    expect(fn () => DB::table('product_categories')->where('id', $category)->delete())
        ->toThrow(QueryException::class)
        ->and(DB::table('product_categories')->where('id', $category)->exists())->toBeTrue();
});

it('Decision 3 (DB) keeps one row per attribute in a product and cascades them with the product', function () {
    $product = schemaProductRow(schemaCategoryRow());
    $fabric = schemaAttributeRow(['name' => 'Tela', 'special_use' => 'fabric']);
    $size = schemaAttributeRow(['name' => 'Talla', 'special_use' => 'size']);
    $drill = schemaValueRow($fabric, ['name' => 'Drill']);
    $row = fn (int $attributeId, string $role): array => [
        'product_id' => $product,
        'catalog_attribute_id' => $attributeId,
        'role' => $role,
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $fabricRow = DB::table('product_attributes')->insertGetId($row($fabric, 'axis'));
    DB::table('product_attributes')->insert($row($size, 'order'));

    expect(fn () => DB::table('product_attributes')->insert($row($fabric, 'order')))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('product_attribute_values')->insert(['product_attribute_id' => $fabricRow, 'attribute_value_id' => $drill]);

    // The attribute value is protected by restrict; the product's own rows disappear with it.
    expect(fn () => DB::table('attribute_values')->where('id', $drill)->delete())->toThrow(QueryException::class);

    DB::table('products')->where('id', $product)->delete();

    expect(DB::table('product_attributes')->where('product_id', $product)->count())->toBe(0)
        ->and(DB::table('product_attribute_values')->where('product_attribute_id', $fabricRow)->count())->toBe(0);
});

it('Decision 3 (DB) cascades detail locations and customizations with the product and restricts the referenced rows', function () {
    $category = schemaCategoryRow();
    $product = schemaProductRow($category, ['name' => 'Camisa']);
    $service = schemaProductRow($category, ['name' => 'Bordado pequeño', 'supply_mode' => 'service']);
    $location = schemaLocationRow();
    DB::table('product_detail_locations')->insert(['product_id' => $product, 'detail_location_id' => $location]);
    DB::table('product_customizations')->insert(['product_id' => $product, 'service_product_id' => $service]);

    expect(fn () => DB::table('detail_locations')->where('id', $location)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('products')->where('id', $service)->delete())->toThrow(QueryException::class);

    DB::table('products')->where('id', $product)->delete();

    expect(DB::table('product_detail_locations')->count())->toBe(0)
        ->and(DB::table('product_customizations')->count())->toBe(0)
        ->and(DB::table('products')->where('id', $service)->exists())->toBeTrue();
});

it('Decision 3 builds product models and factories with their states and enum casts', function () {
    $category = ProductCategory::factory()->create();
    $onDemand = Product::factory()->for($category, 'category')->onDemand()->create();
    $minimum = Product::factory()->for($category, 'category')->stockWithMinimum()->create();
    $depletable = Product::factory()->for($category, 'category')->stockDepletable()->diapers()->create();
    $service = Product::factory()->for($category, 'category')->service()->inactive()->create();

    expect($onDemand->fresh()->supply_mode)->toBe(SupplyMode::OnDemand)
        ->and($onDemand->fresh()->allows_custom_color)->toBeTrue()
        ->and($onDemand->business_line)->toBe(BusinessLine::Uniforms)
        ->and($minimum->fresh()->min_stock_default)->toBe(6)
        ->and($minimum->fresh()->allows_custom_color)->toBeFalse()
        ->and($depletable->fresh()->business_line)->toBe(BusinessLine::Diapers)
        ->and($service->fresh()->status)->toBe(CatalogStatus::Inactive)
        ->and($service->category->is($category))->toBeTrue()
        ->and(Product::query()->withStatus(CatalogStatus::Active)->count())->toBe(3)
        ->and(Product::query()->search('')->count())->toBe(4)
        ->and(Product::query()->search($onDemand->name)->pluck('id')->all())->toBe([$onDemand->id]);
});

it('DEC-PRD-34 computes the effective custom color from mode, flag and the declared color attribute', function () {
    $category = ProductCategory::factory()->create();
    $color = CatalogAttribute::factory()->color()->create();
    $product = Product::factory()->for($category, 'category')->onDemand()->create();

    // No color attribute declared: the stored flag alone is not enough.
    expect($product->admitsCustomColor())->toBeFalse();

    ProductAttribute::factory()->for($product)->for($color, 'catalogAttribute')->create(['role' => 'order']);

    expect($product->fresh()->admitsCustomColor())->toBeTrue()
        ->and(Product::factory()->for($category, 'category')->onDemand()->create(['allows_custom_color' => false])->admitsCustomColor())->toBeFalse();
});
