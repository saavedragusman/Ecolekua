<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\CatalogCode;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\ComboItemPrice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// COT-005 (DEC-COT-06): the unit price of each combo component, saved in one request. The combo price is
// never stored. The price page ships later, so the write endpoint answers a redirect back (003 precedent).

function comboPriceManager(): User
{
    return userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);
}

function comboPriceCombo(string $code = 'K01'): Combo
{
    $combo = Combo::factory()->create(['name' => "Kit escolar {$code}"]);
    CatalogCode::factory()->forCombo($combo)->create(['code' => $code]);

    return $combo;
}

function comboPriceComponent(Combo $combo, string $productName, int $quantity, int $sortOrder): ComboComponent
{
    /** @var ProductFactory $factory */
    $factory = Product::factory();
    $product = $factory->for(ProductCategory::factory(), 'category')->create(['name' => $productName]);

    return ComboComponent::factory()->create([
        'combo_id' => $combo->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'sort_order' => $sortOrder,
    ]);
}

/**
 * @return array{combo_component_id: int, price: ?string}
 */
function comboPriceEntry(ComboComponent $component, ?string $price): array
{
    return ['combo_component_id' => $component->id, 'price' => $price];
}

/**
 * @param  list<array<string, mixed>>  $prices
 */
function saveComboPrices(Combo $combo, array $prices, ?User $actor = null): TestResponse
{
    return test()->actingAs($actor ?? comboPriceManager())->putJson("/pricing/combos/{$combo->id}/prices", ['prices' => $prices]);
}

/**
 * @return Collection<int, AuditLog>
 */
function comboPriceAuditRows(AuditAction ...$actions): Collection
{
    $actions = $actions === [] ? [AuditAction::ComboItemPriceCreated, AuditAction::ComboItemPriceUpdated, AuditAction::ComboItemPriceDeleted] : $actions;

    return AuditLog::query()->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))->orderBy('id')->get();
}

it('COT-005 registers the price of each component and audits the combo, component number, product, quantity and price', function () {
    $actor = comboPriceManager();
    $combo = comboPriceCombo('K01');
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);
    $pants = comboPriceComponent($combo, 'Pantalón escolar', 1, 2);

    saveComboPrices($combo, [comboPriceEntry($shirt, '15,00'), comboPriceEntry($pants, '22.5')], $actor)->assertRedirect();

    $prices = ComboItemPrice::query()->orderBy('combo_component_id')->get();

    expect($prices->map(fn (ComboItemPrice $price): string => $price->unit_price->toScale(2)->toString())->all())->toBe(['15.00', '22.50'])
        ->and($prices->pluck('updated_by')->unique()->all())->toBe([$actor->id])
        ->and($combo->fresh()->getAttributes())->not->toHaveKey('price');

    $rows = comboPriceAuditRows();

    expect($rows->pluck('action')->all())->toBe([AuditAction::ComboItemPriceCreated->value, AuditAction::ComboItemPriceCreated->value])
        ->and($rows->first()->entity_type)->toBe($prices->first()->getMorphClass())
        ->and($rows->first()->entity_id)->toBe($prices->first()->id)
        ->and($rows->first()->old_values)->toBe([])
        ->and($rows->first()->new_values)->toEqual(['combo_code' => 'K01', 'component' => 1, 'product' => 'Chemise escolar', 'quantity' => 2, 'price' => '15.00'])
        ->and($rows->last()->new_values)->toEqual(['combo_code' => 'K01', 'component' => 2, 'product' => 'Pantalón escolar', 'quantity' => 1, 'price' => '22.50']);
});

it('COT-005 numbers the components by their order in the combo, not by their id', function () {
    $combo = comboPriceCombo('K01');
    $second = comboPriceComponent($combo, 'Pantalón escolar', 1, 20);
    $first = comboPriceComponent($combo, 'Chemise escolar', 2, 10);

    saveComboPrices($combo, [comboPriceEntry($second, '22.50')])->assertRedirect();

    expect(comboPriceAuditRows()->sole()->new_values['component'])->toBe(2);

    saveComboPrices($combo, [comboPriceEntry($first, '15.00')])->assertRedirect();

    expect(comboPriceAuditRows()->last()->new_values['component'])->toBe(1);
});

