<?php

use App\Support\Pricing\SizeGroupPlan;

// DEC-COT-53, DEC-COT-58 (N-9), design Decision 10: what a change of the size groups of a combination
// does to its stored groups and prices. Pure: the plan is computed from plain rows, no database.

/**
 * Sizes 28 to 44 in steps of 2, keyed by value id (1 to 9).
 *
 * @return array<int, string>
 */
function planPalette(): array
{
    return [1 => '28', 2 => '30', 3 => '32', 4 => '34', 5 => '36', 6 => '38', 7 => '40', 8 => '42', 9 => '44'];
}

/**
 * The groups 28–36 (id 10) and 38–44 (id 11) of `184`.
 *
 * @return list<array{id: int, size_ids: list<int>}>
 */
function planStored(): array
{
    return [
        ['id' => 10, 'size_ids' => [1, 2, 3, 4, 5]],
        ['id' => 11, 'size_ids' => [6, 7, 8, 9]],
    ];
}

/**
 * One loaded price, as the Action maps it from `CombinationPrice` with its tier.
 *
 * @return array{id: int, size_group_id: ?int, tier: string, tier_order: int, price: string, updated_at: string}
 */
function planPrice(int $id, ?int $groupId, string $tier, int $tierOrder, string $price, string $updatedAt = '2026-10-13 10:00:00'): array
{
    return ['id' => $id, 'size_group_id' => $groupId, 'tier' => $tier, 'tier_order' => $tierOrder, 'price' => $price, 'updated_at' => $updatedAt];
}

/**
 * Detal and Intermedio prices of each group of the stored 28–36 / 38–44 partition.
 *
 * @return list<array{id: int, size_group_id: ?int, tier: string, tier_order: int, price: string, updated_at: string}>
 */
function planGroupedPrices(): array
{
    return [
        planPrice(1, 10, 'Detal', 1, '40.00'),
        planPrice(2, 10, 'Intermedio', 2, '36.00'),
        planPrice(3, 11, 'Detal', 1, '44.00'),
        planPrice(4, 11, 'Intermedio', 2, '40.00'),
    ];
}

it('DEC-COT-53 keeps a stored group whose set equals a proposed one, whatever the order of the sizes', function () {
    $plan = SizeGroupPlan::diff(planStored(), [[9, 8, 7, 6], [5, 4, 3, 2, 1]], [], planGroupedPrices(), planPalette());

    expect($plan->keptGroups())->toBe([0 => 11, 1 => 10])
        ->and($plan->droppedGroupIds())->toBe([])
        ->and($plan->newGroupIndexes())->toBe([])
        ->and($plan->deletedPriceIds())->toBe([])
        ->and($plan->requiresConfirmation())->toBeFalse()
        ->and($plan->deletedPrices())->toBe([])
        ->and($plan->confirmToken())->toBeNull();
});

it('DEC-COT-58 keeps the group and its prices when a size that was in no group is assigned to it', function () {
    // 36 (id 5) was newly admitted and had no group; 28–32 (id 10) and 38–44 (id 11) already had prices.
    $stored = [['id' => 10, 'size_ids' => [1, 2, 3, 4]], ['id' => 11, 'size_ids' => [6, 7, 8, 9]]];
    $prices = planGroupedPrices();

    $plan = SizeGroupPlan::diff($stored, [[1, 2, 3, 4, 5], [6, 7, 8, 9]], [5], $prices, planPalette());

    expect($plan->keptGroups())->toBe([0 => 10, 1 => 11])
        ->and($plan->droppedGroupIds())->toBe([])
        ->and($plan->deletedPriceIds())->toBe([])
        ->and($plan->requiresConfirmation())->toBeFalse()
        ->and($plan->confirmToken())->toBeNull();

    // Triangulation: two ungrouped sizes assigned at once, to two different groups.
    $stored = [['id' => 10, 'size_ids' => [1, 2, 3]], ['id' => 11, 'size_ids' => [6, 7, 8]]];
    $plan = SizeGroupPlan::diff($stored, [[1, 2, 3, 4, 5], [6, 7, 8, 9]], [4, 5, 9], $prices, planPalette());

    expect($plan->keptGroups())->toBe([0 => 10, 1 => 11])
        ->and($plan->requiresConfirmation())->toBeFalse();
});

