<?php

namespace App\Support\Products;

use App\Enums\CatalogStatus;
use App\Models\CatalogAttribute;
use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cross-record "who uses this" queries (design Decisions 9, 10 and 11). They only read and return
 * names so the Actions can say what prevents a change (DEC-PRD-51, DEC-PRD-52).
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
     * The combos that have a component of the product (DEC-PRD-52, E-70), each once and sorted by
     * code, whatever the status of the combo or how many of its components use the product, as
     * "code (name)" (DEC-PRD-63).
     *
     * @return list<string>
     */
    public static function combosUsingProduct(int $productId): array
    {
        $query = DB::table('combo_components')
            ->join('combos', 'combos.id', '=', 'combo_components.combo_id')
            ->where('combo_components.product_id', $productId);

        return self::comboLabels($query);
    }

    /**
     * The combos the combination is part of (PRD-014, DEC-PRD-42, E-61): those with a component of its
     * product that the customer could choose it in (see `ComboMembership`), each once and sorted by
     * code, as "code (name)" (DEC-PRD-63).
     *
     * @param  array<int, list<int>>  $combinationAxes  attributeId => valueIds
     * @param  list<int>  $axisAttributeIds  attributes the product declares as axes
     * @return list<string>
     */
    public static function combosIncludingCombination(int $productId, array $combinationAxes, array $axisAttributeIds): array
    {
        $components = DB::table('combo_components')->where('product_id', $productId)->get(['id', 'combo_id']);

        if ($components->isEmpty()) {
            return [];
        }

        $restrictions = [];

        $rows = DB::table('combo_component_values')
            ->whereIn('combo_component_id', $components->pluck('id')->all())
            ->get(['combo_component_id', 'catalog_attribute_id', 'attribute_value_id']);

        foreach ($rows as $row) {
            $restrictions[(int) $row->combo_component_id][(int) $row->catalog_attribute_id][] = (int) $row->attribute_value_id;
        }

        $comboIds = [];

        foreach ($components as $component) {
            if (ComboMembership::includes($restrictions[(int) $component->id] ?? [], $combinationAxes, $axisAttributeIds)) {
                $comboIds[] = (int) $component->combo_id;
            }
        }

        if ($comboIds === []) {
            return [];
        }

        return self::comboLabels(DB::table('combos')->whereIn('combos.id', array_values(array_unique($comboIds))));
    }

    /**
     * The combos whose components of this product restrict one of the given values or attributes
     * (DEC-PRD-37, DEC-PRD-44), each once and sorted by code, as "code (name)" (DEC-PRD-63).
     * Removing such a value, or an attribute that holds one, from the product would leave the
     * component with a restriction the product no longer admits (DEC-PRD-66).
     *
     * @param  list<int>  $valueIds
     * @param  list<int>  $attributeIds
     * @return list<string>
     */
    public static function combosRestricting(int $productId, array $valueIds, array $attributeIds): array
    {
        $query = DB::table('combo_component_values')
            ->join('combo_components', 'combo_components.id', '=', 'combo_component_values.combo_component_id')
            ->join('combos', 'combos.id', '=', 'combo_components.combo_id')
            ->where('combo_components.product_id', $productId)
            ->where(fn ($query) => $query
                ->whereIn('combo_component_values.attribute_value_id', $valueIds)
                ->orWhereIn('combo_component_values.catalog_attribute_id', $attributeIds));

        return self::comboLabels($query);
    }

    /**
     * "code (name)" of the combos the query reaches (it must already join `combos`), each once and
     * sorted by code, like the combinations of `productsUsingService()`.
     *
     * @return list<string>
     */
    private static function comboLabels(Builder $query): array
    {
        return array_values($query
            ->join('catalog_codes', 'catalog_codes.combo_id', '=', 'combos.id')
            ->distinct()
            ->orderBy('catalog_codes.code')
            ->get(['catalog_codes.code', 'combos.name'])
            ->map(fn (object $row): string => "{$row->code} ({$row->name})")
            ->all());
    }

    /**
     * Who uses a service product as a customization (DEC-PRD-52): the names of the products that
     * admit it (any status) and the combinations that include it as "code (product name)" (DEC-PRD-57), both sorted.
     *
     * @return array{products: list<string>, combinations: list<string>}
     */
    public static function productsUsingService(int $serviceProductId): array
    {
        $products = DB::table('product_customizations')
            ->join('products', 'products.id', '=', 'product_customizations.product_id')
            ->where('product_customizations.service_product_id', $serviceProductId)
            ->orderBy('products.name')
            ->pluck('products.name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();

        $combinations = DB::table('combination_customizations')
            ->join('catalog_codes', 'catalog_codes.combination_id', '=', 'combination_customizations.combination_id')
            ->join('combinations', 'combinations.id', '=', 'combination_customizations.combination_id')
            ->join('products', 'products.id', '=', 'combinations.product_id')
            ->where('combination_customizations.service_product_id', $serviceProductId)
            ->orderBy('catalog_codes.code')
            ->get(['catalog_codes.code', 'products.name'])
            ->map(fn (object $row): string => "{$row->code} ({$row->name})")
            ->all();

        return ['products' => array_values($products), 'combinations' => array_values($combinations)];
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