it('COT-005 edits a component price, auditing the previous and the new value, and writes only what changed', function () {
    $actor = comboPriceManager();
    $combo = comboPriceCombo('K01');
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);
    $pants = comboPriceComponent($combo, 'Pantalón escolar', 1, 2);

    saveComboPrices($combo, [comboPriceEntry($shirt, '15.00'), comboPriceEntry($pants, '22.50')], $actor)->assertRedirect();

    $untouched = ComboItemPrice::query()->where('combo_component_id', $pants->id)->sole();
    $stamp = $untouched->updated_at;

    $this->travel(5)->minutes();

    // The same value written another way (22,5 = 22.50) is not a change; only the shirt moves.
    saveComboPrices($combo, [comboPriceEntry($shirt, '16,00'), comboPriceEntry($pants, '22,5')], $actor)->assertRedirect();

    $updated = comboPriceAuditRows(AuditAction::ComboItemPriceUpdated)->sole();

    expect($updated->old_values)->toEqual(['combo_code' => 'K01', 'component' => 1, 'product' => 'Chemise escolar', 'quantity' => 2, 'price' => '15.00'])
        ->and($updated->new_values['price'])->toBeMoneyString()->toBe('16.00')
        ->and($updated->actor_id)->toBe($actor->id)
        ->and($untouched->fresh()->updated_at->equalTo($stamp))->toBeTrue()
        ->and(comboPriceAuditRows())->toHaveCount(3);

    $before = AuditLog::query()->count();

    saveComboPrices($combo, [comboPriceEntry($shirt, '16.00'), comboPriceEntry($pants, '22.50')], $actor)->assertRedirect();

    expect(AuditLog::query()->count())->toBe($before);
});

it('COT-005 deletes the price of a component sent with a null price and audits the deletion', function () {
    $combo = comboPriceCombo('K01');
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);
    $price = ComboItemPrice::factory()->create(['combo_component_id' => $shirt->id, 'unit_price' => '15.00']);

    saveComboPrices($combo, [comboPriceEntry($shirt, null)])->assertRedirect();

    $deleted = comboPriceAuditRows(AuditAction::ComboItemPriceDeleted)->sole();

    expect(ComboItemPrice::query()->count())->toBe(0)
        ->and($deleted->entity_id)->toBe($price->id)
        ->and($deleted->old_values)->toEqual(['combo_code' => 'K01', 'component' => 1, 'product' => 'Chemise escolar', 'quantity' => 2, 'price' => '15.00'])
        ->and($deleted->new_values)->toBe([]);
});

it('COT-005 treats a null price over a component without price as no change and writes nothing', function () {
    $combo = comboPriceCombo();
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);
    $before = AuditLog::query()->count();

    saveComboPrices($combo, [comboPriceEntry($shirt, null)])->assertRedirect();

    expect(ComboItemPrice::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($before);
});

