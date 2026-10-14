<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\SizeGroup;
use App\Models\User;
use App\Support\Pricing\MissingPrices;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

// COT-004 (E-08, E-70, DEC-COT-48, DEC-COT-53, DEC-COT-58): the size groups of a combination, saved in
// two steps (preview, then PUT with the confirmation token) and cleaned up when a size leaves (003).
// The pricing screens ship later, so the preview answers JSON and the PUT redirects back.

function sgManager(): User
{
    return userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);
}

function sgEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

/**
 * Combination `184` of a product with the Uniformes-like scheme (Detal, Intermedio, Mayor and a
 * negotiation tier). The product declares Talla as an order attribute admitting these sizes.
 *
 * @param  list<string>  $names
 * @return array{product: Product, scheme: PriceScheme, size: CatalogAttribute, declared: ProductAttribute, sizes: array<string, int>, combination: Combination}
 */
function sgFixture(array $names = ['28', '30', '32', '34', '36', '38', '40', '42', '44']): array
{
    $size = CatalogAttribute::factory()->size()->create();
    $scheme = PriceScheme::factory()->negotiationLast()->create();
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create(['price_scheme_id' => $scheme->id]);
    $declared = ProductAttribute::factory()->order()->create(['product_id' => $product->id, 'catalog_attribute_id' => $size->id, 'sort_order' => 1]);
    $sizes = [];

    foreach ($names as $position => $name) {
        $value = AttributeValue::factory()->for($size, 'catalogAttribute')->create(['name' => $name, 'sort_order' => $position + 1]);
        $declared->allowedValues()->attach($value->id);
        $sizes[$name] = $value->id;
    }

    $combination = Combination::factory()->for($product)->create();
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => '184']);

    return ['product' => $product, 'scheme' => $scheme, 'size' => $size, 'declared' => $declared, 'sizes' => $sizes, 'combination' => $combination];
}

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function sgIds(array $fx, array $names): array
{
    return array_map(fn (string $name): int => $fx['sizes'][$name], $names);
}

/**
 * @param  list<list<string>>  $groups  size names of each group
 * @return array<string, mixed>
 */
function sgPayload(array $fx, array $groups, ?string $token = null): array
{
    $payload = ['groups' => array_map(fn (array $names): array => sgIds($fx, $names), $groups)];

    return $token === null ? $payload : [...$payload, 'confirm_token' => $token];
}

/**
 * @param  list<list<string>>  $groups
 */
function sgSave(array $fx, array $groups, ?string $token = null, ?User $actor = null): TestResponse
{
    return test()->actingAs($actor ?? sgManager())->putJson("/pricing/combinations/{$fx['combination']->id}/size-groups", sgPayload($fx, $groups, $token));
}

/**
 * @param  list<list<string>>  $groups
 */
function sgPreview(array $fx, array $groups, ?User $actor = null): TestResponse
{
    return test()->actingAs($actor ?? sgManager())->postJson("/pricing/combinations/{$fx['combination']->id}/size-groups/preview", sgPayload($fx, $groups));
}

/**
 * Stores groups of the combination straight in the database, in this order.
 *
 * @param  list<list<string>>  $groups
 * @return list<SizeGroup>
 */
function sgStore(array $fx, array $groups, ?Combination $combination = null): array
{
    $combination ??= $fx['combination'];
    $stored = [];

    foreach ($groups as $order => $names) {
        $group = SizeGroup::factory()->create(['combination_id' => $combination->id, 'sort_order' => $order + 1]);

        foreach ($names as $name) {
            $group->sizes()->attach($fx['sizes'][$name], ['combination_id' => $combination->id]);
        }

        $stored[] = $group;
    }

    return $stored;
}

