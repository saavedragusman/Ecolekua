<?php

namespace App\Support\Products;

use Illuminate\Validation\ValidationException;

/**
 * The two directions of the SVG layer guard (PRD-020, DEC-PRD-53, design Decisions 9, 10, 11 and 18).
 * Both throw a `ValidationException` that the Actions let reach the caller from inside their locked
 * transaction, before any write.
 */
final class TemplateLayerGuard
{
    private function __construct() {}

    /**
     * Catalog side (E-72): a value or detail location may only be given a layer that every template
     * of the products admitting it has. Callers run it only when the layer is assigned or actually
     * changes; clearing a layer never needs it.
     *
     * @param  'value'|'location'  $kind
     *
     * @throws ValidationException on `svg_layer`, naming the products
     */
    public static function ensureLayerFitsProducts(string $kind, int $id, string $layer): void
    {
        $products = CatalogUsage::productsMissingLayer($kind, $id, $layer);

        if ($products !== []) {
            throw ValidationException::withMessages([
                'svg_layer' => __('validation.template_layer_conflict', ['layer' => $layer, 'products' => CatalogUsage::quote($products)]),
            ]);
        }
    }

    /**
     * Product side (E-40): layers of values or locations the product starts to admit must exist in
     * every template it already has, so the preview never lacks a layer the customer can choose.
     *
     * @param  list<string|null>  $layers  layers of the newly admitted values or locations; nulls are ignored
     * @param  string  $field  the request field the error belongs to
     *
     * @throws ValidationException on `$field`, naming the missing layers
     */
    public static function ensureTemplatesHaveLayers(int $productId, array $layers, string $field): void
    {
        $missing = CatalogUsage::layersMissingFromTemplates($productId, array_values(array_unique(array_filter($layers, is_string(...)))));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                $field => __('validation.template_layer_added', ['layers' => implode(', ', $missing)]),
            ]);
        }
    }
}
