<?php

use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\DetailLocation;
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
