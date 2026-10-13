<?php

namespace App\Support\Products\Selection;

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Loads what the selection engine needs about a product in a constant number of queries, whatever
 * the number of its combinations (design Decision 14, DT-02): at most 8 for a product, one more for
 * the palette and one for the templates once their table exists, all well under the bound of 10. It
 * reads the catalog only: no price, cost or stock table is touched. Rows are read with the query
 * builder and grouped in memory, so no model is hydrated per row.
 */
final class CatalogSnapshotLoader
{
    private const ACTIVE = CatalogStatus::Active->value;

    /**
     * Snapshot of the product, or null when it does not exist. Only its active combinations are
     * loaded; availability of the product, its category, its attributes and its values travels as
     * data (Decision 14).
     */
    public function forProduct(int $productId): ?ProductSnapshot
    {
        return $this->forProducts([$productId])[$productId] ?? null;
    }

    /**
     * Snapshots of several products by product id, in the same constant number of queries as one
     * (the queries take every id at once and the rows are grouped in memory). Ids that do not exist
     * are left out. Used by combos, whose components hold different products (PRD-010).
     *
     * @param  list<int>  $productIds
     * @return array<int, ProductSnapshot>
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));

        if ($productIds === []) {
            return [];
        }

        $products = DB::table('products')
            ->join('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->whereIn('products.id', $productIds)
            ->get([
                'products.id', 'products.name', 'products.status', 'products.supply_mode', 'products.allows_custom_color',
                'product_categories.status as category_status',
            ]);

        $attributes = $this->attributes($productIds);
        $roles = [];
        $allowed = [];
        $fabrics = [];

        foreach ($attributes as $productId => $declared) {
            $allowed[$productId] = [];
            $fabrics[$productId] = [];

            foreach ($declared as $attribute) {
                $roles[$productId][$attribute->id] = $attribute->role;
                $allowed[$productId] = [...$allowed[$productId], ...$attribute->allowed];

                if ($attribute->isFabric()) {
                    $fabrics[$productId] = $attribute->allowed;
                }
            }
        }

        [$combinations, $combinationValueIds] = $this->combinations($productIds, $roles);
        $fabricColors = $this->fabricColors(array_merge([], ...array_values($fabrics)));
        $colorIds = array_merge([], ...array_values($fabricColors));
        $values = $this->values(array_values(array_unique([...array_merge([], ...array_values($allowed)), ...array_merge([], ...array_values($combinationValueIds)), ...$colorIds])));
        $locations = $this->detailLocations($productIds);
        $services = $this->admittedServices($productIds);
        $snapshots = [];

        foreach ($products as $product) {
            $productId = (int) $product->id;
            $declared = $attributes[$productId] ?? [];
            $supplyMode = SupplyMode::from((string) $product->supply_mode);
            $ownFabricColors = array_intersect_key($fabricColors, array_flip($fabrics[$productId] ?? []));
            $ownValueIds = [...($allowed[$productId] ?? []), ...($combinationValueIds[$productId] ?? []), ...array_merge([], ...array_values($ownFabricColors))];

            $snapshots[$productId] = new ProductSnapshot(
                id: $productId,
                name: (string) $product->name,
                active: $product->status === self::ACTIVE,
                categoryActive: $product->category_status === self::ACTIVE,
                supplyMode: $supplyMode,
                admitsCustomColor: $supplyMode === SupplyMode::OnDemand
                    && (bool) $product->allows_custom_color
                    && array_filter($declared, fn (AttributeSnapshot $attribute): bool => $attribute->isColor()) !== [],
                attributes: $declared,
                values: array_intersect_key($values, array_flip($ownValueIds)),
                fabricColors: $ownFabricColors,
                combinations: $combinations[$productId] ?? [],
                detailLocations: $locations[$productId] ?? [],
                customizations: $services[$productId] ?? [],
                // The templates table arrives with the SVG templates (Phase 22); nothing reads them yet.
                templates: [],
            );
        }

        return $snapshots;
    }

    /**
     * Snapshot of a combo with the snapshot and restriction of each component, or null when it does
     * not exist or has no code in the registry. One query for the combo, its components and their
     * restrictions, plus the ones of `forProducts()`, so the cost does not depend on the number of
     * components (PRD-010).
     */
    public function forCombo(int $comboId): ?ComboSnapshot
    {
        $rows = DB::table('combos')
            ->join('catalog_codes', 'catalog_codes.combo_id', '=', 'combos.id')
            ->leftJoin('combo_components', 'combo_components.combo_id', '=', 'combos.id')
            ->leftJoin('combo_component_values', 'combo_component_values.combo_component_id', '=', 'combo_components.id')
            ->where('combos.id', $comboId)
            ->orderBy('combo_components.sort_order')
            ->orderBy('combo_components.id')
            ->get([
                'combos.name', 'combos.status', 'catalog_codes.code', 'combo_components.id as component_id',
                'combo_components.product_id', 'combo_components.quantity',
                'combo_component_values.catalog_attribute_id', 'combo_component_values.attribute_value_id',
            ]);

        if ($rows->isEmpty()) {
            return null;
        }

        $components = [];
        $restrictions = [];

        foreach ($rows as $row) {
            if ($row->component_id === null) {
                continue;
            }

            $components[(int) $row->component_id] ??= $row;

            if ($row->attribute_value_id !== null) {
                $restrictions[(int) $row->component_id][(int) $row->catalog_attribute_id][] = (int) $row->attribute_value_id;
            }
        }

        $snapshots = $this->forProducts(array_map(fn (object $row): int => (int) $row->product_id, array_values($components)));
        $built = [];

        foreach ($components as $id => $row) {
            if (isset($snapshots[(int) $row->product_id])) {
                $built[] = new ComponentSnapshot($id, (int) $row->quantity, $restrictions[$id] ?? [], $snapshots[(int) $row->product_id]);
            }
        }

        $first = $rows->first();

        return new ComboSnapshot($comboId, (string) $first->name, (string) $first->code, $first->status === self::ACTIVE, $built);
    }

