<?php

namespace App\Support\Products;

use App\Enums\CatalogStatus;
use App\Models\CatalogAttribute;
use App\Models\Product;

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
     * Formats names for an error message: «A», «B».
     *
     * @param  list<string>  $names
     */
    public static function quote(array $names): string
    {
        return implode(', ', array_map(fn (string $name): string => "«{$name}»", $names));
    }
}
