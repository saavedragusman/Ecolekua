<?php

use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\Combination;
use App\Models\Product;
use App\Models\StockMinimumOverride;
use App\Support\Products\StockMinimum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('E-65 returns the own value of the article and the product default for the others', function () {
    $size = CatalogAttribute::factory()->size()->create();
    [$s38, $s40] = [
        AttributeValue::factory()->for($size, 'catalogAttribute')->create(['name' => '38']),
        AttributeValue::factory()->for($size, 'catalogAttribute')->create(['name' => '40']),
    ];
    $combination = Combination::factory()->for(Product::factory()->stockWithMinimum(2))->create();
    StockMinimumOverride::query()->create(['combination_id' => $combination->id, 'size_value_id' => $s38->id, 'minimum' => 4]);

    expect(StockMinimum::for($combination, $s38->id))->toBe(4)
        ->and(StockMinimum::for($combination, $s40->id))->toBe(2)
        ->and(StockMinimum::for($combination, null))->toBe(2);
});

it('E-65 treats an override without size as the value of the whole combination', function () {
    $combination = Combination::factory()->for(Product::factory()->stockWithMinimum(2))->create();
    StockMinimumOverride::query()->create(['combination_id' => $combination->id, 'size_value_id' => null, 'minimum' => 0]);

    expect(StockMinimum::for($combination, null))->toBe(0);
});

it('E-20 returns no minimum for a product outside stock_with_minimum', function () {
    $combination = Combination::factory()->for(Product::factory()->onDemand())->create();

    expect(StockMinimum::for($combination, null))->toBeNull();
});
