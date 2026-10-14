<?php

namespace App\Support\Pricing;

use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\SizeGroup;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * What a combination holds today in matters of size groups: the sizes it admits, its stored groups
 * and its loaded prices. The preview and the save read it the same way (design Decision 10), so the
 * confirmation token the user saw is the one the save recomputes; the save reads it under the product
 * lock.
 */
final readonly class SizeGroupState
{
    /**
     * @param  array<int, string>  $palette  admitted size value id => name, in size order
     * @param  list<array{id: int, size_ids: list<int>}>  $groups  stored groups, in their order, sizes in size order
     * @param  list<array{id: int, size_group_id: ?int, tier: string, tier_order: int, price: string, updated_at: string}>  $prices
     */
    private function __construct(
        public array $palette,
        public array $groups,
        public array $prices,
    ) {}

    /**
     * Null when the product of the combination does not declare Talla as an order attribute: such a
     * combination has no size groups (COT-004).
     */
    public static function load(Combination $combination): ?self
    {
        $palette = AdmittedSizes::forCombinations([$combination->id])[$combination->id] ?? null;

        if ($palette === null) {
            return null;
        }

        $groups = [];

        foreach ($combination->sizeGroups()->with('sizes')->get() as $group) {
            $groups[] = ['id' => $group->id, 'size_ids' => self::inPaletteOrder($group, $palette)];
        }

        $prices = [];

        foreach (CombinationPrice::query()->where('combination_id', $combination->id)->with('tier')->orderBy('id')->get() as $price) {
            $prices[] = [
                'id' => $price->id,
                'size_group_id' => $price->size_group_id,
                'tier' => $price->tier->label,
                'tier_order' => $price->tier->sort_order,
                'price' => Money::toStorage($price->unit_price, 2),
                'updated_at' => (string) $price->updated_at?->format('Y-m-d H:i:s'),
            ];
        }

        return new self($palette, $groups, $prices);
    }

    /**
     * The state of a combination that can take groups, once the proposed groups are known to partition
     * the sizes it admits (E-08). The preview and the save both start here.
     *
     * @param  list<list<int>>  $groups
     *
     * @throws ValidationException on `groups` or `groups.{i}`
     */
    public static function loadValidated(Combination $combination, array $groups): self
    {
        $state = self::load($combination);

        if ($state === null) {
            throw ValidationException::withMessages(['groups' => Lang::string('validation.size_groups_not_applicable')]);
        }

        $errors = SizeGroupRules::validate($groups, $state->palette);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $state;
    }

    /**
     * Size ids of each stored group as the names of the palette show them (the partition the audits keep).
     *
     * @return list<list<string>>
     */
    public function partition(): array
    {
        return array_map(fn (array $group): array => array_values(array_intersect_key($this->palette, array_flip($group['size_ids']))), $this->groups);
    }

    /**
     * Admitted sizes that no stored group holds.
     *
     * @return list<int>
     */
    public function ungrouped(): array
    {
        $grouped = array_merge([], ...array_column($this->groups, 'size_ids'));

        return array_values(array_diff(array_keys($this->palette), $grouped));
    }

    /**
     * What the proposed groups would do to the stored groups and prices.
     *
     * @param  list<list<int>>  $proposed
     */
    public function diff(array $proposed): SizeGroupPlan
    {
        return SizeGroupPlan::diff($this->groups, $proposed, $this->ungrouped(), $this->prices, $this->palette);
    }

    /**
     * The proposed groups are the stored ones, same sizes in the same order (nothing to write).
     *
     * @param  list<list<int>>  $proposed
     */
    public function isSameAs(array $proposed): bool
    {
        $normalized = array_map(fn (array $ids): array => array_values(array_intersect(array_keys($this->palette), $ids)), $proposed);

        return $normalized === array_column($this->groups, 'size_ids');
    }

    /**
     * @param  array<int, string>  $palette
     * @return list<int>
     */
    private static function inPaletteOrder(SizeGroup $group, array $palette): array
    {
        return array_values(array_intersect(array_keys($palette), $group->sizes->modelKeys()));
    }
}
