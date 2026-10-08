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
        $product = DB::table('products')
            ->join('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->where('products.id', $productId)
            ->first([
                'products.name', 'products.status', 'products.supply_mode', 'products.allows_custom_color',
                'product_categories.status as category_status',
            ]);

        if ($product === null) {
            return null;
        }

        $attributes = $this->attributes($productId);
        $fabricAllowed = [];

        foreach ($attributes as $attribute) {
            if ($attribute->isFabric()) {
                $fabricAllowed = $attribute->allowed;
            }
        }

        $roles = [];

        foreach ($attributes as $attribute) {
            $roles[$attribute->id] = $attribute->role;
        }

        [$combinations, $combinationValueIds] = $this->combinations($productId, $roles);
        $fabricColors = $this->fabricColors($fabricAllowed);
        $allowed = array_merge(...array_map(fn (AttributeSnapshot $attribute): array => $attribute->allowed, $attributes));
        $colorIds = array_merge(...array_values($fabricColors));
        $supplyMode = SupplyMode::from((string) $product->supply_mode);

        return new ProductSnapshot(
            id: $productId,
            name: (string) $product->name,
            active: $product->status === self::ACTIVE,
            categoryActive: $product->category_status === self::ACTIVE,
            supplyMode: $supplyMode,
            admitsCustomColor: $supplyMode === SupplyMode::OnDemand
                && (bool) $product->allows_custom_color
                && array_filter($attributes, fn (AttributeSnapshot $attribute): bool => $attribute->isColor()) !== [],
            attributes: $attributes,
            values: $this->values(array_values(array_unique([...$allowed, ...$combinationValueIds, ...$colorIds]))),
            fabricColors: $fabricColors,
            combinations: $combinations,
            detailLocations: $this->detailLocations($productId),
            customizations: $this->admittedServices($productId),
            // The templates table arrives with the SVG templates (Phase 22); nothing reads them yet.
            templates: [],
        );
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
     * Attributes the product declares in display order, each with the value ids it admits.
     *
     * @return list<AttributeSnapshot>
     */
    private function attributes(int $productId): array
    {
        $rows = DB::table('product_attributes')
            ->join('catalog_attributes', 'catalog_attributes.id', '=', 'product_attributes.catalog_attribute_id')
            ->leftJoin('product_attribute_values', 'product_attribute_values.product_attribute_id', '=', 'product_attributes.id')
            ->where('product_attributes.product_id', $productId)
            ->orderBy('product_attributes.sort_order')
            ->orderBy('product_attributes.id')
            ->get([
                'product_attributes.id as row_id', 'catalog_attributes.id', 'catalog_attributes.name', 'catalog_attributes.status',
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

        return array_values(array_map(fn (object $row): AttributeSnapshot => new AttributeSnapshot(
            id: (int) $row->id,
            name: (string) $row->name,
            active: $row->status === self::ACTIVE,
            role: AttributeRole::from((string) $row->role),
            sortOrder: (int) $row->sort_order,
            presentation: AttributePresentation::from((string) $row->presentation),
            specialUse: $row->special_use === null ? null : AttributeSpecialUse::from((string) $row->special_use),
            allowed: $allowed[$row->row_id] ?? [],
        ), $attributes));
    }

    /**
     * Active combinations with their code, axes, restrictions and included customizations. A value
     * of an attribute that the product declares as an axis is an axis value; one of an order
     * attribute is a restriction (DEC-PRD-36).
     *
     * @param  array<int, AttributeRole>  $roles  declared attribute id => role
     * @return array{0: list<CombinationSnapshot>, 1: list<int>} the combinations and every value id they use
     */
    private function combinations(int $productId, array $roles): array
    {
        $rows = DB::table('combinations')
            ->join('catalog_codes', 'catalog_codes.combination_id', '=', 'combinations.id')
            ->leftJoin('combination_values', 'combination_values.combination_id', '=', 'combinations.id')
            ->where('combinations.product_id', $productId)
            ->where('combinations.status', self::ACTIVE)
            ->orderBy('combinations.id')
            ->get(['combinations.id', 'catalog_codes.code', 'combination_values.catalog_attribute_id', 'combination_values.attribute_value_id']);

        $codes = [];
        $axes = [];
        $restrictions = [];
        $valueIds = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $codes[$id] = (string) $row->code;
            $axes[$id] ??= [];
            $restrictions[$id] ??= [];

            if ($row->attribute_value_id === null) {
                continue;
            }

            $attributeId = (int) $row->catalog_attribute_id;
            $valueId = (int) $row->attribute_value_id;
            $valueIds[] = $valueId;

            match ($roles[$attributeId] ?? null) {
                AttributeRole::Axis => $axes[$id][$attributeId][] = $valueId,
                AttributeRole::Order => $restrictions[$id][$attributeId][] = $valueId,
                null => null,
            };
        }

        $included = $this->includedServices(array_keys($codes));
        $combinations = [];

        foreach ($codes as $id => $code) {
            $combinations[] = new CombinationSnapshot($id, $code, $axes[$id], $restrictions[$id], $included[$id] ?? []);
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
     * @return list<LocationSnapshot>
     */
    private function detailLocations(int $productId): array
    {
        $rows = DB::table('product_detail_locations')
            ->join('detail_locations', 'detail_locations.id', '=', 'product_detail_locations.detail_location_id')
            ->where('product_detail_locations.product_id', $productId)
            ->where('detail_locations.status', self::ACTIVE)
            ->orderBy('detail_locations.name')
            ->orderBy('detail_locations.id')
            ->get(['detail_locations.id', 'detail_locations.name', 'detail_locations.svg_layer']);

        return array_values($rows->map(fn (object $row): LocationSnapshot => new LocationSnapshot((int) $row->id, (string) $row->name, $row->svg_layer))->all());
    }

    /**
     * Active services the product admits as extra customizations (PRD-008).
     *
     * @return list<ServiceSnapshot>
     */
    private function admittedServices(int $productId): array
    {
        $rows = DB::table('product_customizations')
            ->join('products', 'products.id', '=', 'product_customizations.service_product_id')
            ->where('product_customizations.product_id', $productId)
            ->where('products.status', self::ACTIVE)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->get(['products.id', 'products.name']);

        return array_values($rows->map(fn (object $row): ServiceSnapshot => new ServiceSnapshot((int) $row->id, (string) $row->name, true))->all());
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