function sgPrice(array $fx, string $tier, string $price, ?SizeGroup $group = null, ?Combination $combination = null): CombinationPrice
{
    return CombinationPrice::factory()->create([
        'combination_id' => ($combination ?? $fx['combination'])->id,
        'price_tier_id' => PriceTier::query()->where('price_scheme_id', $fx['scheme']->id)->where('label', $tier)->firstOrFail()->id,
        'size_group_id' => $group?->id,
        'unit_price' => $price,
    ]);
}

/**
 * Every group, group size and price row, to prove that a refused request wrote nothing.
 *
 * @return array{groups: list<object>, sizes: list<object>, prices: list<object>}
 */
function sgState(): array
{
    return [
        'groups' => DB::table('size_groups')->orderBy('id')->get()->all(),
        'sizes' => DB::table('size_group_values')->orderBy('size_group_id')->orderBy('size_value_id')->get()->all(),
        'prices' => DB::table('combination_prices')->orderBy('id')->get()->all(),
    ];
}

/**
 * @return Collection<int, AuditLog>
 */
function sgAudit(): Collection
{
    return AuditLog::query()->where('action', AuditAction::SizeGroupsUpdated->value)->orderBy('id')->get();
}

/**
 * @return list<string>
 */
function sgGroupNames(SizeGroup $group): array
{
    return $group->sizes->sortBy('sort_order')->pluck('name')->values()->all();
}

const SG_LOW = ['28', '30', '32', '34', '36'];
const SG_HIGH = ['38', '40', '42', '44'];

// --- E-08: valid groups --------------------------------------------------------------------------

it('E-08 saves the groups 28–36 and 38–44 of a combination that admits 28 to 44, in that order, and audits the partition', function () {
    $fx = sgFixture();
    $actor = sgManager();

    sgSave($fx, [SG_LOW, SG_HIGH], actor: $actor)->assertRedirect();

    $groups = $fx['combination']->sizeGroups()->with('sizes')->get();
    $audit = sgAudit()->sole();

    expect($groups->pluck('sort_order')->all())->toBe([1, 2])
        ->and(sgGroupNames($groups[0]))->toBe(SG_LOW)
        ->and(sgGroupNames($groups[1]))->toBe(SG_HIGH)
        ->and($audit->entity_type)->toBe($fx['combination']->getMorphClass())
        ->and($audit->entity_id)->toBe($fx['combination']->id)
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->old_values)->toEqual(['partition' => [], 'deleted_prices' => []])
        ->and($audit->new_values)->toEqual(['partition' => [SG_LOW, SG_HIGH]]);
});

it('E-08 rejects a group that repeats 36 or leaves 44 out, with an error on groups, and saves nothing', function (array $groups, string $message) {
    $fx = sgFixture();
    $before = sgState();

    sgSave($fx, $groups)->assertUnprocessable()->assertJsonValidationErrors(['groups'])->assertJsonPath('errors.groups.0', $message);
    sgPreview($fx, $groups)->assertUnprocessable()->assertJsonValidationErrors(['groups'])->assertJsonPath('errors.groups.0', $message);

    expect(sgState())->toEqual($before)
        ->and(sgAudit())->toHaveCount(0);
})->with([
    'repeats 36' => [[SG_LOW, ['36', ...SG_HIGH]], 'La talla 36 está en más de un grupo.'],
    'leaves 44 out' => [[SG_LOW, ['38', '40', '42']], 'La talla 44 está sin grupo.'],
]);

it('E-08 reports an empty group and a size the combination does not admit on that group', function () {
    $fx = sgFixture();
    $foreign = AttributeValue::factory()->create()->id;

    test()->actingAs(sgManager())->putJson("/pricing/combinations/{$fx['combination']->id}/size-groups", [
        'groups' => [[], [...sgIds($fx, SG_LOW), $foreign], sgIds($fx, SG_HIGH)],
    ])->assertUnprocessable()->assertJsonValidationErrors(['groups.0', 'groups.1']);

    expect(SizeGroup::query()->count())->toBe(0);
});

