<?php

use App\Models\AttributeValue;
use App\Models\Combination;
use App\Models\ComboComponent;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Raw inserts, so the database guarantees of design Decision 4 are tested without the models. Setup
 * rows are inserted outside every `toThrow`, so a missing table fails the test instead of passing it.
 *
 * @param  array<string, mixed>  $overrides
 */
function pricingSchemeRow(array $overrides = []): int
{
    return DB::table('price_schemes')->insertGetId(array_merge([
        'name' => 'Uniformes',
        'type' => 'tiered',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pricingTierRow(int $schemeId, array $overrides = []): int
{
    return DB::table('price_tiers')->insertGetId(array_merge([
        'price_scheme_id' => $schemeId,
        'label' => 'Detal',
        'min_quantity' => 1,
        'max_quantity' => 6,
        'kind' => 'priced',
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pricingPriceRow(int $combinationId, int $tierId, array $overrides = []): int
{
    return DB::table('combination_prices')->insertGetId(array_merge([
        'combination_id' => $combinationId,
        'price_tier_id' => $tierId,
        'size_group_id' => null,
        'unit_price' => '40.00',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function pricingGroupRow(int $combinationId, int $sortOrder = 1): int
{
    return DB::table('size_groups')->insertGetId([
        'combination_id' => $combinationId,
        'sort_order' => $sortOrder,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pricingItemPriceRow(int $componentId, array $overrides = []): int
{
    return DB::table('combo_item_prices')->insertGetId(array_merge([
        'combo_component_id' => $componentId,
        'unit_price' => '10.00',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('DEC-COT-02 keeps the scheme name unique ignoring case but not accents', function () {
    pricingSchemeRow(['name' => 'Uniformes']);

    expect(fn () => pricingSchemeRow(['name' => 'UNIFORMES']))->toThrow(UniqueConstraintViolationException::class);

    // Accent-sensitive collation (utf8mb4_0900_as_ci): a different accent is a different name.
    expect(pricingSchemeRow(['name' => 'Uniformés']))->toBeInt();
});

it('Decision 4 keeps the tier label unique within a scheme, ignoring case', function () {
    $scheme = pricingSchemeRow();
    $other = pricingSchemeRow(['name' => 'Pañales']);
    pricingTierRow($scheme, ['label' => 'Detal', 'sort_order' => 1]);

    expect(fn () => pricingTierRow($scheme, ['label' => 'DETAL', 'sort_order' => 2]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(pricingTierRow($other, ['label' => 'Detal']))->toBeInt();
});

it('Decision 4 keeps the sort order unique within a scheme', function () {
    $scheme = pricingSchemeRow();
    $other = pricingSchemeRow(['name' => 'Pañales']);
    pricingTierRow($scheme, ['label' => 'Detal', 'sort_order' => 1]);

    expect(fn () => pricingTierRow($scheme, ['label' => 'Especial', 'sort_order' => 1]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(pricingTierRow($other, ['label' => 'Especial', 'sort_order' => 1]))->toBeInt();
});

it('Decision 4 requires max_quantity to be at least min_quantity or null', function () {
    $scheme = pricingSchemeRow();

    expect(fn () => pricingTierRow($scheme, ['label' => 'Roto', 'min_quantity' => 5, 'max_quantity' => 3, 'sort_order' => 1]))
        ->toThrow(QueryException::class);

    expect(pricingTierRow($scheme, ['label' => 'Uno', 'min_quantity' => 3, 'max_quantity' => 3, 'sort_order' => 1]))->toBeInt()
        ->and(pricingTierRow($scheme, ['label' => 'Abierto', 'min_quantity' => 4, 'max_quantity' => null, 'sort_order' => 2]))->toBeInt();
});

it('Decision 4 allows one price per combination, tier and size group, including two ungrouped ones', function () {
    $tier = pricingTierRow(pricingSchemeRow());
    $otherTier = pricingTierRow(DB::table('price_tiers')->where('id', $tier)->value('price_scheme_id'), ['label' => 'Especial', 'min_quantity' => 7, 'max_quantity' => null, 'sort_order' => 2]);
    $combination = Combination::factory()->create();
    $group = pricingGroupRow($combination->id);
    $otherGroup = pricingGroupRow($combination->id, 2);

    pricingPriceRow($combination->id, $tier);

    // Two NULL size groups must collide: the stored key maps NULL to 0.
    expect(fn () => pricingPriceRow($combination->id, $tier))->toThrow(UniqueConstraintViolationException::class);

    // A group, another group and another tier are different cells.
    expect(pricingPriceRow($combination->id, $tier, ['size_group_id' => $group]))->toBeInt()
        ->and(pricingPriceRow($combination->id, $tier, ['size_group_id' => $otherGroup]))->toBeInt()
        ->and(pricingPriceRow($combination->id, $otherTier))->toBeInt();

    expect(fn () => pricingPriceRow($combination->id, $tier, ['size_group_id' => $group]))->toThrow(UniqueConstraintViolationException::class);
});

it('Decision 4 requires unit_price > 0 on combination prices and combo item prices', function () {
    $tier = pricingTierRow(pricingSchemeRow());
    $combination = Combination::factory()->create();
    $component = ComboComponent::factory()->create();

    foreach (['0.00', '-1.00'] as $invalid) {
        expect(fn () => pricingPriceRow($combination->id, $tier, ['unit_price' => $invalid]))->toThrow(QueryException::class)
            ->and(fn () => pricingItemPriceRow($component->id, ['unit_price' => $invalid]))->toThrow(QueryException::class);
    }

    expect(pricingPriceRow($combination->id, $tier, ['unit_price' => '0.01']))->toBeInt()
        ->and(pricingItemPriceRow($component->id, ['unit_price' => '0.01']))->toBeInt();
});

it('Decision 4 stores a price with exactly two decimals', function () {
    $tier = pricingTierRow(pricingSchemeRow());
    $combination = Combination::factory()->create();

    $id = pricingPriceRow($combination->id, $tier, ['unit_price' => '41']);

    expect(DB::table('combination_prices')->where('id', $id)->value('unit_price'))->toBe('41.00');
});

it('COT-004 puts a size in at most one group of a combination', function () {
    $combination = Combination::factory()->create();
    $size = AttributeValue::factory()->create();
    $otherSize = AttributeValue::factory()->create();
    $group = pricingGroupRow($combination->id);
    $otherGroup = pricingGroupRow($combination->id, 2);

    DB::table('size_group_values')->insert(['size_group_id' => $group, 'combination_id' => $combination->id, 'size_value_id' => $size->id]);

    // Same size in a second group of the same combination: the denormalized unique key rejects it.
    expect(fn () => DB::table('size_group_values')->insert(['size_group_id' => $otherGroup, 'combination_id' => $combination->id, 'size_value_id' => $size->id]))
        ->toThrow(UniqueConstraintViolationException::class);

    // The same size twice in one group is the primary key.
    expect(fn () => DB::table('size_group_values')->insert(['size_group_id' => $group, 'combination_id' => $combination->id, 'size_value_id' => $size->id]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('size_group_values')->insert(['size_group_id' => $otherGroup, 'combination_id' => $combination->id, 'size_value_id' => $otherSize->id]))->toBeTrue();
});

it('DEC-COT-06 allows one price per combo component', function () {
    $component = ComboComponent::factory()->create();
    $other = ComboComponent::factory()->create();

    pricingItemPriceRow($component->id);

    expect(fn () => pricingItemPriceRow($component->id, ['unit_price' => '12.00']))->toThrow(UniqueConstraintViolationException::class)
        ->and(pricingItemPriceRow($other->id))->toBeInt();
});

it('COT-006 seeds the commercial parameters row with 50.00 and 7 days', function () {
    $row = DB::table('commercial_parameters')->first();

    expect(DB::table('commercial_parameters')->count())->toBe(1)
        ->and($row->id)->toBe(1)
        ->and($row->advance_percentage)->toBe('50.00')
        ->and($row->validity_days)->toBe(7);
});

it('COT-006 bounds the advance percentage to 0-100 and the validity to at least one day', function () {
    foreach (['101.00', '-0.01'] as $invalid) {
        expect(fn () => DB::table('commercial_parameters')->where('id', 1)->update(['advance_percentage' => $invalid]))->toThrow(QueryException::class);
    }

    expect(fn () => DB::table('commercial_parameters')->where('id', 1)->update(['validity_days' => 0]))->toThrow(QueryException::class);

    expect(DB::table('commercial_parameters')->where('id', 1)->update(['advance_percentage' => '0.00']))->toBe(1)
        ->and(DB::table('commercial_parameters')->where('id', 1)->update(['advance_percentage' => '100.00']))->toBe(1)
        ->and(DB::table('commercial_parameters')->where('id', 1)->update(['validity_days' => 1]))->toBe(1);
});

it('Decision 4 restricts deleting a scheme assigned to a product or holding tiers', function () {
    $scheme = pricingSchemeRow();
    $product = Product::factory()->create();
    DB::table('products')->where('id', $product->id)->update(['price_scheme_id' => $scheme]);

    expect(DB::table('products')->where('id', $product->id)->value('price_scheme_id'))->toBe($scheme)
        ->and(fn () => DB::table('price_schemes')->where('id', $scheme)->delete())->toThrow(QueryException::class);

    DB::table('products')->where('id', $product->id)->update(['price_scheme_id' => null]);
    $withTiers = pricingSchemeRow(['name' => 'Con tramos']);
    pricingTierRow($withTiers);

    expect(fn () => DB::table('price_schemes')->where('id', $withTiers)->delete())->toThrow(QueryException::class)
        ->and(DB::table('price_schemes')->where('id', $scheme)->delete())->toBe(1);
});

it('Decision 4 deletes the price rows together with their combination, tier or component', function () {
    $tier = pricingTierRow(pricingSchemeRow());
    $combination = Combination::factory()->create();
    $group = pricingGroupRow($combination->id);
    $size = AttributeValue::factory()->create();
    DB::table('size_group_values')->insert(['size_group_id' => $group, 'combination_id' => $combination->id, 'size_value_id' => $size->id]);
    pricingPriceRow($combination->id, $tier, ['size_group_id' => $group]);
    $component = ComboComponent::factory()->create();
    pricingItemPriceRow($component->id);

    $combination->delete();

    expect(DB::table('combination_prices')->count())->toBe(0)
        ->and(DB::table('size_groups')->count())->toBe(0)
        ->and(DB::table('size_group_values')->count())->toBe(0);

    $component->delete();

    expect(DB::table('combo_item_prices')->count())->toBe(0);

    $otherCombination = Combination::factory()->create();
    pricingPriceRow($otherCombination->id, $tier);
    DB::table('price_tiers')->where('id', $tier)->delete();

    expect(DB::table('combination_prices')->count())->toBe(0);
});