it('COT-005 rejects a price of zero, a negative price, one with more than two decimals and a non-number, with an error on the price', function (string $price) {
    $combo = comboPriceCombo();
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);

    saveComboPrices($combo, [comboPriceEntry($shirt, $price)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prices.0.price']);

    expect(ComboItemPrice::query()->count())->toBe(0)
        ->and(comboPriceAuditRows())->toHaveCount(0);
})->with([
    'zero' => ['0'],
    'zero with decimals' => ['0,00'],
    'negative' => ['-5'],
    'three decimals' => ['15.005'],
    'text' => ['quince'],
    'exponent' => ['1e1'],
]);

it('COT-005 reports every invalid entry together and saves none of the valid ones', function () {
    $combo = comboPriceCombo();
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);
    $pants = comboPriceComponent($combo, 'Pantalón escolar', 1, 2);
    $other = comboPriceComponent(comboPriceCombo('K02'), 'Franela deportiva', 1, 1);

    saveComboPrices($combo, [
        comboPriceEntry($shirt, '15.00'),
        comboPriceEntry($pants, '0'),
        comboPriceEntry($other, '10.00'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['prices.1.price', 'prices.2.combo_component_id']);

    expect(ComboItemPrice::query()->count())->toBe(0)
        ->and(comboPriceAuditRows())->toHaveCount(0);
});

it('COT-005 rejects a component of another combo', function () {
    $combo = comboPriceCombo('K01');
    comboPriceComponent($combo, 'Chemise escolar', 2, 1);
    $other = comboPriceComponent(comboPriceCombo('K02'), 'Franela deportiva', 1, 1);

    saveComboPrices($combo, [comboPriceEntry($other, '10.00')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prices.0.combo_component_id']);

    expect(ComboItemPrice::query()->count())->toBe(0);
});

it('COT-005 rejects the same component sent twice', function () {
    $combo = comboPriceCombo();
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);

    saveComboPrices($combo, [comboPriceEntry($shirt, '15.00'), comboPriceEntry($shirt, '16.00')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prices.1.combo_component_id']);

    expect(ComboItemPrice::query()->count())->toBe(0);
});

it('COT-005 accepts prices for the components of an inactive combo', function () {
    $combo = Combo::factory()->inactive()->create();
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);

    saveComboPrices($combo, [comboPriceEntry($shirt, '15.00')])->assertRedirect();

    expect(ComboItemPrice::query()->sole()->combo_component_id)->toBe($shirt->id);
});

it('COT-005 rejects malformed input before touching any price', function (array $payload) {
    $combo = comboPriceCombo();

    $this->actingAs(comboPriceManager())->putJson("/pricing/combos/{$combo->id}/prices", $payload)->assertUnprocessable();

    expect(ComboItemPrice::query()->count())->toBe(0);
})->with([
    'no prices' => [[]],
    'empty list' => [['prices' => []]],
    'prices not a list' => [['prices' => 'x']],
    'entry without component' => [['prices' => [['price' => '15.00']]]],
    'unknown component' => [['prices' => [['combo_component_id' => 9000000, 'price' => '15.00']]]],
    'entry without price key' => [['prices' => [['combo_component_id' => 1]]]],
    'price as number' => [['prices' => [['combo_component_id' => 1, 'price' => 15]]]],
]);

it('COT-005 answers 404 for an unknown combo', function () {
    $this->actingAs(comboPriceManager())->putJson('/pricing/combos/9000000/prices', ['prices' => []])->assertNotFound();
});

it('COT-005 answers 403, audited, to a user with pricing.view but without pricing.manage and changes no price', function () {
    $actor = userWithPermissions(PermissionName::PricingView);
    $combo = comboPriceCombo();
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);
    $price = ComboItemPrice::factory()->create(['combo_component_id' => $shirt->id, 'unit_price' => '15.00']);

    saveComboPrices($combo, [comboPriceEntry($shirt, '99.00')], $actor)->assertForbidden();

    expect($price->fresh()->unit_price->toString())->toBe('15.00')
        ->and(comboPriceAuditRows())->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->actor_id)->toBe($actor->id);
});

it('COT-005 answers 403 to a user holding product permissions only', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
    $combo = comboPriceCombo();
    $shirt = comboPriceComponent($combo, 'Chemise escolar', 2, 1);

    saveComboPrices($combo, [comboPriceEntry($shirt, '15.00')], $actor)->assertForbidden();

    expect(ComboItemPrice::query()->count())->toBe(0);
});

it('COT-005 declares the price route for a combo', function () {
    $route = app('router')->getRoutes()->getByName('pricing.combos.prices.update');

    expect($route->uri())->toBe('pricing/combos/{combo}/prices')
        ->and($route->methods())->toContain('PUT');
});

// DEC-COT-44 (design Decision 12): editing the components of a combo (003) keeps the price of every
// component whose product does not change, even when its quantity or order changes. A new component, or
// one with another product, has no price.

/**
 * Prices the stored components of a combo in their order, one amount per component.
 */
function priceComboComponents(Combo $combo, string ...$amounts): void
{
    foreach ($combo->components()->get()->values() as $index => $component) {
        ComboItemPrice::factory()->create(['combo_component_id' => $component->id, 'unit_price' => $amounts[$index]]);
    }
}

/**
 * The price of each stored component of a combo, in combo order, as `[product, quantity, price|null]`.
 *
 * @return list<array{0: string, 1: int, 2: ?string}>
 */
function comboComponentPrices(Combo $combo): array
{
    return array_values($combo->components()->with(['product', 'price'])->get()->map(fn (ComboComponent $component): array => [
        $component->product->name,
        $component->quantity,
        $component->price?->unit_price->toScale(2)->toString(),
    ])->all());
}

/**
 * One component of the edit payload that names the stored component it edits.
 *
 * @param  array<string, list<string>>  $values
 * @return array{id: int, product_id: int, quantity: int, values: array<int, list<int>>}
 */
function comboComponentWithId(array $catalog, ComboComponent $stored, string $product, int $quantity, array $values = []): array
{
    return ['id' => $stored->id, ...comboComponent($catalog, $product, $quantity, $values)];
}

it('DEC-COT-44 keeps the component prices when the quantity or the order of the components changes', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    priceComboComponents($combo, '10.00', '5.00', '20.00');
    [$diaper, $pad, $cover] = $combo->components()->get()->all();
    $priceIds = ComboItemPrice::query()->orderBy('id')->pluck('id')->all();

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [
            comboComponentWithId($catalog, $cover, 'Protector de cama', 1),
            comboComponentWithId($catalog, $diaper, 'Pañal antiderrame', 4, ['Talla' => ['3XG', '4XG', '5XG']]),
            comboComponentWithId($catalog, $pad, 'Absorbente', 3),
        ],
    ]))->assertRedirect("/combos/{$combo->id}");

    expect(comboComponentPrices($combo))->toBe([
        ['Protector de cama', 1, '20.00'],
        ['Pañal antiderrame', 4, '10.00'],
        ['Absorbente', 3, '5.00'],
    ])
        ->and($combo->components()->pluck('id')->all())->toBe([$cover->id, $diaper->id, $pad->id])
        ->and(ComboItemPrice::query()->orderBy('id')->pluck('id')->all())->toBe($priceIds)
        ->and(comboAudit(AuditAction::ComboUpdated)->sole()->old_values)->not->toHaveKey('deleted_item_prices');
});