it('E-08 only accepts the sizes the combination admits when it is restricted by size', function () {
    $fx = sgFixture();

    foreach (['28', '30', '32'] as $name) {
        $fx['combination']->values()->attach($fx['sizes'][$name], ['catalog_attribute_id' => $fx['size']->id]);
    }

    sgSave($fx, [['28', '30'], ['32', '34']])->assertUnprocessable()->assertJsonValidationErrors(['groups.1']);
    sgSave($fx, [['28', '30']])->assertUnprocessable()->assertJsonValidationErrors(['groups']);

    expect(SizeGroup::query()->count())->toBe(0);

    sgSave($fx, [['28', '30'], ['32']])->assertRedirect();

    expect($fx['combination']->sizeGroups()->count())->toBe(2);
});

it('COT-004 refuses groups for a combination whose product does not declare Talla as an order attribute', function () {
    $fx = sgFixture();
    $fx['declared']->update(['role' => 'axis']);

    $refused = function () use ($fx): void {
        sgSave($fx, [SG_LOW, SG_HIGH])->assertUnprocessable()->assertJsonValidationErrors(['groups'])
            ->assertJsonPath('errors.groups.0', 'Esta combinación no admite grupos de talla.');
        sgPreview($fx, [])->assertUnprocessable()->assertJsonValidationErrors(['groups']);
    };

    $refused();

    $fx['declared']->delete();
    $refused();

    expect(SizeGroup::query()->count())->toBe(0);
});

it('COT-004 rejects malformed input before reading any group', function (array $payload) {
    $fx = sgFixture();

    test()->actingAs(sgManager())->putJson("/pricing/combinations/{$fx['combination']->id}/size-groups", $payload)->assertUnprocessable();
    test()->actingAs(sgManager())->postJson("/pricing/combinations/{$fx['combination']->id}/size-groups/preview", $payload)->assertUnprocessable();

    expect(SizeGroup::query()->count())->toBe(0);
})->with([
    'no groups key' => [[]],
    'groups not a list' => [['groups' => 'all']],
    'group not a list' => [['groups' => ['28']]],
    'size not an integer' => [['groups' => [['abc']]]],
    'token not a string' => [['groups' => [], 'confirm_token' => ['x']]],
]);

it('COT-004 answers 404 for an unknown combination', function () {
    test()->actingAs(sgManager())->putJson('/pricing/combinations/9000000/size-groups', ['groups' => []])->assertNotFound();
    test()->actingAs(sgManager())->postJson('/pricing/combinations/9000000/size-groups/preview', ['groups' => []])->assertNotFound();
});

it('E-08 saving the same groups again changes and audits nothing', function () {
    $fx = sgFixture();
    [$low, $high] = sgStore($fx, [SG_LOW, SG_HIGH]);
    sgPrice($fx, 'Detal', '40.00', $low);
    sgPrice($fx, 'Detal', '44.00', $high);
    $before = sgState();

    sgSave($fx, [SG_LOW, SG_HIGH])->assertRedirect();

    expect(sgState())->toEqual($before)
        ->and(sgAudit())->toHaveCount(0);
});

// --- E-70: preview and confirmation ---------------------------------------------------------------