it('DEC-COT-58 moving a size between groups drops both groups and lists the prices of both', function () {
    // 36 moves from 28–36 to the group of 38–44: both groups are affected even though their sets overlap the old ones.
    $plan = SizeGroupPlan::diff(planStored(), [[1, 2, 3, 4], [5, 6, 7, 8, 9]], [], planGroupedPrices(), planPalette());

    expect($plan->keptGroups())->toBe([])
        ->and($plan->droppedGroupIds())->toBe([10, 11])
        ->and($plan->newGroupIndexes())->toBe([0, 1])
        ->and($plan->deletedPriceIds())->toBe([1, 2, 3, 4])
        ->and($plan->requiresConfirmation())->toBeTrue();
});

it('DEC-COT-58 a size taken from a group is a reorganization even when another size joins in the same save', function () {
    // 36 leaves 28–36 for 38–44 while the ungrouped 34 stays out: nothing is a pure assignment of an ungrouped size.
    $stored = [['id' => 10, 'size_ids' => [1, 2, 3, 5]], ['id' => 11, 'size_ids' => [6, 7, 8, 9]]];
    $plan = SizeGroupPlan::diff($stored, [[1, 2, 3, 4], [5, 6, 7, 8, 9]], [4], planGroupedPrices(), planPalette());

    expect($plan->droppedGroupIds())->toBe([10, 11])
        ->and($plan->deletedPriceIds())->toBe([1, 2, 3, 4]);
});

it('DEC-COT-53 merging two groups drops both and creates a single new one', function () {
    $plan = SizeGroupPlan::diff(planStored(), [[1, 2, 3, 4, 5, 6, 7, 8, 9]], [], planGroupedPrices(), planPalette());

    expect($plan->keptGroups())->toBe([])
        ->and($plan->droppedGroupIds())->toBe([10, 11])
        ->and($plan->newGroupIndexes())->toBe([0])
        ->and($plan->deletedPriceIds())->toBe([1, 2, 3, 4])
        ->and($plan->requiresConfirmation())->toBeTrue();
});

it('DEC-COT-53 splitting a group drops it and creates the two new ones, leaving the untouched group alone', function () {
    $stored = [['id' => 10, 'size_ids' => [1, 2, 3, 4]], ['id' => 11, 'size_ids' => [5, 6, 7, 8, 9]]];

    $plan = SizeGroupPlan::diff($stored, [[1, 2, 3, 4], [5, 6], [7, 8, 9]], [], planGroupedPrices(), planPalette());

    expect($plan->keptGroups())->toBe([0 => 10])
        ->and($plan->droppedGroupIds())->toBe([11])
        ->and($plan->newGroupIndexes())->toBe([1, 2])
        ->and($plan->deletedPriceIds())->toBe([3, 4]);
});

it('DEC-COT-53 going from ungrouped to grouped lists the prices without group', function () {
    $prices = [
        planPrice(1, null, 'Detal', 1, '40.00'),
        planPrice(2, null, 'Intermedio', 2, '36.00'),
    ];

    $plan = SizeGroupPlan::diff([], [[1, 2, 3, 4, 5], [6, 7, 8, 9]], [1, 2, 3, 4, 5, 6, 7, 8, 9], $prices, planPalette());

    expect($plan->keptGroups())->toBe([])
        ->and($plan->newGroupIndexes())->toBe([0, 1])
        ->and($plan->deletedPriceIds())->toBe([1, 2])
        ->and($plan->deletedPrices())->toBe([
            ['group' => null, 'tier' => 'Detal', 'price' => '40.00'],
            ['group' => null, 'tier' => 'Intermedio', 'price' => '36.00'],
        ])
        ->and($plan->requiresConfirmation())->toBeTrue();
});

it('DEC-COT-53 going from ungrouped to grouped with no prices needs no confirmation', function () {
    $plan = SizeGroupPlan::diff([], [[1, 2, 3, 4, 5], [6, 7, 8, 9]], [1, 2, 3, 4, 5, 6, 7, 8, 9], [], planPalette());

    expect($plan->newGroupIndexes())->toBe([0, 1])
        ->and($plan->requiresConfirmation())->toBeFalse()
        ->and($plan->confirmToken())->toBeNull();
});

it('DEC-COT-53 removing the groups lists every price of every group', function () {
    $plan = SizeGroupPlan::diff(planStored(), [], [], planGroupedPrices(), planPalette());

    expect($plan->keptGroups())->toBe([])
        ->and($plan->droppedGroupIds())->toBe([10, 11])
        ->and($plan->newGroupIndexes())->toBe([])
        ->and($plan->deletedPriceIds())->toBe([1, 2, 3, 4])
        ->and($plan->requiresConfirmation())->toBeTrue();
});

