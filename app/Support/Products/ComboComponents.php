<?php

namespace App\Support\Products;

use App\Actions\Pricing\SyncComboItemPrices;
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
     * The ids the entries carry are not compared: an edit that only names its components changes nothing.
     *
     * @param  list<array{id: ?int, product_id: int, quantity: int, values: array<int, list<int>>}>  $components  normalized by `ComboRules::validateComponents()`
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

        $given = array_map(fn (array $component): array => [
            'product_id' => $component['product_id'],
            'quantity' => $component['quantity'],
            'values' => self::canonical($component['values']),
        ], $components);

        return $stored === $given;
    }

    /**
     * Replaces the components of a combo, with their restrictions, by the given ones, keeping the
     * identity (and so the item price) of every stored component whose product does not change
     * (DEC-COT-44, design Decision 12):
     *
     * 1. an entry whose `id` is a stored component of the same product edits that component in place;
     * 2. an entry without `id` takes the next stored component of its product not kept yet, in stored
     *    order (callers that send no ids: `CreateCombo`, the catalog import, an older client);
     * 3. any other entry is a new component, without price;
     * 4. the stored components not kept are deleted, their price and restrictions with them (cascade).
     *
     * Returns the item prices deleted with step 4, as the pricing audit describes them, so the combo
     * edit can audit them.
     *
     * @param  list<array{id: ?int, product_id: int, quantity: int, values: array<int, list<int>>}>  $components  normalized by `ComboRules::validateComponents()`
     * @return list<array{combo_code: ?string, component: int, product: string, quantity: int, price: string}>
     */
    public static function replace(Combo $combo, array $components): array
    {
        $stored = $combo->components()->with(['product', 'price'])->get()->values();
        $byId = $stored->keyBy('id');
        $targets = [];
        $kept = [];

        foreach ($components as $position => $component) {
            $match = $component['id'] !== null ? $byId->get($component['id']) : null;

            if ($match !== null && $match->product_id === $component['product_id'] && ! isset($kept[$match->id])) {
                $targets[$position] = $match;
                $kept[$match->id] = true;
            }
        }

        foreach ($components as $position => $component) {
            if ($component['id'] !== null) {
                continue;
            }

            $match = $stored->first(fn (ComboComponent $row): bool => ! isset($kept[$row->id]) && $row->product_id === $component['product_id']);

            if ($match !== null) {
                $targets[$position] = $match;
                $kept[$match->id] = true;
            }
        }

        $deletedPrices = [];
        $code = $combo->load('catalogCode')->code;

        foreach ($stored as $index => $row) {
            if (! isset($kept[$row->id]) && $row->price !== null) {
                $deletedPrices[] = SyncComboItemPrices::snapshot($code, $index + 1, $row, $row->price->unit_price);
            }
        }

        ComboComponent::query()->where('combo_id', $combo->id)->whereKeyNot(array_keys($kept))->delete();

        foreach ($components as $position => $component) {
            $attributes = ['quantity' => $component['quantity'], 'sort_order' => $position + 1];

            if (isset($targets[$position])) {
                $row = $targets[$position];
                $row->update($attributes);
                DB::table('combo_component_values')->where('combo_component_id', $row->id)->delete();
            } else {
                $row = ComboComponent::query()->create(['combo_id' => $combo->id, 'product_id' => $component['product_id'], ...$attributes]);
            }

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

        return $deletedPrices;
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