    /**
     * Active values of the color attribute: the palette a detail color is chosen from (PRD-007).
     *
     * @return list<ValueSnapshot>
     */
    public function palette(): array
    {
        $rows = DB::table('attribute_values')
            ->join('catalog_attributes', 'catalog_attributes.id', '=', 'attribute_values.catalog_attribute_id')
            ->where('catalog_attributes.presentation', AttributePresentation::Color->value)
            ->where('attribute_values.status', self::ACTIVE)
            ->orderBy('attribute_values.sort_order')
            ->orderBy('attribute_values.id')
            ->get(self::valueColumns());

        return array_values($rows->map(fn (object $row): ValueSnapshot => self::value($row))->all());
    }

    /**
     * Attributes each product declares in display order, each with the value ids it admits.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<AttributeSnapshot>> by product id
     */
    private function attributes(array $productIds): array
    {
        $rows = DB::table('product_attributes')
            ->join('catalog_attributes', 'catalog_attributes.id', '=', 'product_attributes.catalog_attribute_id')
            ->leftJoin('product_attribute_values', 'product_attribute_values.product_attribute_id', '=', 'product_attributes.id')
            ->whereIn('product_attributes.product_id', $productIds)
            ->orderBy('product_attributes.sort_order')
            ->orderBy('product_attributes.id')
            ->get([
                'product_attributes.id as row_id', 'product_attributes.product_id', 'catalog_attributes.id', 'catalog_attributes.name', 'catalog_attributes.status',
                'catalog_attributes.presentation', 'catalog_attributes.special_use', 'product_attributes.role',
                'product_attributes.sort_order', 'product_attribute_values.attribute_value_id',
            ]);

        $attributes = [];
        $allowed = [];

        foreach ($rows as $row) {
            $attributes[$row->row_id] ??= $row;

            if ($row->attribute_value_id !== null) {
                $allowed[$row->row_id][] = (int) $row->attribute_value_id;
            }
        }

        $byProduct = [];

        foreach ($attributes as $rowId => $row) {
            $byProduct[(int) $row->product_id][] = new AttributeSnapshot(
                id: (int) $row->id,
                name: (string) $row->name,
                active: $row->status === self::ACTIVE,
                role: AttributeRole::from((string) $row->role),
                sortOrder: (int) $row->sort_order,
                presentation: AttributePresentation::from((string) $row->presentation),
                specialUse: $row->special_use === null ? null : AttributeSpecialUse::from((string) $row->special_use),
                allowed: $allowed[$rowId] ?? [],
            );
        }

        return $byProduct;
    }

    /**
     * Active combinations with their code, axes, restrictions and included customizations. A value
     * of an attribute that the product declares as an axis is an axis value; one of an order
     * attribute is a restriction (DEC-PRD-36).
     *
     * @param  list<int>  $productIds
     * @param  array<int, array<int, AttributeRole>>  $roles  product id => declared attribute id => role
     * @return array{0: array<int, list<CombinationSnapshot>>, 1: array<int, list<int>>} the combinations and every value id they use, by product id
     */
    private function combinations(array $productIds, array $roles): array
    {
        $rows = DB::table('combinations')
            ->join('catalog_codes', 'catalog_codes.combination_id', '=', 'combinations.id')
            ->leftJoin('combination_values', 'combination_values.combination_id', '=', 'combinations.id')
            ->whereIn('combinations.product_id', $productIds)
            ->where('combinations.status', self::ACTIVE)
            ->orderBy('combinations.id')
            ->get(['combinations.id', 'combinations.product_id', 'catalog_codes.code', 'combination_values.catalog_attribute_id', 'combination_values.attribute_value_id']);

        $codes = [];
        $owners = [];
        $axes = [];
        $restrictions = [];
        $valueIds = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $productId = (int) $row->product_id;
            $codes[$id] = (string) $row->code;
            $owners[$id] = $productId;
            $axes[$id] ??= [];
            $restrictions[$id] ??= [];

            if ($row->attribute_value_id === null) {
                continue;
            }

            $attributeId = (int) $row->catalog_attribute_id;
            $valueId = (int) $row->attribute_value_id;
            $valueIds[$productId][] = $valueId;

            match ($roles[$productId][$attributeId] ?? null) {
                AttributeRole::Axis => $axes[$id][$attributeId][] = $valueId,
                AttributeRole::Order => $restrictions[$id][$attributeId][] = $valueId,
                null => null,
            };
        }

