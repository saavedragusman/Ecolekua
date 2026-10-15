<?php

namespace App\Support\Quotations\Calculation;

use App\Support\Pricing\AdmittedSizes;
use App\Support\Pricing\SizeGroupLabel;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The prices one calculation reads, loaded once in a constant number of queries whatever the number of lines
 * and rows (design Decisions 15 and 16): the combination prices of every resolved combination, their size
 * groups with their sizes and labels, and the item prices of the components of the lines' combos.
 */
final class PriceBook
{
    /**
     * @param  array<string, BigDecimal>  $prices  "combination:tier:group" (group 0 for no group) => unit price
     * @param  array<int, array<int, int>>  $groupOfSize  combination id => size value id => size group id
     * @param  array<int, string>  $groupLabels  size group id => label
     * @param  array<int, BigDecimal>  $itemPrices  combo component id => item price
     */
    private function __construct(
        private readonly array $prices,
        private readonly array $groupOfSize,
        private readonly array $groupLabels,
        private readonly array $itemPrices,
    ) {}

    /**
     * @param  list<int>  $combinationIds
     * @param  list<int>  $comboIds
     */
    public static function load(array $combinationIds, array $comboIds): self
    {
        $combinationIds = array_values(array_unique($combinationIds));
        $comboIds = array_values(array_unique($comboIds));

        $prices = [];
        $groupOfSize = [];
        $groupLabels = [];
        $itemPrices = [];

        if ($combinationIds !== []) {
            $rows = DB::table('combination_prices')
                ->whereIn('combination_id', $combinationIds)
                ->get(['combination_id', 'price_tier_id', 'size_group_id', 'unit_price']);

            foreach ($rows as $row) {
                $prices[self::key((int) $row->combination_id, (int) $row->price_tier_id, $row->size_group_id === null ? null : (int) $row->size_group_id)] = BigDecimal::of((string) $row->unit_price);
            }

            $members = DB::table('size_group_values')
                ->whereIn('combination_id', $combinationIds)
                ->get(['size_group_id', 'combination_id', 'size_value_id']);

            $sizesOfGroup = [];

            foreach ($members as $member) {
                $groupOfSize[(int) $member->combination_id][(int) $member->size_value_id] = (int) $member->size_group_id;
                $sizesOfGroup[(int) $member->combination_id][(int) $member->size_group_id][] = (int) $member->size_value_id;
            }

            $palettes = $sizesOfGroup === [] ? [] : AdmittedSizes::forCombinations(array_keys($sizesOfGroup));

            foreach ($sizesOfGroup as $combinationId => $groups) {
                foreach ($groups as $groupId => $sizeIds) {
                    $palette = $palettes[$combinationId] ?? [];
                    $known = array_values(array_filter($sizeIds, fn (int $id): bool => isset($palette[$id])));
                    $groupLabels[$groupId] = $known === [] ? '' : SizeGroupLabel::of($known, $palette);
                }
            }
        }

        if ($comboIds !== []) {
            $rows = DB::table('combo_item_prices')
                ->join('combo_components', 'combo_components.id', '=', 'combo_item_prices.combo_component_id')
                ->whereIn('combo_components.combo_id', $comboIds)
                ->get(['combo_item_prices.combo_component_id', 'combo_item_prices.unit_price']);

            foreach ($rows as $row) {
                $itemPrices[(int) $row->combo_component_id] = BigDecimal::of((string) $row->unit_price);
            }
        }

        return new self($prices, $groupOfSize, $groupLabels, $itemPrices);
    }

    public function hasSizeGroups(int $combinationId): bool
    {
        return isset($this->groupOfSize[$combinationId]);
    }

    /** The size group of a size in a combination with groups, or null when the size is in none (COT-025). */
    public function groupOf(int $combinationId, ?int $sizeValueId): ?int
    {
        return $sizeValueId === null ? null : ($this->groupOfSize[$combinationId][$sizeValueId] ?? null);
    }

    public function groupLabel(int $groupId): ?string
    {
        return $this->groupLabels[$groupId] ?? null;
    }

    public function price(int $combinationId, int $tierId, ?int $groupId): ?BigDecimal
    {
        return $this->prices[self::key($combinationId, $tierId, $groupId)] ?? null;
    }

    public function itemPrice(int $componentId): ?BigDecimal
    {
        return $this->itemPrices[$componentId] ?? null;
    }

    private static function key(int $combinationId, int $tierId, ?int $groupId): string
    {
        return $combinationId.':'.$tierId.':'.($groupId ?? 0);
    }
}