it('E-70 lists every price the change deletes and a token, without locking, writing or auditing', function () {
    $fx = sgFixture();
    sgPrice($fx, 'Detal', '40.00');
    sgPrice($fx, 'Intermedio', '36.00');
    sgPrice($fx, 'Mayor', '1150.00');
    $before = sgState();

    $actor = sgManager();

    DB::enableQueryLog();
    $response = sgPreview($fx, [SG_LOW, SG_HIGH], $actor)->assertOk();
    // The session row the framework writes at the end of any request is not the preview's.
    $queries = collect(DB::getQueryLog())->map(fn (array $query): string => strtolower($query['query']))->reject(fn (string $sql): bool => str_contains($sql, '`sessions`'));
    DB::disableQueryLog();

    expect($response->json('requires_confirmation'))->toBeTrue()
        ->and($response->json('deleted_prices'))->toBe([
            ['group' => null, 'tier' => 'Detal', 'price' => ['value' => '40.00', 'display' => '40,00']],
            ['group' => null, 'tier' => 'Intermedio', 'price' => ['value' => '36.00', 'display' => '36,00']],
            ['group' => null, 'tier' => 'Mayor', 'price' => ['value' => '1150.00', 'display' => '1.150,00']],
        ])
        ->and($response->json('confirm_token'))->toMatch('/^[a-f0-9]{64}$/')
        ->and($queries->count())->toBeGreaterThan(0)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'for update') || preg_match('/^(insert|update|delete)\b/', $sql) === 1)->values()->all())->toBe([])
        ->and(sgState())->toEqual($before)
        ->and(sgAudit())->toHaveCount(0);
});

it('E-70 answers that nothing needs confirming when no price would be deleted', function () {
    $fx = sgFixture();

    $response = sgPreview($fx, [SG_LOW, SG_HIGH])->assertOk();

    expect($response->json('requires_confirmation'))->toBeFalse()
        ->and($response->json('deleted_prices'))->toBe([])
        ->and($response->json('confirm_token'))->toBeNull();
});

it('E-70 refuses the PUT without the token when prices would be deleted, and changes nothing', function () {
    $fx = sgFixture();
    sgPrice($fx, 'Detal', '40.00');
    sgPrice($fx, 'Intermedio', '36.00');
    $before = sgState();

    sgSave($fx, [SG_LOW, SG_HIGH])
        ->assertUnprocessable()
        ->assertJsonPath('errors.groups.0', 'Confirme la eliminación de los precios afectados.');

    expect(sgState())->toEqual($before)
        ->and(sgAudit())->toHaveCount(0);
});

it('E-70 saves the groups, deletes the listed prices and audits them once when the token is sent, leaving the combination without price', function () {
    $fx = sgFixture();
    sgPrice($fx, 'Detal', '40.00');
    sgPrice($fx, 'Intermedio', '36.00');
    $token = sgPreview($fx, [SG_LOW, SG_HIGH])->json('confirm_token');

    DB::enableQueryLog();
    sgSave($fx, [SG_LOW, SG_HIGH], $token)->assertRedirect();
    $locked = collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains(strtolower($query['query']), 'for update'));
    DB::disableQueryLog();

    $audit = sgAudit()->sole();
    $missing = MissingPrices::forProducts([$fx['product']->id])[$fx['product']->id];

    expect($locked)->toBeTrue()
        ->and(CombinationPrice::query()->count())->toBe(0)
        ->and($fx['combination']->sizeGroups()->count())->toBe(2)
        ->and($audit->old_values)->toEqual([
            'partition' => [],
            'deleted_prices' => [
                ['code' => '184', 'size_group' => null, 'tier' => 'Detal', 'price' => '40.00'],
                ['code' => '184', 'size_group' => null, 'tier' => 'Intermedio', 'price' => '36.00'],
            ],
        ])
        ->and($audit->new_values)->toEqual(['partition' => [SG_LOW, SG_HIGH]])
        ->and($missing)->toHaveCount(1)
        ->and($missing[0]['combination_id'])->toBe($fx['combination']->id)
        ->and($missing[0]['missing'])->toHaveCount(6);
});

