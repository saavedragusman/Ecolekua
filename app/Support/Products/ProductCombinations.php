<?php

namespace App\Support\Products;

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\CatalogStatus;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes shared by the combination Actions (design Decisions 5 and 12): the structure the
 * product declares, the axes of a combination, the overlap lookup against the other active
 * combinations and the sync of their values. Callers hold the product lock.
 */
final class ProductCombinations
{
    /**
     * Attributes the product declares in display order, in the shape `CombinationRules::validate()`
     * and `ProductRules` expect, plus the admitted value ids.
     *
     * @return list<array{attribute_id: int, role: AttributeRole, presentation: AttributePresentation, special_use: AttributeSpecialUse|null, allowed: list<int>}>
     */
    public static function declared(Product $product): array
    {
        $rows = $product->productAttributes()
            ->with(['catalogAttribute', 'allowedValues'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (ProductAttribute $row): array => [
            'attribute_id' => $row->catalog_attribute_id,
            'role' => $row->role,
            'presentation' => $row->catalogAttribute->presentation,
            'special_use' => $row->catalogAttribute->special_use,
            'allowed' => array_values(array_map('intval', $row->allowedValues->modelKeys())),
        ])->all());
    }

    /**
     * Axis values of a combination: its values of the attributes the product declares as axes.
     *
     * @param  list<array{attribute_id: int, role: AttributeRole, presentation: AttributePresentation, special_use: AttributeSpecialUse|null, allowed: list<int>}>  $declared
     * @return array<int, list<int>> attributeId => valueIds
     */
    public static function axesOf(Combination $combination, array $declared): array
    {
        $axisIds = array_column(array_filter($declared, fn (array $attribute): bool => $attribute['role'] === AttributeRole::Axis), 'attribute_id');
        $axes = [];

        $rows = DB::table('combination_values')
            ->where('combination_id', $combination->id)
            ->whereIn('catalog_attribute_id', $axisIds)
            ->get(['catalog_attribute_id', 'attribute_value_id']);

        foreach ($rows as $row) {
            $axes[(int) $row->catalog_attribute_id][] = (int) $row->attribute_value_id;
        }

        return $axes;
    }

    /**
     * Code of the first other active combination of the product that overlaps the axes (DEC-PRD-39),
     * or null. `$exceptId` is the combination being edited or activated.
     *
     * @param  array<int, list<int>>  $axes
     */
    public static function overlappingCode(Product $product, array $axes, ?int $exceptId = null): ?string
    {
        $ids = Combination::query()
            ->where('product_id', $product->id)
            ->where('status', CatalogStatus::Active->value)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $others = array_fill_keys($ids, []);

        $rows = DB::table('combination_values')
            ->whereIn('combination_id', $ids)
            ->get(['combination_id', 'catalog_attribute_id', 'attribute_value_id']);

        foreach ($rows as $row) {
            $others[(int) $row->combination_id][(int) $row->catalog_attribute_id][] = (int) $row->attribute_value_id;
        }

        $overlapping = CombinationOverlap::firstOverlap($axes, $others);

        return $overlapping === null ? null : CatalogCode::query()->where('combination_id', $overlapping)->value('code');
    }

    /**
     * Replaces the axis values and restrictions of a combination.
     *
     * @param  array<int, list<int>>  $axes
     * @param  array<int, list<int>>  $restrictions
     */
    public static function syncValues(Combination $combination, array $axes, array $restrictions): void
    {
        $pivot = [];

        foreach ([$axes, $restrictions] as $map) {
            foreach ($map as $attributeId => $valueIds) {
                foreach ($valueIds as $valueId) {
                    $pivot[$valueId] = ['catalog_attribute_id' => $attributeId];
                }
            }
        }

        $combination->values()->sync($pivot);
    }

    /**
     * Replaces the customizations included in the price of a combination (PRD-008, DEC-PRD-47). The
     * ids were validated as products in mode `service` by `CombinationRules`.
     *
     * @param  array<array-key, mixed>|null  $serviceIds  null clears the set
     */
    public static function syncCustomizations(Combination $combination, ?array $serviceIds): void
    {
        $combination->customizations()->sync(array_values(array_unique(array_map('intval', $serviceIds ?? []))));
    }
}