it('DEC-COT-53 removing the groups does not list the prices without group of an ungrouped combination', function () {
    // An ungrouped price cannot coexist with groups, but the plan only deletes what the change makes obsolete.
    $plan = SizeGroupPlan::diff([], [], [1, 2, 3], [planPrice(1, null, 'Detal', 1, '40.00')], planPalette());

    expect($plan->deletedPriceIds())->toBe([])
        ->and($plan->requiresConfirmation())->toBeFalse();
});

it('DEC-COT-53 keeps the prices of a kept group when another group is dropped', function () {
    $stored = [['id' => 10, 'size_ids' => [1, 2, 3, 4]], ['id' => 11, 'size_ids' => [5, 6, 7, 8, 9]]];

    $plan = SizeGroupPlan::diff($stored, [[1, 2, 3, 4], [5, 6, 7, 8, 9]], [], planGroupedPrices(), planPalette());
    expect($plan->deletedPriceIds())->toBe([]);

    $plan = SizeGroupPlan::diff($stored, [[1, 2, 3, 4], [5, 6, 7], [8, 9]], [], planGroupedPrices(), planPalette());
    expect($plan->deletedPriceIds())->toBe([3, 4]);
});

it('DEC-COT-58 a stored group is claimed by only one proposed group', function () {
    // Overlapping proposals are rejected by SizeGroupRules, but the plan stays safe on its own: the ungrouped
    // 32 and 34 could each join {28, 30}; the first proposed group takes it, the other is new.
    $stored = [['id' => 10, 'size_ids' => [1, 2]]];

    $plan = SizeGroupPlan::diff($stored, [[1, 2, 3], [1, 2, 4]], [3, 4], [], planPalette());

    expect($plan->keptGroups())->toBe([0 => 10])
        ->and($plan->newGroupIndexes())->toBe([1]);
});

it('DEC-COT-58 an empty stored group is never kept: a group made only of ungrouped sizes is new', function () {
    $stored = [['id' => 10, 'size_ids' => []]];
    $prices = [planPrice(1, 10, 'Detal', 1, '40.00')];

    $plan = SizeGroupPlan::diff($stored, [[3]], [3], $prices, planPalette());

    expect($plan->keptGroups())->toBe([])
        ->and($plan->droppedGroupIds())->toBe([10])
        ->and($plan->newGroupIndexes())->toBe([0])
        ->and($plan->deletedPriceIds())->toBe([1]);
});

it('DEC-COT-53 a group is not kept when the extra size belongs to another group, not to the ungrouped ones', function () {
    // 34 (id 4) is ungrouped but 36 (id 5) is not: only the pure assignment keeps the group.
    $stored = [['id' => 10, 'size_ids' => [1, 2, 3]], ['id' => 11, 'size_ids' => [5, 6, 7, 8, 9]]];

    $plan = SizeGroupPlan::diff($stored, [[1, 2, 3, 4, 5], [6, 7, 8, 9]], [4], planGroupedPrices(), planPalette());

    expect($plan->keptGroups())->toBe([])
        ->and($plan->droppedGroupIds())->toBe([10, 11]);
});

it('DEC-COT-53 lists the deleted prices by group order, then tier order, with the group as its label', function () {
    // Given out of order on purpose: group 11 first and the tiers of each group reversed.
    $prices = [
        planPrice(4, 11, 'Intermedio', 2, '40.00'),
        planPrice(3, 11, 'Detal', 1, '44.00'),
        planPrice(2, 10, 'Intermedio', 2, '36.00'),
        planPrice(1, 10, 'Detal', 1, '40.00'),
    ];

    $plan = SizeGroupPlan::diff(planStored(), [[1, 2, 3, 4, 5, 6, 7, 8, 9]], [], $prices, planPalette());

    expect($plan->deletedPrices())->toBe([
        ['group' => '28–36', 'tier' => 'Detal', 'price' => '40.00'],
        ['group' => '28–36', 'tier' => 'Intermedio', 'price' => '36.00'],
        ['group' => '38–44', 'tier' => 'Detal', 'price' => '44.00'],
        ['group' => '38–44', 'tier' => 'Intermedio', 'price' => '40.00'],
    ])->and($plan->deletedPriceIds())->toBe([1, 2, 3, 4]);
});

