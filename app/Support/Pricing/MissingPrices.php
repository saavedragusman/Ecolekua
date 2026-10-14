<?php

namespace App\Support\Pricing;

use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\CatalogStatus;
use App\Enums\PriceTierKind;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The "sin precio" flag of the price list (COT-025, E-26, design Decision 10): for each **active**
 * combination of products that have a scheme, the priced tiers (per size group when the combination
 * has groups) that hold no price and, for a grouped combination, the admitted sizes that belong to
 * no group. Inactive combinations are ignored (COT-003 only marks the active ones), and a product
 * without a scheme has no tiers to miss, so it is not listed here.
 *
 * Two queries whatever the number of products, so the list page does not grow with its rows: one for
 * the empty cells and one for the sizes without a group.
 */
final class MissingPrices
{
    /**
     * Only the combinations that are incomplete appear, ordered by combination id inside each product.
     *
     * - `missing`: one entry per empty cell, by group order (the cell of an ungrouped combination has
     *   `size_group_id` null) and then tier order.
     * - `ungrouped_sizes`: size value id => name of the admitted sizes without a group, in size order.
     *   A combination without groups lists none: its sizes take the ungrouped price.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array{combination_id: int, code: ?string, missing: list<array{size_group_id: ?int, price_tier_id: int, tier: string}>, ungrouped_sizes: array<int, string>}>>
     */
    public static function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var array<int, array{product_id: int, combination_id: int, code: ?string, missing: list<array{size_group_id: ?int, price_tier_id: int, tier: string}>, ungrouped_sizes: array<int, string>}> $byCombination */
        $byCombination = [];

        foreach (self::emptyCells($productIds) as $cell) {
            $id = (int) $cell->combination_id;
            $byCombination[$id] ??= self::entry((int) $cell->product_id, $id, $cell->code);
            $byCombination[$id]['missing'][] = [
                'size_group_id' => $cell->size_group_id === null ? null : (int) $cell->size_group_id,
                'price_tier_id' => (int) $cell->price_tier_id,
                'tier' => (string) $cell->tier,
            ];
        }

        foreach (self::ungroupedSizes($productIds) as $size) {
            $id = (int) $size->combination_id;
            $byCombination[$id] ??= self::entry((int) $size->product_id, $id, $size->code);
            $byCombination[$id]['ungrouped_sizes'][(int) $size->size_value_id] = (string) $size->name;
        }

        ksort($byCombination);

        $byProduct = [];

        foreach ($byCombination as $entry) {
            $byProduct[$entry['product_id']][] = [
                'combination_id' => $entry['combination_id'],
                'code' => $entry['code'],
                'missing' => $entry['missing'],
                'ungrouped_sizes' => $entry['ungrouped_sizes'],
            ];
        }

        return $byProduct;
    }

    /**
     * @return array{product_id: int, combination_id: int, code: ?string, missing: list<array{size_group_id: ?int, price_tier_id: int, tier: string}>, ungrouped_sizes: array<int, string>}
     */
    private static function entry(int $productId, int $combinationId, mixed $code): array
    {
        return [
            'product_id' => $productId,
            'combination_id' => $combinationId,
            'code' => $code === null ? null : (string) $code,
            'missing' => [],
            'ungrouped_sizes' => [],
        ];
    }

    /**
     * Query 1: active combination x priced tier of the product's scheme x (each size group, or none),
     * keeping the cells with no price. The price join compares `size_group_key` (0 for no group), the
     * column the unique cell index is built on.
     *
     * @param  list<int>  $productIds
     * @return array<int, stdClass> columns `product_id`, `combination_id`, `code`, `size_group_id`, `price_tier_id`, `tier`
     */
    private static function emptyCells(array $productIds): array
    {
        return DB::table('combinations as c')
            ->join('products as p', 'p.id', '=', 'c.product_id')
            ->join('price_tiers as t', function (JoinClause $join): void {
                $join->on('t.price_scheme_id', '=', 'p.price_scheme_id')->where('t.kind', '=', PriceTierKind::Priced->value);
            })
            ->leftJoin('catalog_codes as cc', 'cc.combination_id', '=', 'c.id')
            ->leftJoin('size_groups as g', 'g.combination_id', '=', 'c.id')
            ->leftJoin('combination_prices as cp', function (JoinClause $join): void {
                $join->on('cp.combination_id', '=', 'c.id')
                    ->on('cp.price_tier_id', '=', 't.id')
                    ->whereRaw('cp.size_group_key = IFNULL(g.id, 0)');
            })
            ->whereIn('c.product_id', $productIds)
            ->where('c.status', CatalogStatus::Active->value)
            ->whereNull('cp.id')
            ->orderBy('c.id')
            ->orderBy('g.sort_order')
            ->orderBy('g.id')
            ->orderBy('t.sort_order')
            ->orderBy('t.id')
            ->get(['c.product_id', 'c.id as combination_id', 'cc.code', 'g.id as size_group_id', 't.id as price_tier_id', 't.label as tier'])
            ->all();
    }

    /**
     * Query 2: for each active grouped combination, the sizes it admits (its restriction on the size
     * attribute, or every size the product allows when it has none) that no group holds.
     *
     * @param  list<int>  $productIds
     * @return array<int, stdClass> columns `product_id`, `combination_id`, `code`, `size_value_id`, `name`
     */
    private static function ungroupedSizes(array $productIds): array
    {
        return DB::table('combinations as c')
            ->join('products as p', 'p.id', '=', 'c.product_id')
            ->join('product_attributes as pa', function (JoinClause $join): void {
                $join->on('pa.product_id', '=', 'c.product_id')->where('pa.role', '=', AttributeRole::Order->value);
            })
            ->join('catalog_attributes as ca', function (JoinClause $join): void {
                $join->on('ca.id', '=', 'pa.catalog_attribute_id')->where('ca.special_use', '=', AttributeSpecialUse::Size->value);
            })
            ->join('product_attribute_values as pav', 'pav.product_attribute_id', '=', 'pa.id')
            ->join('attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')
            ->leftJoin('catalog_codes as cc', 'cc.combination_id', '=', 'c.id')
            ->whereIn('c.product_id', $productIds)
            ->whereNotNull('p.price_scheme_id')
            ->where('c.status', CatalogStatus::Active->value)
            ->whereExists(fn (Builder $groups) => $groups->from('size_groups as sg')->whereColumn('sg.combination_id', 'c.id')->select(DB::raw(1)))
            ->where(function (Builder $admitted): void {
                $admitted
                    ->whereNotExists(fn (Builder $restriction) => $restriction->from('combination_values as cv')
                        ->whereColumn('cv.combination_id', 'c.id')
                        ->whereColumn('cv.catalog_attribute_id', 'ca.id')
                        ->select(DB::raw(1)))
                    ->orWhereExists(fn (Builder $restriction) => $restriction->from('combination_values as cv')
                        ->whereColumn('cv.combination_id', 'c.id')
                        ->whereColumn('cv.attribute_value_id', 'av.id')
                        ->select(DB::raw(1)));
            })
            ->whereNotExists(fn (Builder $grouped) => $grouped->from('size_group_values as sgv')
                ->whereColumn('sgv.combination_id', 'c.id')
                ->whereColumn('sgv.size_value_id', 'av.id')
                ->select(DB::raw(1)))
            ->orderBy('c.id')
            ->orderBy('av.sort_order')
            ->orderBy('av.id')
            ->get(['c.product_id', 'c.id as combination_id', 'cc.code', 'av.id as size_value_id', 'av.name'])
            ->all();
    }
}
