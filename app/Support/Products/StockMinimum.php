<?php

namespace App\Support\Products;

use App\Models\Combination;
use App\Models\Product;
use App\Models\StockMinimumOverride;
use Illuminate\Support\Facades\DB;

/**
 * Minimum stock of an article (design Decision 13, PRD-009, DEC-PRD-46). An article is a
 * combination plus, when the product declares the size-use attribute as an order attribute, one size
 * value. Its minimum is its own override when it has one and the product default otherwise (E-65).
 * Overrides are configuration: they never touch quantities.
 */
final class StockMinimum
{
    /**
     * Minimum of the article, or null when the product has no minimum (it is not in
     * `stock_with_minimum`).
     */
    public static function for(Combination $combination, ?int $sizeValueId): ?int
    {
        $own = StockMinimumOverride::query()
            ->where('combination_id', $combination->id)
            ->where('size_key', $sizeValueId ?? 0)
            ->value('minimum');

        return $own !== null ? (int) $own : $combination->product->min_stock_default;
    }

    /**
     * Audit form of the overrides of the product: code, size name and minimum, sorted by code and
     * size. `$sizeValueIds` limits the list to the overrides of those sizes.
     *
     * @param  list<int>|null  $sizeValueIds
     * @return list<array{code: string, size: string|null, minimum: int}>
     */
    public static function snapshot(Product $product, ?array $sizeValueIds = null): array
    {
        $rows = DB::table('stock_minimum_overrides')
            ->join('combinations', 'combinations.id', '=', 'stock_minimum_overrides.combination_id')
            ->leftJoin('catalog_codes', 'catalog_codes.combination_id', '=', 'combinations.id')
            ->leftJoin('attribute_values', 'attribute_values.id', '=', 'stock_minimum_overrides.size_value_id')
            ->where('combinations.product_id', $product->id)
            ->when($sizeValueIds !== null, fn ($query) => $query->whereIn('stock_minimum_overrides.size_value_id', $sizeValueIds))
            ->orderBy('catalog_codes.code')
            ->orderBy('attribute_values.name')
            ->orderBy('stock_minimum_overrides.id')
            ->get(['catalog_codes.code', 'attribute_values.name as size', 'stock_minimum_overrides.minimum']);

        return array_values($rows->map(fn (object $row): array => [
            'code' => (string) $row->code,
            'size' => $row->size === null ? null : (string) $row->size,
            'minimum' => (int) $row->minimum,
        ])->all());
    }

    /**
     * Deletes the overrides of the product, or only those of the given sizes, and returns them in
     * audit form. Callers hold the product lock and a transaction.
     *
     * @param  list<int>|null  $sizeValueIds
     * @return list<array{code: string, size: string|null, minimum: int}>
     */
    public static function deleteOverrides(Product $product, ?array $sizeValueIds = null): array
    {
        $deleted = self::snapshot($product, $sizeValueIds);

        if ($deleted !== []) {
            StockMinimumOverride::query()
                ->whereIn('combination_id', Combination::query()->where('product_id', $product->id)->select('id'))
                ->when($sizeValueIds !== null, fn ($query) => $query->whereIn('size_value_id', $sizeValueIds))
                ->delete();
        }

        return $deleted;
    }
}
