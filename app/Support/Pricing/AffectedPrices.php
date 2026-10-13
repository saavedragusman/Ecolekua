<?php

namespace App\Support\Pricing;

use App\Models\CombinationPrice;
use Illuminate\Validation\ValidationException;

/**
 * The loaded prices that a scheme change would delete (DEC-COT-23, DEC-COT-57, design Decision 8) and
 * the answer given when the change was not confirmed. One row per price, with the strings the audit
 * keeps under `deleted_prices` (`{code, size_group, tier, price}`, money as a canonical string).
 *
 * `size_group` is the sizes of the group joined by ", " until the size group label arrives with the
 * size groups slice (COT-004); an ungrouped price has `null`.
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

        $prices = CombinationPrice::query()
            ->with(['combination.catalogCode', 'tier', 'sizeGroup.sizes'])
            ->whereIn('price_tier_id', $tierIds)
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($prices as $price) {
            $rows[] = [
                'id' => $price->id,
                'product_id' => $price->combination->product_id,
                'code' => $price->combination->code,
                'size_group' => $price->sizeGroup?->sizes->pluck('name')->implode(', '),
                'tier' => $price->tier->label,
                'price' => Money::toStorage($price->unit_price, 2),
            ];
        }

        return $rows;
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
