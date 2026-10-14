<?php

use App\Enums\CatalogStatus;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Models\AttributeValue;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\ComboItemPrice;
use App\Models\CommercialParameters;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\SizeGroup;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;

it('DT-01 reads every price and parameter amount as a BigDecimal at the column scale', function () {
    $price = CombinationPrice::factory()->create(['unit_price' => '41'])->fresh();
    $itemPrice = ComboItemPrice::factory()->create(['unit_price' => '9.5'])->fresh();
    $parameters = CommercialParameters::query()->findOrFail(1);

    expect($price->unit_price)->toBeInstanceOf(BigDecimal::class)
        ->and($price->unit_price->toString())->toBe('41.00')
        ->and($itemPrice->unit_price->toString())->toBe('9.50')
        ->and($parameters->advance_percentage)->toBeInstanceOf(BigDecimal::class)
        ->and($parameters->advance_percentage->toString())->toBe('50.00')
        ->and($parameters->validity_days)->toBe(7);
});

it('DT-01 refuses to store a price with more decimals than the column instead of rounding it', function () {
    expect(fn () => CombinationPrice::factory()->create(['unit_price' => '40.005']))->toThrow(RoundingNecessaryException::class)
        ->and(fn () => ComboItemPrice::factory()->create(['unit_price' => '1.234']))->toThrow(RoundingNecessaryException::class)
        ->and(CombinationPrice::query()->count())->toBe(0)
        ->and(ComboItemPrice::query()->count())->toBe(0);
});

it('Decision 2 casts the scheme type, tier kind and scheme status to their enums', function () {
    $scheme = PriceScheme::factory()->create()->fresh();
    $tier = PriceTier::factory()->create(['price_scheme_id' => $scheme->id])->fresh();

    expect($scheme->type)->toBe(PriceSchemeType::Tiered)
        ->and($scheme->status)->toBe(CatalogStatus::Active)
        ->and($tier->kind)->toBe(PriceTierKind::Priced)
        ->and($tier->min_quantity)->toBe(1)
        ->and($tier->max_quantity)->toBeNull();
});

it('COT-001 builds a fixed scheme with one implicit priced tier', function () {
    $scheme = PriceScheme::factory()->fixed()->create();

    expect($scheme->type)->toBe(PriceSchemeType::Fixed)
        ->and($scheme->tiers)->toHaveCount(1)
        ->and($scheme->tiers->first()->label)->toBe('Precio')
        ->and($scheme->tiers->first()->kind)->toBe(PriceTierKind::Priced)
        ->and($scheme->tiers->first()->min_quantity)->toBe(1)
        ->and($scheme->tiers->first()->max_quantity)->toBeNull();
});

it('COT-001 builds a tiered scheme whose last tier is the open negotiation tier', function () {
    $scheme = PriceScheme::factory()->negotiationLast()->create();
    $tiers = $scheme->tiers;

    expect($tiers)->toHaveCount(4)
        ->and($tiers->pluck('sort_order')->all())->toBe([1, 2, 3, 4])
        ->and($tiers->map(fn (PriceTier $tier): array => [$tier->min_quantity, $tier->max_quantity])->all())
        ->toBe([[1, 6], [7, 12], [13, 49], [50, null]])
        ->and($tiers->pluck('kind')->all())->toBe([PriceTierKind::Priced, PriceTierKind::Priced, PriceTierKind::Priced, PriceTierKind::Negotiation]);
});

it('COT-002 relates a product to its scheme and a scheme to its products', function () {
    $scheme = PriceScheme::factory()->create();
    $product = Product::factory()->create();
    $product->price_scheme_id = $scheme->id;
    $product->save();

    expect($product->fresh()->priceScheme->is($scheme))->toBeTrue()
        ->and($scheme->products->pluck('id')->all())->toBe([$product->id])
        ->and(Product::factory()->create()->priceScheme)->toBeNull();
});

it('COT-003 relates a combination to its prices, tiers and size groups', function () {
    $combination = Combination::factory()->create();
    $tier = PriceTier::factory()->create();
    $group = SizeGroup::factory()->create(['combination_id' => $combination->id, 'sort_order' => 1]);
    $price = CombinationPrice::factory()->create([
        'combination_id' => $combination->id,
        'price_tier_id' => $tier->id,
        'size_group_id' => $group->id,
    ]);

    expect($combination->prices->pluck('id')->all())->toBe([$price->id])
        ->and($combination->sizeGroups->pluck('id')->all())->toBe([$group->id])
        ->and($price->combination->is($combination))->toBeTrue()
        ->and($price->tier->is($tier))->toBeTrue()
        ->and($price->sizeGroup->is($group))->toBeTrue()
        ->and($tier->prices->pluck('id')->all())->toBe([$price->id])
        ->and($group->prices->pluck('id')->all())->toBe([$price->id]);
});

it('COT-004 lists the sizes of a group through the registry that keeps one group per size', function () {
    $combination = Combination::factory()->create();
    $group = SizeGroup::factory()->create(['combination_id' => $combination->id]);
    $sizes = AttributeValue::factory()->count(2)->create();

    foreach ($sizes as $size) {
        $group->sizes()->attach($size->id, ['combination_id' => $combination->id]);
    }

    expect($group->sizes->pluck('id')->sort()->values()->all())->toBe($sizes->pluck('id')->sort()->values()->all());
});

it('COT-005 relates a combo component to its price and a combo to the prices of its components', function () {
    $combo = Combo::factory()->create();
    $priced = ComboComponent::factory()->create(['combo_id' => $combo->id]);
    ComboComponent::factory()->create(['combo_id' => $combo->id]);
    $price = ComboItemPrice::factory()->create(['combo_component_id' => $priced->id]);

    expect($priced->price->is($price))->toBeTrue()
        ->and($price->component->is($priced))->toBeTrue()
        ->and($combo->itemPrices->pluck('id')->all())->toBe([$price->id])
        ->and($combo->components)->toHaveCount(2);
});

it('COT-006 describes the single commercial parameters row with the seeded defaults', function () {
    $made = CommercialParameters::factory()->make();

    expect($made->getKey())->toBe(1)
        ->and($made->advance_percentage->toString())->toBe('50.00')
        ->and($made->validity_days)->toBe(7)
        ->and(CommercialParameters::query()->count())->toBe(1);
});