it('E-70 refuses with "los precios cambiaron" and deletes nothing when a price changed or appeared after the preview', function () {
    $fx = sgFixture();
    $detal = sgPrice($fx, 'Detal', '40.00');
    sgPrice($fx, 'Intermedio', '36.00');
    $groups = [SG_LOW, SG_HIGH];
    $message = 'Los precios cambiaron; revise de nuevo los grupos.';

    $stale = sgPreview($fx, $groups)->json('confirm_token');
    $detal->update(['unit_price' => '41.00']);
    $edited = sgState();

    sgSave($fx, $groups, $stale)->assertUnprocessable()->assertJsonPath('errors.groups.0', $message);

    expect(sgState())->toEqual($edited)
        ->and(sgAudit())->toHaveCount(0);

    $stale = sgPreview($fx, $groups)->json('confirm_token');
    sgPrice($fx, 'Mayor', '30.00');
    $added = sgState();

    sgSave($fx, $groups, $stale)->assertUnprocessable()->assertJsonPath('errors.groups.0', $message);

    expect(sgState())->toEqual($added)
        ->and(sgAudit())->toHaveCount(0);

    sgSave($fx, $groups, sgPreview($fx, $groups)->json('confirm_token'))->assertRedirect();

    expect(CombinationPrice::query()->count())->toBe(0)
        ->and(sgAudit()->sole()->old_values['deleted_prices'])->toHaveCount(3);
});

it('E-70 removing the groups lists their prices by group and tier and deletes them with the groups once confirmed', function () {
    $fx = sgFixture();
    [$low, $high] = sgStore($fx, [SG_LOW, SG_HIGH]);
    sgPrice($fx, 'Intermedio', '38.00', $high);
    sgPrice($fx, 'Detal', '40.00', $low);
    sgPrice($fx, 'Detal', '44.00', $high);

    $preview = sgPreview($fx, [])->assertOk();

    expect($preview->json('requires_confirmation'))->toBeTrue()
        ->and(array_map(fn (array $row): array => [$row['group'], $row['tier'], $row['price']['value']], $preview->json('deleted_prices')))->toBe([
            ['28–36', 'Detal', '40.00'],
            ['38–44', 'Detal', '44.00'],
            ['38–44', 'Intermedio', '38.00'],
        ]);

    $before = sgState();
    sgSave($fx, [])->assertUnprocessable()->assertJsonValidationErrors(['groups']);

    expect(sgState())->toEqual($before);

    sgSave($fx, [], $preview->json('confirm_token'))->assertRedirect();

    $audit = sgAudit()->sole();

    expect(SizeGroup::query()->count())->toBe(0)
        ->and(DB::table('size_group_values')->count())->toBe(0)
        ->and(CombinationPrice::query()->count())->toBe(0)
        ->and($audit->old_values['partition'])->toBe([SG_LOW, SG_HIGH])
        ->and($audit->old_values['deleted_prices'])->toEqual([
            ['code' => '184', 'size_group' => '28–36', 'tier' => 'Detal', 'price' => '40.00'],
            ['code' => '184', 'size_group' => '38–44', 'tier' => 'Detal', 'price' => '44.00'],
            ['code' => '184', 'size_group' => '38–44', 'tier' => 'Intermedio', 'price' => '38.00'],
        ])
        ->and($audit->new_values)->toEqual(['partition' => []]);
});

it('E-70 merging 28–36 with 38–44 deletes the prices of both groups once confirmed and creates one group without prices', function () {
    $fx = sgFixture();
    [$low, $high] = sgStore($fx, [SG_LOW, SG_HIGH]);
    sgPrice($fx, 'Detal', '40.00', $low);
    sgPrice($fx, 'Detal', '44.00', $high);
    $all = [[...SG_LOW, ...SG_HIGH]];

    $preview = sgPreview($fx, $all)->assertOk();

    expect(array_column($preview->json('deleted_prices'), 'group'))->toBe(['28–36', '38–44']);

    sgSave($fx, $all)->assertUnprocessable();
    sgSave($fx, $all, $preview->json('confirm_token'))->assertRedirect();

    $groups = $fx['combination']->sizeGroups()->with('sizes')->get();

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->id)->not->toBe($low->id)
        ->and($groups[0]->id)->not->toBe($high->id)
        ->and($groups[0]->sizes)->toHaveCount(9)
        ->and(CombinationPrice::query()->count())->toBe(0)
        ->and(sgAudit()->sole()->new_values)->toEqual(['partition' => [[...SG_LOW, ...SG_HIGH]]]);
});

