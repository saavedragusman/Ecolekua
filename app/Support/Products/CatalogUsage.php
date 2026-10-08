<?php

namespace App\Support\Products;

use App\Enums\CatalogStatus;
use App\Models\CatalogAttribute;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Cross-record "who uses this" queries (design Decisions 9, 10 and 11). They only read and return
 * names so the Actions can say what prevents a change (DEC-PRD-51, DEC-PRD-52). The queries for
 * combinations, combos and services are added by the phases that create those tables.
 */
final class CatalogUsage
{
    /**
     * Names of the products that declare the attribute, sorted. With `$activeOnly` only active
     * products count (deactivating an attribute); otherwise any status does (changing its
     * presentation or special use).
     *
     * @return list<string>
     */
    public static function productsDeclaring(CatalogAttribute $attribute, bool $activeOnly = false): array
    {
        $products = Product::query()
            ->whereHas('productAttributes', fn ($query) => $query->where('catalog_attribute_id', $attribute->id))
            ->when($activeOnly, fn ($query) => $query->where('status', CatalogStatus::Active->value))
            ->orderBy('name')
            ->get();

        return array_values(array_map(fn (Product $product): string => $product->name, $products->all()));
    }

    /**
     * Who uses a service product as a customization (DEC-PRD-52): the names of the products that
     * admit it (any status) and the codes of the combinations that include it, both sorted.
     *
     * @return array{products: list<string>, combinations: list<string>}
     */
    public static function productsUsingService(int $serviceProductId): array
    {
        $products = DB::table('product_customizations')
            ->join('products', 'products.id', '=', 'product_customizations.product_id')
            ->where('product_customizations.service_product_id', $serviceProductId)
            ->orderBy('products.name')
            ->pluck('products.name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();

        $combinations = DB::table('combination_customizations')
            ->join('catalog_codes', 'catalog_codes.combination_id', '=', 'combination_customizations.combination_id')
            ->where('combination_customizations.service_product_id', $serviceProductId)
            ->orderBy('catalog_codes.code')
            ->pluck('catalog_codes.code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();

        return ['products' => array_values($products), 'combinations' => array_values($combinations)];
    }

    /**
     * Formats names for an error message: «A», «B».
     *
     * @param  list<string>  $names
     */
    public static function quote(array $names): string
    {
        return implode(', ', array_map(fn (string $name): string => "«{$name}»", $names));
    }
}
