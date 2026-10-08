<?php

namespace App\Support\Products;

use App\Models\Product;

/**
 * Audit form of the general data of a product (design "Audit payloads"): enum values as strings
 * and the category by id and name, so `products.created` carries every field and `products.updated`
 * the previous and new values of the fields that changed.
 */
final class ProductAudit
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Product $product): array
    {
        return [
            'name' => $product->name,
            'description' => $product->description,
            'product_category_id' => $product->product_category_id,
            'category_name' => $product->category->name,
            'business_line' => $product->business_line->value,
            'supply_mode' => $product->supply_mode->value,
            'min_stock_default' => $product->min_stock_default,
            'allows_custom_color' => $product->allows_custom_color,
            'portal_visible' => $product->portal_visible,
            'status' => $product->status->value,
        ];
    }
}