// --- DEC-COT-58: assign versus move --------------------------------------------------------------

it('DEC-COT-58 assigning a size that had no group to an existing group keeps the group and its prices without confirmation', function () {
    $fx = sgFixture();
    [$low, $high] = sgStore($fx, [SG_LOW, ['38', '40', '42']]);
    $lowPrice = sgPrice($fx, 'Detal', '40.00', $low);
    $highPrice = sgPrice($fx, 'Detal', '44.00', $high);

    expect(sgPreview($fx, [SG_LOW, SG_HIGH])->json())->toBe(['requires_confirmation' => false, 'deleted_prices' => [], 'confirm_token' => null]);

    sgSave($fx, [SG_LOW, SG_HIGH])->assertRedirect();

    $groups = $fx['combination']->sizeGroups()->with('sizes')->get();

    expect($groups->pluck('id')->all())->toBe([$low->id, $high->id])
        ->and(sgGroupNames($groups[1]))->toBe(SG_HIGH)
        ->and($highPrice->fresh()->unit_price->toString())->toBe('44.00')
        ->and($lowPrice->fresh()->unit_price->toString())->toBe('40.00')
        ->and(CombinationPrice::query()->count())->toBe(2)
        ->and(sgAudit()->sole()->old_values['deleted_prices'])->toBe([]);
});

it('DEC-COT-58 moving a size from one group to another needs confirmation and deletes the prices of both groups', function () {
    $fx = sgFixture();
    [$low, $high] = sgStore($fx, [SG_LOW, SG_HIGH]);
    sgPrice($fx, 'Detal', '40.00', $low);
    sgPrice($fx, 'Detal', '44.00', $high);
    $moved = [['28', '30', '32', '34'], ['36', ...SG_HIGH]];

    $preview = sgPreview($fx, $moved)->assertOk();

    expect($preview->json('requires_confirmation'))->toBeTrue()
        ->and(array_column($preview->json('deleted_prices'), 'group'))->toBe(['28–36', '38–44']);

    sgSave($fx, $moved)->assertUnprocessable();
    expect(CombinationPrice::query()->count())->toBe(2);

    sgSave($fx, $moved, $preview->json('confirm_token'))->assertRedirect();

    $groups = $fx['combination']->sizeGroups()->with('sizes')->get();

    expect(CombinationPrice::query()->count())->toBe(0)
        ->and($groups->map(fn (SizeGroup $group): array => sgGroupNames($group))->all())->toBe($moved)
        ->and($groups->pluck('id')->all())->not->toContain($low->id)
        ->and($groups->pluck('id')->all())->not->toContain($high->id);
});

it('COT-004 names a group by its derived label in the deleted prices of a scheme change', function () {
    $fx = sgFixture();
    [$low] = sgStore($fx, [SG_LOW, SG_HIGH]);
    sgPrice($fx, 'Detal', '40.00', $low);
    $other = PriceScheme::factory()->fixed()->create();

    test()->actingAs(userWithPermissions(PermissionName::PricingManage))->putJson("/pricing/products/{$fx['product']->id}/scheme", [
        'product_ids' => [$fx['product']->id],
        'price_scheme_id' => $other->id,
        'confirm_price_removal' => true,
    ])->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::ProductSchemeAssigned->value)->sole();

    expect($audit->old_values['deleted_prices'])->toEqual([['code' => '184', 'size_group' => '28–36', 'tier' => 'Detal', 'price' => '40.00']]);
});

// --- DEC-COT-48: a size leaves the product or the combination (003) --------------------------------

/**
 * The structure payload of the fixture's product with these sizes admitted.
 *
 * @param  list<string>  $names
 * @return array{attributes: list<array<string, mixed>>}
 */
