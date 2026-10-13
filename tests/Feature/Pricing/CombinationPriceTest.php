<?php

use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SizeGroup;
use App\Models\User;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Testing\TestResponse;

// COT-003 (E-05, E-06, E-07, DEC-COT-32): the price table of one product, saved in one request.
// The price page ships later, so the write endpoint answers a redirect back (003 precedent).

function priceTableManager(): User
{
    return userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);
}

/**
 * A stored tiered scheme: Detal 1-6, Intermedio 7-12 and a Negociación tier without registered price.
 */
function priceTableScheme(string $name = 'Uniformes'): PriceScheme
{
    $scheme = PriceScheme::factory()->create(['name' => $name]);
    $scheme->tiers()->create(['label' => 'Detal', 'min_quantity' => 1, 'max_quantity' => 6, 'kind' => PriceTierKind::Priced, 'sort_order' => 1]);
    $scheme->tiers()->create(['label' => 'Intermedio', 'min_quantity' => 7, 'max_quantity' => 12, 'kind' => PriceTierKind::Priced, 'sort_order' => 2]);
    $scheme->tiers()->create(['label' => 'Mayor', 'min_quantity' => 13, 'max_quantity' => 24, 'kind' => PriceTierKind::Priced, 'sort_order' => 3]);
    $scheme->tiers()->create(['label' => 'Negociación', 'min_quantity' => 25, 'max_quantity' => null, 'kind' => PriceTierKind::Negotiation, 'sort_order' => 4]);

    return $scheme->fresh();
}

function priceTableProduct(?PriceScheme $scheme): Product
{
    /** @var ProductFactory $factory */
    $factory = Product::factory();

    return $factory->for(ProductCategory::factory(), 'category')->create(['price_scheme_id' => $scheme?->id]);
}

function priceTableCombination(Product $product, string $code, CatalogStatus $status = CatalogStatus::Active): Combination
{
    $combination = Combination::factory()->create(['product_id' => $product->id, 'status' => $status]);
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => $code]);

    return $combination;
}

function priceTableTier(PriceScheme $scheme, string $label): PriceTier
{
    return $scheme->tiers->firstWhere('label', $label);
}

/**
 * @return array{combination_id: int, size_group_id: ?int, price_tier_id: int, price: ?string}
 */
function priceCell(Combination $combination, PriceTier $tier, ?string $price, ?SizeGroup $group = null): array
{
    return [
        'combination_id' => $combination->id,
        'size_group_id' => $group?->id,
        'price_tier_id' => $tier->id,
        'price' => $price,
    ];
}

/**
 * @param  list<array<string, mixed>>  $cells
 */
function savePriceTable(Product $product, array $cells, ?User $actor = null): TestResponse
{
    return test()->actingAs($actor ?? priceTableManager())->putJson("/pricing/products/{$product->id}/prices", ['cells' => $cells]);
}

/**
 * @return Collection<int, AuditLog>
 */
function priceAuditRows(AuditAction ...$actions): Collection
{
    $actions = $actions === [] ? [AuditAction::PriceCreated, AuditAction::PriceUpdated, AuditAction::PriceDeleted] : $actions;

    return AuditLog::query()->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))->orderBy('id')->get();
}

it('E-05 registers 40,00 / 36,00 / 32,00 and then changes the detal to 41,00, auditing the previous and the new value', function () {
    $actor = priceTableManager();
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');
    $detal = priceTableTier($scheme, 'Detal');

    savePriceTable($product, [
        priceCell($combination, $detal, '40,00'),
        priceCell($combination, priceTableTier($scheme, 'Intermedio'), '36.00'),
        priceCell($combination, priceTableTier($scheme, 'Mayor'), '32'),
    ], $actor)->assertRedirect();

    $prices = CombinationPrice::query()->where('combination_id', $combination->id)->orderBy('price_tier_id')->get();

    expect($prices->map(fn (CombinationPrice $price): string => $price->unit_price->toScale(2)->toString())->all())->toBe(['40.00', '36.00', '32.00'])
        ->and($prices->pluck('updated_by')->unique()->all())->toBe([$actor->id])
        ->and(priceAuditRows()->pluck('action')->all())->toBe([
            AuditAction::PriceCreated->value, AuditAction::PriceCreated->value, AuditAction::PriceCreated->value,
        ]);

    $created = priceAuditRows()->first();

    expect($created->entity_type)->toBe($prices->first()->getMorphClass())
        ->and($created->entity_id)->toBe($prices->first()->id)
        ->and($created->old_values)->toBe([])
        ->and($created->new_values)->toEqual(['code' => '110', 'size_group' => null, 'tier' => 'Detal', 'price' => '40.00']);

    savePriceTable($product, [priceCell($combination, $detal, '41,00')], $actor)->assertRedirect();

    $updated = priceAuditRows(AuditAction::PriceUpdated)->sole();

    expect($updated->old_values['price'])->toBeMoneyString()->toBe('40.00')
        ->and($updated->new_values['price'])->toBeMoneyString()->toBe('41.00')
        ->and($updated->new_values)->toEqual(['code' => '110', 'size_group' => null, 'tier' => 'Detal', 'price' => '41.00'])
        ->and($updated->actor_id)->toBe($actor->id)
        ->and(CombinationPrice::query()->where('combination_id', $combination->id)->count())->toBe(3)
        ->and(priceAuditRows())->toHaveCount(4);
});