it('DEC-COT-44 drops the price of a component whose product is replaced and lists it in the combo audit', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    priceComboComponents($combo, '10.00', '5.00', '20.00');
    [$diaper, $pad, $cover] = $combo->components()->get()->all();

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [
            comboComponentWithId($catalog, $diaper, 'Pañal antiderrame', 2, ['Talla' => ['3XG', '4XG', '5XG']]),
            comboComponentWithId($catalog, $pad, 'Pañal ecológico', 3),
            comboComponentWithId($catalog, $cover, 'Protector de cama', 1),
        ],
    ]))->assertRedirect("/combos/{$combo->id}");

    $audit = comboAudit(AuditAction::ComboUpdated)->sole();

    expect(comboComponentPrices($combo))->toBe([
        ['Pañal antiderrame', 2, '10.00'],
        ['Pañal ecológico', 3, null],
        ['Protector de cama', 1, '20.00'],
    ])
        ->and(ComboComponent::query()->whereKey($pad->id)->exists())->toBeFalse()
        ->and($audit->old_values['deleted_item_prices'])->toEqual([
            ['combo_code' => 'K-ORO', 'component' => 2, 'product' => 'Absorbente', 'quantity' => 3, 'price' => '5.00'],
        ])
        ->and($audit->new_values)->not->toHaveKey('deleted_item_prices')
        ->and(comboPriceAuditRows())->toHaveCount(0);
});

it('DEC-COT-44 drops the price of a removed component and leaves a new one without price', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    priceComboComponents($combo, '10.00', '5.00', '20.00');
    [$diaper, , $cover] = $combo->components()->get()->all();

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [
            comboComponentWithId($catalog, $diaper, 'Pañal antiderrame', 2, ['Talla' => ['3XG', '4XG', '5XG']]),
            comboComponentWithId($catalog, $cover, 'Protector de cama', 1),
            comboComponent($catalog, 'Pañal ecológico', 2),
        ],
    ]))->assertRedirect("/combos/{$combo->id}");

    expect(comboComponentPrices($combo))->toBe([
        ['Pañal antiderrame', 2, '10.00'],
        ['Protector de cama', 1, '20.00'],
        ['Pañal ecológico', 2, null],
    ])
        ->and(comboAudit(AuditAction::ComboUpdated)->sole()->old_values['deleted_item_prices'])->toEqual([
            ['combo_code' => 'K-ORO', 'component' => 2, 'product' => 'Absorbente', 'quantity' => 3, 'price' => '5.00'],
        ]);
});

