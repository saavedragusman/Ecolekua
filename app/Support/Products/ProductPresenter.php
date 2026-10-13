<?php

namespace App\Support\Products;

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\Combination;
use App\Models\DetailLocation;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\StockMinimumOverride;
use Illuminate\Support\Collection;

/**
 * Turns catalog models into the plain arrays the Inertia pages receive (design Decision 20). Every
 * label is resolved here so the UI only renders; the product and combination presenters join this
 * class in later slices.
 */
final class ProductPresenter
{
    /**
     * Row of the attribute list and header of the attribute page. `values_count` comes from
     * `withCount('values')`.
     *
     * @return array<string, mixed>
     */
    public static function attribute(CatalogAttribute $attribute): array
    {
        return [
            'id' => $attribute->id,
            'name' => $attribute->name,
            'presentation' => $attribute->presentation->value,
            'presentation_label' => $attribute->presentation->label(),
            'special_use' => $attribute->special_use?->value,
            'special_use_label' => $attribute->special_use?->label(),
            'status' => $attribute->status->value,
            'status_label' => $attribute->status->label(),
            'sort_order' => $attribute->sort_order,
            'values_count' => (int) $attribute->getAttribute('values_count'),
        ];
    }

    /**
     * Value of an attribute. `offered_colors` is only an array for the values of the fabric
     * attribute (PRD-002, E-46); every other value carries null.
     *
     * @return array<string, mixed>
     */
    public static function value(AttributeValue $value, bool $withOfferedColors): array
    {
        return [
            'id' => $value->id,
            'name' => $value->name,
            'description' => $value->description,
            'tone' => $value->tone,
            'svg_layer' => $value->svg_layer,
            'status' => $value->status->value,
            'status_label' => $value->status->label(),
            'sort_order' => $value->sort_order,
            'offered_colors' => $withOfferedColors
                ? $value->offeredColors->sortBy(['sort_order', 'id'])->map(self::colorRef(...))->values()->all()
                : null,
        ];
    }