it('E-05 writes and audits only the cells that changed', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');
    $detal = priceTableTier($scheme, 'Detal');
    $intermedio = priceTableTier($scheme, 'Intermedio');

    savePriceTable($product, [priceCell($combination, $detal, '40.00'), priceCell($combination, $intermedio, '36.00')])->assertRedirect();

    $untouched = CombinationPrice::query()->where('price_tier_id', $detal->id)->sole();
    $stamp = $untouched->updated_at;

    $this->travel(5)->minutes();

    // The same value written another way (40 = 40.00) is not a change; only the intermedio moves.
    savePriceTable($product, [priceCell($combination, $detal, '40'), priceCell($combination, $intermedio, '37.50')])->assertRedirect();

    expect($untouched->fresh()->updated_at->equalTo($stamp))->toBeTrue()
        ->and(priceAuditRows(AuditAction::PriceUpdated))->toHaveCount(1)
        ->and(priceAuditRows(AuditAction::PriceUpdated)->sole()->new_values['tier'])->toBe('Intermedio')
        ->and(priceAuditRows())->toHaveCount(3);

    $before = AuditLog::query()->count();

    savePriceTable($product, [priceCell($combination, $detal, '40.00'), priceCell($combination, $intermedio, '37.50')])->assertRedirect();

    expect(AuditLog::query()->count())->toBe($before);
});

it('E-06 rejects a price of zero, a negative price, one with more than two decimals and a non-number, with an error on the price', function (string $price) {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');
    $detal = priceTableTier($scheme, 'Detal');

    savePriceTable($product, [priceCell($combination, $detal, $price)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.price']);

    expect(CombinationPrice::query()->count())->toBe(0)
        ->and(priceAuditRows())->toHaveCount(0);
})->with([
    'zero' => ['0'],
    'zero with decimals' => ['0,00'],
    'negative' => ['-5'],
    'three decimals' => ['40.005'],
    'text' => ['cuarenta'],
    'exponent' => ['4e1'],
]);

it('E-06 accepts a price with the maximum of two decimals and the smallest positive amount', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');

    savePriceTable($product, [
        priceCell($combination, priceTableTier($scheme, 'Detal'), '0.01'),
        priceCell($combination, priceTableTier($scheme, 'Intermedio'), '1234,56'),
    ])->assertRedirect();

    expect(CombinationPrice::query()->orderBy('price_tier_id')->get()->map(fn (CombinationPrice $price): string => $price->unit_price->toString())->all())->toBe(['0.01', '1234.56']);
});

it('E-06 reports every invalid cell together and saves none of the valid ones', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');

    savePriceTable($product, [
        priceCell($combination, priceTableTier($scheme, 'Detal'), '40.00'),
        priceCell($combination, priceTableTier($scheme, 'Intermedio'), '0'),
        priceCell($combination, priceTableTier($scheme, 'Mayor'), '32.123'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['cells.1.price', 'cells.2.price']);

    expect(CombinationPrice::query()->count())->toBe(0)
        ->and(priceAuditRows())->toHaveCount(0);
});

it('E-06 rejects a price in a negotiation tier, whose price is never registered', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');

    savePriceTable($product, [priceCell($combination, priceTableTier($scheme, 'Negociación'), '30.00')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.price']);

    expect(CombinationPrice::query()->count())->toBe(0);
});

it('DEC-COT-32 deletes the price of a cell sent with a null price and audits the deletion', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');
    $detal = priceTableTier($scheme, 'Detal');
    $intermedio = priceTableTier($scheme, 'Intermedio');

    savePriceTable($product, [priceCell($combination, $detal, '40.00'), priceCell($combination, $intermedio, '36.00')])->assertRedirect();
    $deletedId = CombinationPrice::query()->where('price_tier_id', $detal->id)->sole()->id;

    savePriceTable($product, [priceCell($combination, $detal, null)])->assertRedirect();

    $deleted = priceAuditRows(AuditAction::PriceDeleted)->sole();

    expect(CombinationPrice::query()->where('price_tier_id', $detal->id)->exists())->toBeFalse()
        ->and(CombinationPrice::query()->where('price_tier_id', $intermedio->id)->exists())->toBeTrue()
        ->and($deleted->entity_id)->toBe($deletedId)
        ->and($deleted->old_values)->toEqual(['code' => '110', 'size_group' => null, 'tier' => 'Detal', 'price' => '40.00'])
        ->and($deleted->new_values)->toBe([]);
});

it('DEC-COT-32 treats a null price over an empty cell as no change and writes nothing', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');

    savePriceTable($product, [priceCell($combination, priceTableTier($scheme, 'Detal'), null)])->assertRedirect();

    expect(CombinationPrice::query()->count())->toBe(0)
        ->and(priceAuditRows())->toHaveCount(0);
});

it('COT-003 accepts prices for an inactive combination', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110', CatalogStatus::Inactive);

    savePriceTable($product, [priceCell($combination, priceTableTier($scheme, 'Detal'), '40.00')])->assertRedirect();

    expect(CombinationPrice::query()->where('combination_id', $combination->id)->count())->toBe(1);
});

