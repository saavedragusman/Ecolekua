<?php

namespace App\Support\Pricing;

use App\Models\CombinationPrice;
use App\Models\SizeGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The loaded prices that a scheme change would delete (DEC-COT-23, DEC-COT-57, design Decision 8) and
 * the answer given when the change was not confirmed. One row per price, with the strings the audit
 * keeps under `deleted_prices` (`{code, size_group, tier, price}`, money as a canonical string).
 *
 * `size_group` is the derived label of the group (`SizeGroupLabel`, COT-004); an ungrouped price has `null`.
 */
final class AffectedPrices
{
    /**
     * Prices registered in any of the tiers, in the order they were loaded.
     *
     * @param  list<int>  $tierIds
     * @return list<array{id: int, product_id: int, code: ?string, size_group: ?string, tier: string, price: string}>
     */
    public static function forTiers(array $tierIds): array
    {
        if ($tierIds === []) {
            return [];
        }

        return self::rows(CombinationPrice::query()->whereIn('price_tier_id', $tierIds));
    }

    /**
     * Prices of every combination, tier and size group of the products, in the order they were loaded
     * (a product leaving its scheme loses all of them, COT-002, DEC-COT-23).
     *
     * @param  list<int>  $productIds
     * @return list<array{id: int, product_id: int, code: ?string, size_group: ?string, tier: string, price: string}>
     */
    public static function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return self::rows(CombinationPrice::query()->whereHas('combination', fn ($combinations) => $combinations->whereIn('product_id', $productIds)));
    }

    /**
     * @param  Builder<CombinationPrice>  $query
     * @return list<array{id: int, product_id: int, code: ?string, size_group: ?string, tier: string, price: string}>
     */
    private static function rows(Builder $query): array
    {
        $prices = $query
            ->with(['combination.catalogCode', 'tier', 'sizeGroup.sizes'])
            ->orderBy('id')
            ->get();

        $palettes = AdmittedSizes::forCombinations(array_values(array_unique(array_map(fn (CombinationPrice $price): int => $price->combination_id, $prices->all()))));
        $rows = [];

        foreach ($prices as $price) {
            $rows[] = [
                'id' => $price->id,
                'product_id' => $price->combination->product_id,
                'code' => $price->combination->code,
                'size_group' => self::sizeGroupLabel($price->sizeGroup, $palettes[$price->combination_id] ?? []),
                'tier' => $price->tier->label,
                'price' => Money::toStorage($price->unit_price, 2),
            ];
        }

        return $rows;
    }

    /**
     * How the audits name a size group: its derived label, such as "28–36" (`null` for an ungrouped
     * price). The group needs its `sizes` loaded; `$palette` is what its combination admits
     * (`AdmittedSizes`).
     *
     * @param  array<int, string>  $palette  size value id => name, in size order
     */
    public static function sizeGroupLabel(?SizeGroup $group, array $palette): ?string
    {
        return $group === null ? null : SizeGroupLabel::of(array_values(array_map(intval(...), $group->sizes->modelKeys())), $palette);
    }

    /**
     * Product ids of the rows, in ascending order (the lock order of design Decision 10).
     *
     * @param  list<array{product_id: int}>  $rows
     * @return list<int>
     */
    public static function productIds(array $rows): array
    {
        $ids = array_values(array_unique(array_column($rows, 'product_id')));
        sort($ids);

        return $ids;
    }

    /**
     * The rows as the audit keeps them, without the internal ids.
     *
     * @param  list<array{id: int, product_id: int, code: ?string, size_group: ?string, tier: string, price: string}>  $rows
     * @return list<array{code: ?string, size_group: ?string, tier: string, price: string}>
     */
    public static function forAudit(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'code' => $row['code'],
            'size_group' => $row['size_group'],
            'tier' => $row['tier'],
            'price' => $row['price'],
        ], $rows);
    }

    /**
     * The rows grouped by product id, in the shape the audit keeps.
     *
     * @param  list<array{id: int, product_id: int, code: ?string, size_group: ?string, tier: string, price: string}>  $rows
     * @return array<int, list<array{code: ?string, size_group: ?string, tier: string, price: string}>>
     */
    public static function auditByProduct(array $rows): array
    {
        $byProduct = [];

        foreach ($rows as $row) {
            $byProduct[$row['product_id']][] = $row;
        }

        return array_map(self::forAudit(...), $byProduct);
    }

    /**
     * The validation error that asks the user to confirm the deletion (the screen opens its
     * `ConfirmDialog` from it). Nothing has been written when it is thrown.
     *
     * @param  list<array{product_id: int}>  $rows
     */
    public static function confirmationRequired(array $rows, string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => __('validation.scheme_prices_removal', [
                'prices' => trans_choice('validation.scheme_price_count', count($rows)),
                'products' => trans_choice('validation.scheme_product_count', count(self::productIds($rows))),
            ]),
        ]);
    }
}