    /**
     * Active colors a fabric can offer (PRD-002: only active colors can be added), in display order.
     *
     * @return list<array<string, mixed>>
     */
    public static function palette(): array
    {
        $colors = AttributeValue::query()
            ->whereHas('catalogAttribute', fn ($query) => $query->where('presentation', AttributePresentation::Color->value))
            ->withStatus(CatalogStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return array_values($colors->map(self::colorRef(...))->all());
    }

    /**
     * Descriptive name of a combination (spec section 5, E-07): the product name followed by the
     * value names of each axis in axis order, joined by " · "; several values of one axis are joined
     * by " o " (DEC-PRD-33).
     *
     * @param  list<list<string>>  $axes  value names of each axis, in axis order
     */
    public static function descriptiveName(Product $product, array $axes): string
    {
        $parts = array_map(fn (array $names): string => implode(' o ', $names), array_filter($axes, fn (array $names): bool => $names !== []));

        return implode(' · ', [$product->name, ...$parts]);
    }

    /**
     * Descriptive name of a stored combination: its axis values by the display order of the
     * attributes and of the values.
     */
    public static function combinationName(Combination $combination): string
    {
        $combination->load('values');
        $product = $combination->product;
        $axes = [];

        $axisAttributes = $product->productAttributes()
            ->where('role', AttributeRole::Axis->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($axisAttributes as $row) {
            $axes[] = array_values($combination->values
                ->filter(fn (AttributeValue $value): bool => $value->catalog_attribute_id === $row->catalog_attribute_id)
                ->sortBy(['sort_order', 'id'])
                ->map(fn (AttributeValue $value): string => $value->name)
                ->all());
        }

        return self::descriptiveName($product, $axes);
    }

    /**
     * Row of the product list (PRD-015). Expects `category` loaded and `combinations_count` from
     * `withCount('combinations')`.
     *
     * @return array<string, mixed>
     */
    public static function listRow(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'category' => $product->category->name,
            'business_line_label' => $product->business_line->label(),
            'supply_mode_label' => $product->supply_mode->label(),
            'status' => $product->status->value,
            'status_label' => $product->status->label(),
            'combinations_count' => (int) $product->getAttribute('combinations_count'),
        ];
    }

    /**
     * Options of the list filters (category, business line, supply mode), labelled by the backend.
     * Every category is offered, inactive ones included: a product can still belong to one.
     *
     * @return array{categories: list<array{value: int, label: string}>, lines: list<array{value: string, label: string}>, modes: list<array{value: string, label: string}>}
     */
    public static function filterOptions(): array
    {
        return [
            'categories' => array_values(ProductCategory::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ProductCategory $category): array => ['value' => $category->id, 'label' => $category->name])
                ->all()),
            'lines' => array_map(
                fn (BusinessLine $case): array => ['value' => $case->value, 'label' => $case->label()],
                BusinessLine::cases(),
            ),
            'modes' => array_map(
                fn (SupplyMode $case): array => ['value' => $case->value, 'label' => $case->label()],
                SupplyMode::cases(),
            ),
        ];
    }

    /**
     * Product page (PRD-015, spec section 8): general data, structure, combinations, details,
     * customizations, stock and images. Every label and every derived flag is resolved here.
     *
     * @return array<string, mixed>
     */
    public static function detail(Product $product): array
    {
        $product->load([
            'category',
            'productAttributes.catalogAttribute',
            'productAttributes.allowedValues',
            'detailLocations',
            'customizations',
        ]);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category->name,
            'business_line' => $product->business_line->value,
            'business_line_label' => $product->business_line->label(),
            'supply_mode' => $product->supply_mode->value,
            'supply_mode_label' => $product->supply_mode->label(),
            'portal_visible' => $product->portal_visible,
            'admits_custom_color' => $product->admitsCustomColor(),
            'status' => $product->status->value,
            'status_label' => $product->status->label(),
            'attributes' => self::structure($product),
            'combinations' => self::combinationRows($product),
            'detail_locations' => self::references($product->detailLocations),
            'customizations' => self::references($product->customizations),
            'stock' => [
                'default' => $product->min_stock_default,
                'overrides' => StockMinimum::snapshot($product),
            ],
            // Image URLs and template files arrive with the upload slices (Phases 19 and 22); the raw
            // storage paths are never exposed.
            'images' => ['has_main' => $product->image_display_path !== null, 'templates' => []],
        ];
    }

    /**
     * Options of the product form (PRD-003, PRD-012): the categories a product can be placed in
     * (the active ones, plus the product's own even if it was deactivated afterwards, as the
     * request rule does), and the labelled lines and modes.
     *
     * @return array{categories: list<array{value: int, label: string}>, lines: list<array{value: string, label: string}>, modes: list<array{value: string, label: string}>}
     */
    public static function formOptions(?Product $product = null): array
    {
        $categories = ProductCategory::query()
            ->where(fn ($query) => $query
                ->where('status', CatalogStatus::Active->value)
                ->when($product !== null, fn ($inner) => $inner->orWhere('id', $product?->product_category_id)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'categories' => array_values($categories
                ->map(fn (ProductCategory $category): array => ['value' => $category->id, 'label' => $category->name])
                ->all()),
            'lines' => self::filterOptions()['lines'],
            'modes' => self::filterOptions()['modes'],
        ];
    }

    /**
     * Stored general data of a product for the edit form, with the ids of the detail locations and
     * customizations it admits.
     *
     * @return array<string, mixed>
     */
    public static function form(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'product_category_id' => $product->product_category_id,
            'business_line' => $product->business_line->value,
            'supply_mode' => $product->supply_mode->value,
            'min_stock_default' => $product->min_stock_default,
            'allows_custom_color' => $product->allows_custom_color,
            'portal_visible' => $product->portal_visible,
            'status' => $product->status->value,
            'detail_location_ids' => array_values($product->detailLocations()->pluck('detail_locations.id')->map(fn (mixed $id): int => (int) $id)->all()),
            'customization_ids' => array_values($product->customizations()->pluck('products.id')->map(fn (mixed $id): int => (int) $id)->all()),
        ];
    }

    /**
     * Detail locations and services the edit form can check: the active ones plus those the product
     * already holds even if they were deactivated afterwards (DEC-PRD-55). A service is never the
     * product itself.
     *
     * @return array{detail_locations: list<array{value: int, label: string}>, customizations: list<array{value: int, label: string}>}
     */
    public static function relationOptions(Product $product): array
    {
        $heldLocations = $product->detailLocations()->pluck('detail_locations.id')->all();
        $heldServices = $product->customizations()->pluck('products.id')->all();

        $locations = DetailLocation::query()
            ->where(fn ($query) => $query->where('status', CatalogStatus::Active->value)->orWhereIn('id', $heldLocations))
            ->get();
        $services = Product::query()
            ->where('supply_mode', SupplyMode::Service->value)
            ->whereKeyNot($product->id)
            ->where(fn ($query) => $query->where('status', CatalogStatus::Active->value)->orWhereIn('id', $heldServices))
            ->get();

        return [
            'detail_locations' => self::checkboxOptions($locations),
            'customizations' => self::checkboxOptions($services),
        ];
    }

    /**
     * Own minimum stock editor of a `stock_with_minimum` product (PRD-009, DEC-PRD-46): the articles
     * (combinations) that can carry an own minimum, the sizes each one admits when the product
     * declares the size-use attribute as an order attribute (the combination restriction when it has
     * one, every allowed size otherwise), and the stored overrides. Null in any other mode.
     *
     * @return array<string, mixed>|null
     */
    public static function stockEditor(Product $product): ?array
    {
        if ($product->supply_mode !== SupplyMode::StockWithMinimum) {
            return null;
        }

        $sizeRow = $product->productAttributes()
            ->where('role', AttributeRole::Order->value)
            ->whereHas('catalogAttribute', fn ($query) => $query->where('special_use', AttributeSpecialUse::Size->value))
            ->with(['catalogAttribute', 'allowedValues'])
            ->first();
        $allowedSizes = $sizeRow?->allowedValues->sortBy(['sort_order', 'id']);

        $combinations = $product->combinations()
            ->with(['catalogCode', 'values'])
            ->get()
            ->sortBy(fn (Combination $combination): string => (string) $combination->code, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'size_attribute' => $sizeRow?->catalogAttribute->name,
            'combinations' => array_values($combinations->map(function (Combination $combination) use ($sizeRow, $allowedSizes): array {
                $sizes = null;

                if ($sizeRow !== null && $allowedSizes !== null) {
                    $restriction = $combination->values->where('catalog_attribute_id', $sizeRow->catalog_attribute_id)->modelKeys();
                    $sizes = array_values($allowedSizes
                        ->filter(fn (AttributeValue $size): bool => $restriction === [] || in_array($size->id, $restriction, true))
                        ->map(fn (AttributeValue $size): array => ['value' => $size->id, 'label' => $size->name])
                        ->all());
                }

                return [
                    'id' => $combination->id,
                    'code' => $combination->code,
                    'name' => self::combinationName($combination),
                    'sizes' => $sizes,
                ];
            })->all()),
            'overrides' => array_values(StockMinimumOverride::query()
                ->whereIn('combination_id', $combinations->modelKeys())
                ->orderBy('combination_id')
                ->orderBy('size_key')
                ->get()
                ->map(fn (StockMinimumOverride $override): array => [
                    'combination_id' => $override->combination_id,
                    'size_value_id' => $override->size_value_id,
                    'minimum' => $override->minimum,
                ])
                ->all()),
        ];
    }

    /**
     * Structure editor (PRD-004): the declared attributes in display order with their admitted value
     * ids, ready to be sent back as they are.
     *
     * @return list<array{attribute_id: int, role: string, allowed_value_ids: list<int>}>
     */
    public static function structureEntries(Product $product): array
    {
        return array_values($product->productAttributes()
            ->with('allowedValues')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductAttribute $row): array => [
                'attribute_id' => $row->catalog_attribute_id,
                'role' => $row->role->value,
                'allowed_value_ids' => array_values($row->allowedValues->sortBy(['sort_order', 'id'])->modelKeys()),
            ])
            ->all());
    }

    /**
     * Attributes a product can declare: the active ones with their active values, plus the inactive
     * attributes and values the product already holds so a save does not silently drop them
     * (DEC-PRD-51). `is_fabric`, `is_color` and `fixed_role` carry DEC-PRD-35 and DEC-PRD-50 to the
     * page; the backend still validates every request.
     *
     * @return list<array<string, mixed>>
     */
    public static function structureCatalog(Product $product): array
    {
        $held = $product->productAttributes()->with('allowedValues')->get();
        $heldAttributeIds = $held->pluck('catalog_attribute_id')->all();
        $heldValueIds = $held->flatMap(fn (ProductAttribute $row): array => $row->allowedValues->modelKeys())->all();

        $attributes = CatalogAttribute::query()
            ->where(fn ($query) => $query->where('status', CatalogStatus::Active->value)->orWhereIn('id', $heldAttributeIds))
            ->with(['values' => fn ($query) => $query
                ->where(fn ($inner) => $inner->where('status', CatalogStatus::Active->value)->orWhereIn('id', $heldValueIds))
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return array_values($attributes->map(function (CatalogAttribute $attribute): array {
            $isFabric = $attribute->special_use === AttributeSpecialUse::Fabric;
            $isColor = $attribute->presentation === AttributePresentation::Color;

            return [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'status' => $attribute->status->value,
                'is_fabric' => $isFabric,
                'is_color' => $isColor,
                'fixed_role' => match (true) {
                    $isFabric => AttributeRole::Axis->value,
                    $isColor => AttributeRole::Order->value,
                    default => null,
                },
                'values' => array_values($attribute->values->map(fn (AttributeValue $value): array => [
                    'value' => $value->id,
                    'label' => $value->status === CatalogStatus::Active ? $value->name : "{$value->name} (inactivo)",
                ])->all()),
            ];
        })->all());
    }

    /**
     * @param  Collection<int, DetailLocation>|Collection<int, Product>  $items
     * @return list<array{value: int, label: string}>
     */
    private static function checkboxOptions(Collection $items): array
    {
        return array_values($items
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (DetailLocation|Product $item): array => [
                'value' => $item->id,
                'label' => $item->status === CatalogStatus::Active ? $item->name : "{$item->name} (inactivo)",
            ])
            ->all());
    }

    /**
     * Attributes the product declares in display order, with role and admitted values. A color the
     * product declares without values takes its options from the chosen fabric (DEC-PRD-35).
     *
     * @return list<array<string, mixed>>
     */
    private static function structure(Product $product): array
    {
        $declaresFabric = $product->productAttributes
            ->contains(fn (ProductAttribute $row): bool => $row->catalogAttribute->special_use === AttributeSpecialUse::Fabric);

        return array_values($product->productAttributes->map(function (ProductAttribute $row) use ($declaresFabric): array {
            $values = $row->allowedValues->sortBy(['sort_order', 'id'])->values();

            return [
                'id' => $row->catalog_attribute_id,
                'name' => $row->catalogAttribute->name,
                'role' => $row->role->value,
                'role_label' => $row->role->label(),
                'values' => array_values($values->map(fn (AttributeValue $value): array => ['id' => $value->id, 'name' => $value->name])->all()),
                'follows_fabric' => $declaresFabric
                    && $values->isEmpty()
                    && $row->catalogAttribute->presentation === AttributePresentation::Color,
            ];
        })->all());
    }

    /**
     * Combinations of the product by code: descriptive name, order restrictions, the services the
     * price already covers and the combination's own status. Loaded once for the whole product.
     *
     * @return list<array<string, mixed>>
     */
    private static function combinationRows(Product $product): array
    {
        $combinations = $product->combinations()
            ->with(['catalogCode', 'values', 'customizations'])
            ->get()
            ->sortBy(fn (Combination $combination): string => (string) $combination->code, SORT_NATURAL | SORT_FLAG_CASE);

        $axisIds = $product->productAttributes->where('role', AttributeRole::Axis)->pluck('catalog_attribute_id');
        $orderRows = $product->productAttributes->where('role', AttributeRole::Order);

        $namesOf = fn (Combination $combination, int $attributeId): array => array_values($combination->values
            ->filter(fn (AttributeValue $value): bool => $value->catalog_attribute_id === $attributeId)
            ->sortBy(['sort_order', 'id'])
            ->map(fn (AttributeValue $value): string => $value->name)
            ->all());

        return array_values($combinations->map(function (Combination $combination) use ($product, $axisIds, $orderRows, $namesOf): array {
            $axes = [];

            foreach ($axisIds as $axisId) {
                $axes[] = $namesOf($combination, $axisId);
            }

            $restrictions = [];

            foreach ($orderRows as $row) {
                $names = $namesOf($combination, $row->catalog_attribute_id);

                if ($names !== []) {
                    $restrictions[] = ['attribute' => $row->catalogAttribute->name, 'values' => $names];
                }
            }

            return [
                'id' => $combination->id,
                'code' => $combination->code,
                'name' => self::descriptiveName($product, $axes),
                'status' => $combination->status->value,
                'status_label' => $combination->status->label(),
                'restrictions' => $restrictions,
                'included_customizations' => array_values($combination->customizations->pluck('name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->all()),
            ];
        })->all());
    }

    /**
     * Detail locations and services of a product page, by name.
     *
     * @param  Collection<int, DetailLocation>|Collection<int, Product>  $items
     * @return list<array{id: int, name: string, status: string, status_label: string}>
     */
    private static function references(Collection $items): array
    {
        return array_values($items
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (DetailLocation|Product $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
            ])
            ->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function presentationOptions(): array
    {
        return array_map(
            fn (AttributePresentation $case): array => ['value' => $case->value, 'label' => $case->label()],
            AttributePresentation::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function specialUseOptions(): array
    {
        return array_map(
            fn (AttributeSpecialUse $case): array => ['value' => $case->value, 'label' => $case->label()],
            AttributeSpecialUse::cases(),
        );
    }

    /**
     * @return array{id: int, name: string, tone: string|null, status: string}
     */
    private static function colorRef(AttributeValue $color): array
    {
        return [
            'id' => $color->id,
            'name' => $color->name,
            'tone' => $color->tone,
            'status' => $color->status->value,
        ];
    }
}