function sgStructure(array $fx, array $names): array
{
    return ['attributes' => [['attribute_id' => $fx['size']->id, 'role' => 'order', 'allowed_value_ids' => sgIds($fx, $names)]]];
}

it('DEC-COT-48 takes a size removed from the product out of its group, keeping the group and its prices, and audits the removal', function () {
    $fx = sgFixture();
    [$low, $high] = sgStore($fx, [SG_LOW, SG_HIGH]);
    sgPrice($fx, 'Detal', '40.00', $low);
    sgPrice($fx, 'Detal', '44.00', $high);

    test()->actingAs(sgEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", sgStructure($fx, [...SG_LOW, '38', '40', '42']))
        ->assertRedirect();

    $audit = sgAudit()->sole();

    expect(sgGroupNames($high->fresh()->load('sizes')))->toBe(['38', '40', '42'])
        ->and(CombinationPrice::query()->count())->toBe(2)
        ->and($audit->entity_id)->toBe($fx['combination']->id)
        ->and($audit->context)->toMatchArray(['reason' => 'size_removed'])
        ->and($audit->old_values)->toEqual(['partition' => [SG_LOW, SG_HIGH], 'removed_sizes' => ['44'], 'deleted_prices' => []])
        ->and($audit->new_values)->toEqual(['partition' => [SG_LOW, ['38', '40', '42']]])
        ->and(AuditLog::query()->where('action', AuditAction::ProductAttributesUpdated->value)->count())->toBe(1);
});

it('DEC-COT-48 deletes a group left empty with its prices and audits the previous values', function () {
    $fx = sgFixture();
    [$main, $last] = sgStore($fx, [[...SG_LOW, '38', '40', '42'], ['44']]);
    $kept = sgPrice($fx, 'Detal', '40.00', $main);
    sgPrice($fx, 'Detal', '44.00', $last);
    sgPrice($fx, 'Intermedio', '41.00', $last);

    test()->actingAs(sgEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", sgStructure($fx, [...SG_LOW, '38', '40', '42']))
        ->assertRedirect();

    $audit = sgAudit()->sole();

    expect(SizeGroup::query()->pluck('id')->all())->toBe([$main->id])
        ->and(CombinationPrice::query()->pluck('id')->all())->toBe([$kept->id])
        ->and($audit->old_values['removed_sizes'])->toBe(['44'])
        ->and($audit->old_values['deleted_prices'])->toEqual([
            ['code' => '184', 'size_group' => '44', 'tier' => 'Detal', 'price' => '44.00'],
            ['code' => '184', 'size_group' => '44', 'tier' => 'Intermedio', 'price' => '41.00'],
        ])
        ->and($audit->new_values)->toEqual(['partition' => [[...SG_LOW, '38', '40', '42']]]);
});

it('DEC-COT-48 cleans every combination of the product that held the removed size, one audit each', function () {
    $fx = sgFixture();
    $second = Combination::factory()->for($fx['product'])->create();
    CatalogCode::factory()->create(['combination_id' => $second->id, 'code' => '184-2']);
    $restricted = Combination::factory()->for($fx['product'])->create();
    CatalogCode::factory()->create(['combination_id' => $restricted->id, 'code' => '184-3']);

    foreach (SG_LOW as $name) {
        $restricted->values()->attach($fx['sizes'][$name], ['catalog_attribute_id' => $fx['size']->id]);
    }

    sgStore($fx, [SG_LOW, SG_HIGH]);
    sgStore($fx, [SG_LOW, SG_HIGH], $second);
    sgStore($fx, [SG_LOW], $restricted);

    test()->actingAs(sgEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", sgStructure($fx, [...SG_LOW, '38', '40', '42']))
        ->assertRedirect();

    expect(sgAudit()->pluck('entity_id')->all())->toBe([$fx['combination']->id, $second->id])
        ->and($restricted->sizeGroups()->sole()->sizes)->toHaveCount(5);
});

it('DEC-COT-48 takes the sizes out of the groups when a combination restriction is narrowed, deleting the groups left empty', function () {
    $fx = sgFixture();
    [$low, $high] = sgStore($fx, [SG_LOW, SG_HIGH]);
    $kept = sgPrice($fx, 'Detal', '40.00', $low);
    sgPrice($fx, 'Detal', '44.00', $high);

    test()->actingAs(sgEditor())
        ->putJson("/products/{$fx['product']->id}/combinations/{$fx['combination']->id}", [
            'code' => '184',
            'axes' => [],
            'restrictions' => [$fx['size']->id => sgIds($fx, SG_LOW)],
        ])
        ->assertRedirect();

    $audit = sgAudit()->sole();

    expect(SizeGroup::query()->pluck('id')->all())->toBe([$low->id])
        ->and(CombinationPrice::query()->pluck('id')->all())->toBe([$kept->id])
        ->and($audit->entity_id)->toBe($fx['combination']->id)
        ->and($audit->context)->toMatchArray(['reason' => 'size_removed'])
        ->and($audit->old_values['removed_sizes'])->toBe(SG_HIGH)
        ->and($audit->old_values['deleted_prices'])->toEqual([['code' => '184', 'size_group' => '38–44', 'tier' => 'Detal', 'price' => '44.00']])
        ->and($audit->new_values)->toEqual(['partition' => [SG_LOW]])
        ->and(AuditLog::query()->where('action', AuditAction::CombinationUpdated->value)->count())->toBe(1);
});

it('DEC-COT-48 leaves the groups alone when an edit removes no size', function () {
    $fx = sgFixture();

    foreach (SG_LOW as $name) {
        $fx['combination']->values()->attach($fx['sizes'][$name], ['catalog_attribute_id' => $fx['size']->id]);
    }

    [$low] = sgStore($fx, [SG_LOW]);
    sgPrice($fx, 'Detal', '40.00', $low);
    $before = sgState();

    // Widening the restriction admits more sizes (they wait for a group, COT-004) and removes none.
    test()->actingAs(sgEditor())
        ->putJson("/products/{$fx['product']->id}/combinations/{$fx['combination']->id}", ['code' => '184', 'axes' => [], 'restrictions' => []])
        ->assertRedirect();
    test()->actingAs(sgEditor())
        ->putJson("/products/{$fx['product']->id}/attributes", sgStructure($fx, [...SG_LOW, ...SG_HIGH]))
        ->assertRedirect();

    expect(sgState())->toEqual($before)
        ->and(sgAudit())->toHaveCount(0);
});

// --- Authorization and routes --------------------------------------------------------------------

it('E-07 answers 403, audited, to a user without pricing.manage on the preview and on the PUT, and changes nothing', function () {
    $fx = sgFixture();
    sgPrice($fx, 'Detal', '40.00');
    $before = sgState();
    $viewer = userWithPermissions(PermissionName::PricingView);
    $other = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);

    sgPreview($fx, [SG_LOW, SG_HIGH], $viewer)->assertForbidden();
    sgSave($fx, [SG_LOW, SG_HIGH], actor: $viewer)->assertForbidden();
    sgSave($fx, [SG_LOW, SG_HIGH], actor: $other)->assertForbidden();

    expect(sgState())->toEqual($before)
        ->and(sgAudit())->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->count())->toBe(3);
});

it('COT-004 declares the preview and update routes of the size groups', function () {
    $routes = app('router')->getRoutes();
    $preview = $routes->getByName('pricing.size-groups.preview');
    $update = $routes->getByName('pricing.size-groups.update');

    expect($preview->uri())->toBe('pricing/combinations/{combination}/size-groups/preview')
        ->and($preview->methods())->toContain('POST')
        ->and($update->uri())->toBe('pricing/combinations/{combination}/size-groups')
        ->and($update->methods())->toContain('PUT');
});
