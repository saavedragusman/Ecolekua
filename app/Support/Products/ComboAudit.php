<?php

namespace App\Support\Products;

use App\Models\Combo;
use App\Models\ComboComponent;
use Illuminate\Support\Facades\DB;

/**
 * Audit form of a combo (design "Audit payloads"): code, name, portal visibility and the components
 * in display order, each with the product name, the quantity and the values it admits by attribute
 * and value name (sorted, so the comparison does not depend on how the rows were read). So
 * `products.combo_created` carries every field and `products.combo_updated` the previous and new
 * values of the fields that changed.
 */
final class ComboAudit
{
    /**
     * @return array{code: string|null, name: string, portal_visible: bool, components: list<array{product: string, quantity: int, values: array<string, list<string>>}>}
     */
    public static function snapshot(Combo $combo): array
    {
        $combo->load('catalogCode');

        $components = [];

        /** @var ComboComponent $component */
        foreach ($combo->components()->with('product')->get() as $component) {
            $values = [];

            $rows = DB::table('combo_component_values')
                ->join('attribute_values', 'attribute_values.id', '=', 'combo_component_values.attribute_value_id')
                ->join('catalog_attributes', 'catalog_attributes.id', '=', 'combo_component_values.catalog_attribute_id')
                ->where('combo_component_values.combo_component_id', $component->id)
                ->get(['catalog_attributes.name as attribute', 'attribute_values.name as value']);

            foreach ($rows as $row) {
                $values[(string) $row->attribute][] = (string) $row->value;
            }

            ksort($values);

            foreach ($values as &$names) {
                sort($names);
            }

            unset($names);

            $components[] = ['product' => $component->product->name, 'quantity' => $component->quantity, 'values' => $values];
        }

        return [
            'code' => $combo->catalogCode?->code,
            'name' => $combo->name,
            'portal_visible' => $combo->portal_visible,
            'components' => $components,
        ];
    }
}