it('DEC-COT-44 keeps the prices of an edit without ids by pairing each product with its stored components in order', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog, ['components' => [
        comboComponent($catalog, 'Pañal antiderrame', 1, ['Talla' => ['3XG']]),
        comboComponent($catalog, 'Pañal antiderrame', 2, ['Talla' => ['4XG']]),
        comboComponent($catalog, 'Absorbente', 1),
    ]]);
    priceComboComponents($combo, '11.00', '12.00', '13.00');

    // An older client or a caller without ids: the k-th id-less entry of a product takes the k-th stored
    // component of that product, whatever the new order.
    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [
            comboComponent($catalog, 'Absorbente', 1),
            comboComponent($catalog, 'Pañal antiderrame', 5, ['Talla' => ['3XG']]),
            comboComponent($catalog, 'Pañal antiderrame', 6, ['Talla' => ['4XG']]),
        ],
    ]))->assertRedirect("/combos/{$combo->id}");

    expect(comboComponentPrices($combo))->toBe([
        ['Absorbente', 1, '13.00'],
        ['Pañal antiderrame', 5, '11.00'],
        ['Pañal antiderrame', 6, '12.00'],
    ])
        ->and(comboAudit(AuditAction::ComboUpdated)->sole()->old_values)->not->toHaveKey('deleted_item_prices');
});

it('DEC-COT-44 tells apart two components of the same product by their ids', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog, ['components' => [
        comboComponent($catalog, 'Pañal antiderrame', 1, ['Talla' => ['3XG']]),
        comboComponent($catalog, 'Pañal antiderrame', 2, ['Talla' => ['4XG']]),
    ]]);
    priceComboComponents($combo, '11.00', '12.00');
    [$first, $second] = $combo->components()->get()->all();

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [
            comboComponentWithId($catalog, $second, 'Pañal antiderrame', 2, ['Talla' => ['4XG']]),
            comboComponentWithId($catalog, $first, 'Pañal antiderrame', 1, ['Talla' => ['3XG']]),
        ],
    ]))->assertRedirect("/combos/{$combo->id}");

    expect(comboComponentPrices($combo))->toBe([
        ['Pañal antiderrame', 2, '12.00'],
        ['Pañal antiderrame', 1, '11.00'],
    ])
        ->and($combo->components()->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('DEC-COT-44 rejects a component id of another combo, or unknown, on its field and changes nothing', function (bool $unknown) {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    priceComboComponents($combo, '10.00', '5.00', '20.00');
    $other = makeCombo($catalog, ['name' => 'Kit Plata', 'code' => 'K-PLATA']);
    $foreignId = $unknown ? 9000000 : $other->components()->first()->id;
    $before = comboComponentPrices($combo);

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [
            ['id' => $foreignId, ...comboComponent($catalog, 'Pañal antiderrame', 2, ['Talla' => ['3XG', '4XG', '5XG']])],
            comboComponent($catalog, 'Absorbente', 3),
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['components.0.id']);

    expect(comboComponentPrices($combo))->toBe($before)
        ->and(comboAudit(AuditAction::ComboUpdated))->toHaveCount(0);
})->with(['another combo' => [false], 'unknown' => [true]]);

it('DEC-COT-44 rejects a component id on create, where every component is new', function () {
    $catalog = comboCatalog();
    $stored = makeCombo($catalog)->components()->first();

    postCombo($this, comboCreator(), comboPayload($catalog, [
        'name' => 'Kit Plata',
        'code' => 'K-PLATA',
        'components' => [comboComponentWithId($catalog, $stored, 'Absorbente', 3)],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['components.0.id']);

    expect(Combo::query()->count())->toBe(1);
});

it('DEC-COT-44 rejects an id that is not an integer', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [['id' => 'x', ...comboComponent($catalog, 'Absorbente', 3)]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['components.0.id']);
});

it('DEC-COT-44 gives the combo edit form the id of each stored component so it can send it back', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $ids = $combo->components()->pluck('id')->all();

    $this->actingAs(comboEditor())->get("/combos/{$combo->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('initial.components.0.id', $ids[0])
        ->where('initial.components.1.id', $ids[1])
        ->where('initial.components.2.id', $ids[2]));
});