        $included = $this->includedServices(array_keys($codes));
        $combinations = [];

        foreach ($codes as $id => $code) {
            $combinations[$owners[$id]][] = new CombinationSnapshot($id, $code, $axes[$id], $restrictions[$id], $included[$id] ?? []);
        }

        return [$combinations, $valueIds];
    }

    /**
     * Services each combination includes, by combination id (DEC-PRD-47).
     *
     * @param  list<int>  $combinationIds
     * @return array<int, list<ServiceSnapshot>>
     */
    private function includedServices(array $combinationIds): array
    {
        if ($combinationIds === []) {
            return [];
        }

        $rows = DB::table('combination_customizations')
            ->join('products', 'products.id', '=', 'combination_customizations.service_product_id')
            ->whereIn('combination_customizations.combination_id', $combinationIds)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->get(['combination_customizations.combination_id', 'products.id', 'products.name', 'products.status']);

        $included = [];

        foreach ($rows as $row) {
            $included[(int) $row->combination_id][] = new ServiceSnapshot((int) $row->id, (string) $row->name, $row->status === self::ACTIVE);
        }

        return $included;
    }

    /**
     * Active colors each fabric value offers, by fabric value id (DEC-PRD-32).
     *
     * @param  list<int>  $fabricValueIds
     * @return array<int, list<int>>
     */
    private function fabricColors(array $fabricValueIds): array
    {
        if ($fabricValueIds === []) {
            return [];
        }

        $rows = DB::table('fabric_offered_colors')
            ->join('attribute_values', 'attribute_values.id', '=', 'fabric_offered_colors.color_value_id')
            ->whereIn('fabric_offered_colors.fabric_value_id', $fabricValueIds)
            ->where('attribute_values.status', self::ACTIVE)
            ->orderBy('attribute_values.sort_order')
            ->orderBy('attribute_values.id')
            ->get(['fabric_offered_colors.fabric_value_id', 'fabric_offered_colors.color_value_id']);

        $colors = [];

        foreach ($rows as $row) {
            $colors[(int) $row->fabric_value_id][] = (int) $row->color_value_id;
        }

        return $colors;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, list<LocationSnapshot>> by product id
     */
    private function detailLocations(array $productIds): array
    {
        $rows = DB::table('product_detail_locations')
            ->join('detail_locations', 'detail_locations.id', '=', 'product_detail_locations.detail_location_id')
            ->whereIn('product_detail_locations.product_id', $productIds)
            ->where('detail_locations.status', self::ACTIVE)
            ->orderBy('detail_locations.name')
            ->orderBy('detail_locations.id')
            ->get(['product_detail_locations.product_id', 'detail_locations.id', 'detail_locations.name', 'detail_locations.svg_layer']);

        $locations = [];

        foreach ($rows as $row) {
            $locations[(int) $row->product_id][] = new LocationSnapshot((int) $row->id, (string) $row->name, $row->svg_layer);
        }

        return $locations;
    }

    /**
     * Active services each product admits as extra customizations (PRD-008).
     *
     * @param  list<int>  $productIds
     * @return array<int, list<ServiceSnapshot>> by product id
     */
    private function admittedServices(array $productIds): array
    {
        $rows = DB::table('product_customizations')
            ->join('products', 'products.id', '=', 'product_customizations.service_product_id')
            ->whereIn('product_customizations.product_id', $productIds)
            ->where('products.status', self::ACTIVE)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->get(['product_customizations.product_id', 'products.id', 'products.name']);

        $services = [];

        foreach ($rows as $row) {
            $services[(int) $row->product_id][] = new ServiceSnapshot((int) $row->id, (string) $row->name, true);
        }

        return $services;
    }

    /**
     * @param  list<int>  $valueIds
     * @return array<int, ValueSnapshot>
     */
    private function values(array $valueIds): array
    {
        if ($valueIds === []) {
            return [];
        }

        $values = [];

        foreach (DB::table('attribute_values')->whereIn('id', $valueIds)->get(self::valueColumns()) as $row) {
            $values[(int) $row->id] = self::value($row);
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function valueColumns(): array
    {
        return [
            'attribute_values.id', 'attribute_values.catalog_attribute_id', 'attribute_values.name', 'attribute_values.description',
            'attribute_values.sort_order', 'attribute_values.status', 'attribute_values.tone', 'attribute_values.svg_layer',
        ];
    }

    private static function value(stdClass $row): ValueSnapshot
    {
        return new ValueSnapshot(
            id: (int) $row->id,
            attributeId: (int) $row->catalog_attribute_id,
            name: (string) $row->name,
            description: $row->description,
            sortOrder: (int) $row->sort_order,
            active: $row->status === self::ACTIVE,
            tone: $row->tone,
            layer: $row->svg_layer,
        );
    }
}