it('COT-003 rejects a combination that belongs to another product', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $foreign = priceTableCombination(priceTableProduct($scheme), '210');

    savePriceTable($product, [priceCell($foreign, priceTableTier($scheme, 'Detal'), '40.00')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.combination_id']);

    expect(CombinationPrice::query()->count())->toBe(0);
});

it('COT-003 rejects an unknown combination or tier id', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');
    $detal = priceTableTier($scheme, 'Detal');

    savePriceTable($product, [
        ['combination_id' => 9_000_000, 'size_group_id' => null, 'price_tier_id' => $detal->id, 'price' => '40.00'],
        ['combination_id' => $combination->id, 'size_group_id' => null, 'price_tier_id' => 9_000_000, 'price' => '40.00'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['cells.0.combination_id', 'cells.1.price_tier_id']);
});

it('COT-003 rejects a tier of another scheme', function () {
    $scheme = priceTableScheme('Uniformes');
    $other = priceTableScheme('Pañales de adulto');
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');

    savePriceTable($product, [priceCell($combination, priceTableTier($other, 'Detal'), '40.00')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.price_tier_id']);

    expect(CombinationPrice::query()->count())->toBe(0);
});

it('COT-003 rejects a table for a product without a scheme and writes nothing', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct(null);
    $combination = priceTableCombination($product, '110');

    savePriceTable($product, [priceCell($combination, priceTableTier($scheme, 'Detal'), '40.00')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells']);

    expect(CombinationPrice::query()->count())->toBe(0);
});

it('COT-003 rejects the same cell sent twice', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');
    $detal = priceTableTier($scheme, 'Detal');

    savePriceTable($product, [priceCell($combination, $detal, '40.00'), priceCell($combination, $detal, '41.00')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.1.price_tier_id']);

    expect(CombinationPrice::query()->count())->toBe(0);
});

it('COT-004 requires the size group of a grouped combination and prices each group apart', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');
    $detal = priceTableTier($scheme, 'Detal');
    $small = SizeGroup::factory()->create(['combination_id' => $combination->id, 'sort_order' => 1]);
    $large = SizeGroup::factory()->create(['combination_id' => $combination->id, 'sort_order' => 2]);

    $names = ['S', 'M'];

    foreach ($names as $name) {
        $small->sizes()->attach(AttributeValue::factory()->create(['name' => $name])->id, ['combination_id' => $combination->id]);
    }

    savePriceTable($product, [priceCell($combination, $detal, '40.00')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.size_group_id']);

    savePriceTable($product, [priceCell($combination, $detal, '40.00', $small), priceCell($combination, $detal, '44.00', $large)])->assertRedirect();

    $audit = priceAuditRows(AuditAction::PriceCreated)->first();

    expect(CombinationPrice::query()->where('size_group_id', $small->id)->sole()->unit_price->toString())->toBe('40.00')
        ->and(CombinationPrice::query()->where('size_group_id', $large->id)->sole()->unit_price->toString())->toBe('44.00')
        ->and($audit->new_values['size_group'])->toBeString()->toContain('S')
        ->and($audit->new_values['size_group'])->toContain('M');
});

it('COT-004 rejects a size group of another combination and a size group on an ungrouped combination', function () {
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $grouped = priceTableCombination($product, '110');
    $ungrouped = priceTableCombination($product, '120');
    $group = SizeGroup::factory()->create(['combination_id' => $grouped->id, 'sort_order' => 1]);
    $detal = priceTableTier($scheme, 'Detal');

    savePriceTable($product, [priceCell($ungrouped, $detal, '40.00', $group)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.size_group_id']);

    $otherGroup = SizeGroup::factory()->create(['combination_id' => priceTableCombination($product, '130')->id, 'sort_order' => 2]);

    savePriceTable($product, [priceCell($grouped, $detal, '40.00', $otherGroup)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.size_group_id']);

    expect(CombinationPrice::query()->count())->toBe(0);
});

it('COT-001 prices a fixed scheme through its single implicit tier', function () {
    $scheme = PriceScheme::factory()->create(['name' => 'Precio único', 'type' => PriceSchemeType::Fixed]);
    $scheme->tiers()->create(['label' => 'Precio fijo', 'min_quantity' => 1, 'max_quantity' => null, 'kind' => PriceTierKind::Priced, 'sort_order' => 1]);
    $scheme = $scheme->fresh();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');

    savePriceTable($product, [priceCell($combination, $scheme->tiers->sole(), '25.00')])->assertRedirect();

    expect(CombinationPrice::query()->sole()->unit_price->toString())->toBe('25.00')
        ->and(priceAuditRows()->sole()->new_values['tier'])->toBe('Precio fijo');
});

it('COT-003 rejects malformed input before touching any price', function (array $payload) {
    $product = priceTableProduct(priceTableScheme());

    $this->actingAs(priceTableManager())->putJson("/pricing/products/{$product->id}/prices", $payload)->assertUnprocessable();

    expect(CombinationPrice::query()->count())->toBe(0);
})->with([
    'no cells' => [[]],
    'empty list' => [['cells' => []]],
    'cells not a list' => [['cells' => 'x']],
    'cell without combination' => [['cells' => [['price_tier_id' => 1, 'price' => '40.00']]]],
    'cell without tier' => [['cells' => [['combination_id' => 1, 'price' => '40.00']]]],
    'price as number' => [['cells' => [['combination_id' => 1, 'price_tier_id' => 1, 'price' => 40]]]],
]);

it('COT-003 answers 404 for an unknown product', function () {
    $this->actingAs(priceTableManager())->putJson('/pricing/products/9000000/prices', ['cells' => []])->assertNotFound();
});

it('E-07 answers 403, audited, to a user with pricing.view but without pricing.manage and changes no price', function () {
    $actor = userWithPermissions(PermissionName::PricingView);
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');
    $detal = priceTableTier($scheme, 'Detal');
    $price = CombinationPrice::factory()->create(['combination_id' => $combination->id, 'price_tier_id' => $detal->id, 'unit_price' => '40.00']);

    savePriceTable($product, [priceCell($combination, $detal, '99.00')], $actor)->assertForbidden();

    expect($price->fresh()->unit_price->toString())->toBe('40.00')
        ->and(priceAuditRows())->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->actor_id)->toBe($actor->id);
});

it('E-07 answers 403 to a user holding other permissions only', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
    $scheme = priceTableScheme();
    $product = priceTableProduct($scheme);
    $combination = priceTableCombination($product, '110');

    savePriceTable($product, [priceCell($combination, priceTableTier($scheme, 'Detal'), '40.00')], $actor)->assertForbidden();

    expect(CombinationPrice::query()->count())->toBe(0);
});

it('COT-003 declares the price table route for a product', function () {
    $route = app('router')->getRoutes()->getByName('pricing.products.prices.update');

    expect($route->uri())->toBe('pricing/products/{product}/prices')
        ->and($route->methods())->toContain('PUT');
});
