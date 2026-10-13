<?php

namespace App\Support\Products;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttribute;

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

    /**
     * Audit form of the structure: attribute name, role and sorted value names, in display order.
     * The attributes must be loaded with `catalogAttribute` and `allowedValues`.
     *
     * @param  iterable<ProductAttribute>  $declaredAttributes
     * @return list<array{attribute: string, role: string, values: list<string>}>
     */
    public static function structure(iterable $declaredAttributes): array
    {
        $structure = [];

        foreach ($declaredAttributes as $declaredAttribute) {
            $names = array_map(fn (AttributeValue $value): string => $value->name, $declaredAttribute->allowedValues->all());
            sort($names);

            $structure[] = [
                'attribute' => $declaredAttribute->catalogAttribute->name,
                'role' => $declaredAttribute->role->value,
                'values' => $names,
            ];
        }

        return $structure;
    }
}
