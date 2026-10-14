<?php

namespace App\Support\Pricing;

use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use Illuminate\Support\Facades\DB;

/**
 * The sizes a combination admits, which is what its size groups must partition (COT-004, design
 * Decision 10). Only a product that declares the attribute with special use `size` as an **order**
 * attribute has them; a combination admits its own restriction on that attribute, or every size the
 * product allows when it has none (the rule `ProductPresenter::stockEditor` and `MissingPrices` read).
 *
 * Callers hold the product lock when the answer feeds a write.
 */
final class AdmittedSizes
{
    /**
     * Size value id => name, in size order, for each combination whose product declares sizes as an
     * order attribute. A combination of any other product has no entry.
     *
     * @param  list<int>  $combinationIds
     * @return array<int, array<int, string>>
     */
    public static function forCombinations(array $combinationIds): array
    {
        if ($combinationIds === []) {
            return [];
        }

        $productByCombination = DB::table('combinations')->whereIn('id', $combinationIds)->pluck('product_id', 'id');

        /** @var array<int, array<int, string>> $allowed */
        $allowed = [];

        $rows = DB::table('product_attributes as pa')
            ->join('catalog_attributes as ca', function ($join): void {
                $join->on('ca.id', '=', 'pa.catalog_attribute_id')->where('ca.special_use', '=', AttributeSpecialUse::Size->value);
            })
            ->join('product_attribute_values as pav', 'pav.product_attribute_id', '=', 'pa.id')
            ->join('attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')
            ->where('pa.role', AttributeRole::Order->value)
            ->whereIn('pa.product_id', $productByCombination->unique()->values()->all())
            ->orderBy('av.sort_order')
            ->orderBy('av.id')
            ->get(['pa.product_id', 'av.id as value_id', 'av.name']);

        foreach ($rows as $row) {
            $allowed[(int) $row->product_id][(int) $row->value_id] = (string) $row->name;
        }

        /** @var array<int, array<int, true>> $restricted */
        $restricted = [];

        $restrictions = DB::table('combination_values as cv')
            ->join('catalog_attributes as ca', function ($join): void {
                $join->on('ca.id', '=', 'cv.catalog_attribute_id')->where('ca.special_use', '=', AttributeSpecialUse::Size->value);
            })
            ->whereIn('cv.combination_id', $combinationIds)
            ->get(['cv.combination_id', 'cv.attribute_value_id']);

        foreach ($restrictions as $row) {
            $restricted[(int) $row->combination_id][(int) $row->attribute_value_id] = true;
        }

        $admitted = [];

        foreach ($productByCombination as $combinationId => $productId) {
            $sizes = $allowed[(int) $productId] ?? null;

            if ($sizes === null) {
                continue;
            }

            $admitted[(int) $combinationId] = isset($restricted[(int) $combinationId]) ? array_intersect_key($sizes, $restricted[(int) $combinationId]) : $sizes;
        }

        return $admitted;
    }
}
