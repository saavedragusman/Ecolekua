<?php

namespace App\Support\Products;

use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes shared by the combo Actions (design Decision 15): the lock of the component
 * products, the comparison with the stored components and their full replacement. Components are
 * written in the order of the payload.
 */
final class ComboComponents
{
    /**
     * Takes the lock of every component product, in id order. Edits of a product (line, mode,
     * structure) take the same lock, so they serialize with the combo writes that read the product.
     *
     * @param  array<array-key, mixed>  $components  shaped by `ComboRules::rules()`
     */
    public static function lockProducts(array $components): void
    {
        $ids = array_values(array_unique(array_map(fn (mixed $component): int => (int) $component['product_id'], $components)));
        sort($ids);

        if ($ids !== []) {
            Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * Whether the stored components are exactly the given ones, in the same order. An edit that
     * changes nothing keeps its component rows.
     *
     * @param  list<array{product_id: int, quantity: int, values: array<int, list<int>>}>  $components  normalized by `ComboRules::validateComponents()`
     */
    public static function matches(Combo $combo, array $components): bool
    {
        $stored = [];

        foreach ($combo->components()->get() as $component) {
            $values = [];

            $rows = DB::table('combo_component_values')
                ->where('combo_component_id', $component->id)
                ->get(['catalog_attribute_id', 'attribute_value_id']);

            foreach ($rows as $row) {
                $values[(int) $row->catalog_attribute_id][] = (int) $row->attribute_value_id;
            }

            $stored[] = ['product_id' => $component->product_id, 'quantity' => $component->quantity, 'values' => self::canonical($values)];
        }

        $given = array_map(fn (array $component): array => [...$component, 'values' => self::canonical($component['values'])], $components);

        return $stored === $given;
    }

    /**
     * Replaces the components of a combo, with their restrictions, by the given ones.
     *
     * @param  list<array{product_id: int, quantity: int, values: array<int, list<int>>}>  $components  normalized by `ComboRules::validateComponents()`
     */
    public static function replace(Combo $combo, array $components): void
    {
        // The restrictions go with their component (cascade).
        ComboComponent::query()->where('combo_id', $combo->id)->delete();

        foreach ($components as $position => $component) {
            $row = ComboComponent::query()->create([
                'combo_id' => $combo->id,
                'product_id' => $component['product_id'],
                'quantity' => $component['quantity'],
                'sort_order' => $position + 1,
            ]);

            $values = [];

            foreach ($component['values'] as $attributeId => $valueIds) {
                foreach ($valueIds as $valueId) {
                    $values[] = ['combo_component_id' => $row->id, 'catalog_attribute_id' => $attributeId, 'attribute_value_id' => $valueId];
                }
            }

            if ($values !== []) {
                DB::table('combo_component_values')->insert($values);
            }
        }
    }

    /**
     * Sorted attributes and values, so two equal restrictions compare equal whatever their order.
     *
     * @param  array<int, list<int>>  $values
     * @return array<int, list<int>>
     */
    private static function canonical(array $values): array
    {
        ksort($values);

        return array_map(function (array $ids): array {
            sort($ids);

            return $ids;
        }, $values);
    }
}