it('DEC-COT-53 puts the prices without group before the ones of the groups', function () {
    $prices = [
        planPrice(1, 10, 'Detal', 1, '40.00'),
        planPrice(2, null, 'Detal', 1, '39.00'),
    ];

    $plan = SizeGroupPlan::diff(planStored(), [[1, 2, 3, 4, 5, 6, 7, 8, 9]], [], $prices, planPalette());

    // Proposed has groups, so the null price is obsolete too; it is listed first as "Sin grupo".
    expect($plan->deletedPrices())->toBe([
        ['group' => null, 'tier' => 'Detal', 'price' => '39.00'],
        ['group' => '28–36', 'tier' => 'Detal', 'price' => '40.00'],
    ]);
});

it('DEC-COT-53 labels a dropped group by the runs of its sizes', function () {
    $stored = [['id' => 10, 'size_ids' => [1, 2, 3, 5]]];
    $prices = [planPrice(1, 10, 'Detal', 1, '40.00')];

    $plan = SizeGroupPlan::diff($stored, [], [], $prices, planPalette());

    expect($plan->deletedPrices())->toBe([['group' => '28–32, 36', 'tier' => 'Detal', 'price' => '40.00']]);
});

it('DEC-COT-53 the confirm token is a sha256 of the deleted prices and is null when nothing is deleted', function () {
    $merge = SizeGroupPlan::diff(planStored(), [[1, 2, 3, 4, 5, 6, 7, 8, 9]], [], planGroupedPrices(), planPalette());
    $same = SizeGroupPlan::diff(planStored(), [[1, 2, 3, 4, 5], [6, 7, 8, 9]], [], planGroupedPrices(), planPalette());

    expect($merge->confirmToken())->toBeString()->toMatch('/^[0-9a-f]{64}$/')
        ->and($same->confirmToken())->toBeNull();
});

it('DEC-COT-53 the confirm token does not depend on the order the prices are given in', function () {
    $prices = planGroupedPrices();

    $plan = SizeGroupPlan::diff(planStored(), [], [], $prices, planPalette());
    $shuffled = SizeGroupPlan::diff(planStored(), [], [], array_reverse($prices), planPalette());

    expect($shuffled->confirmToken())->toBe($plan->confirmToken());
});

it('DEC-COT-53 the confirm token changes when a deleted price value or its updated_at changes', function () {
    $base = SizeGroupPlan::diff(planStored(), [], [], planGroupedPrices(), planPalette())->confirmToken();

    $valueChanged = planGroupedPrices();
    $valueChanged[0]['price'] = '41.00';

    $touched = planGroupedPrices();
    $touched[2]['updated_at'] = '2026-10-13 10:00:01';

    expect(SizeGroupPlan::diff(planStored(), [], [], $valueChanged, planPalette())->confirmToken())->not->toBe($base)
        ->and(SizeGroupPlan::diff(planStored(), [], [], $touched, planPalette())->confirmToken())->not->toBe($base);
});

it('DEC-COT-53 the confirm token changes when a price is added to or missing from the deleted set', function () {
    $prices = planGroupedPrices();
    $base = SizeGroupPlan::diff(planStored(), [], [], $prices, planPalette())->confirmToken();

    $extra = [...$prices, planPrice(5, 11, 'Mayor', 3, '38.00')];
    $fewer = array_slice($prices, 0, 3);

    expect(SizeGroupPlan::diff(planStored(), [], [], $extra, planPalette())->confirmToken())->not->toBe($base)
        ->and(SizeGroupPlan::diff(planStored(), [], [], $fewer, planPalette())->confirmToken())->not->toBe($base);
});

it('DEC-COT-53 the confirm token ignores a price that the change keeps', function () {
    // The price of the kept group changes after the preview; the deleted ones are the same, so the token holds.
    $stored = [['id' => 10, 'size_ids' => [1, 2, 3, 4]], ['id' => 11, 'size_ids' => [5, 6, 7, 8, 9]]];
    $proposed = [[1, 2, 3, 4], [5, 6, 7], [8, 9]];

    $base = SizeGroupPlan::diff($stored, $proposed, [], planGroupedPrices(), planPalette())->confirmToken();

    $keptGroupEdited = planGroupedPrices();
    $keptGroupEdited[0]['price'] = '99.00';

    expect(SizeGroupPlan::diff($stored, $proposed, [], $keptGroupEdited, planPalette())->confirmToken())->toBe($base);
});
