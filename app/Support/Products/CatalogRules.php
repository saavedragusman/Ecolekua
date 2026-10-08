<?php

namespace App\Support\Products;

use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\DetailLocation;
use App\Models\Product;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;

/**
 * Validation rules of the attribute catalog, shared by the form requests and, later, by the
 * initial load (PRD-018) so both apply exactly the same criteria (design Decision 9): reference
 * tone (E-36, DEC-PRD-17), special use and presentation uniqueness (E-45, E-58, DEC-PRD-38,
 * DEC-PRD-49) and the SVG layer format (DEC-PRD-29).
 */
final class CatalogRules
{
    /** Reference tone: `#RRGGBB`, any letter case; it is stored uppercase. */
    public const TONE_PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    /** SVG layer: lowercase words joined by single hyphens. */
    public const LAYER_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public const LAYER_MAX_LENGTH = 64;

    /** Layer names with a fixed meaning in the template preview (PRD-020). */
    public const RESERVED_LAYERS = ['cuerpo', 'sombras'];

    public static function normalizeTone(?string $tone): ?string
    {
        return $tone === null ? null : strtoupper($tone);
    }

    /**
     * Rules of an attribute. `$attribute` is the row being edited (null on creation); `$presentation`
     * is the submitted presentation, which the special-use rule needs (fabric is never a color).
     *
     * @return array<string, list<mixed>>
     */
    public static function attributeRules(?CatalogAttribute $attribute, mixed $presentation, bool $specialUseRequired): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('catalog_attributes', 'name')->ignore($attribute?->id)],
            'presentation' => [
                'required',
                Rule::enum(AttributePresentation::class),
                function (string $field, mixed $value, Closure $fail) use ($attribute): void {
                    if ($value !== AttributePresentation::Color->value || $attribute?->presentation === AttributePresentation::Color) {
                        return;
                    }

                    $otherColor = CatalogAttribute::query()
                        ->where('presentation', AttributePresentation::Color->value)
                        ->when($attribute !== null, fn ($query) => $query->whereKeyNot($attribute?->id))
                        ->exists();

                    if ($otherColor) {
                        $fail(__('validation.attribute_color_unique'));
                    } elseif ($attribute !== null && $attribute->values()->whereNull('tone')->exists()) {
                        $fail(__('validation.attribute_color_requires_tones'));
                    }
                },
            ],
            'special_use' => [
                $specialUseRequired ? 'present' : 'sometimes',
                'nullable',
                Rule::enum(AttributeSpecialUse::class),
                Rule::unique('catalog_attributes', 'special_use')->ignore($attribute?->id),
                function (string $field, mixed $value, Closure $fail) use ($presentation): void {
                    if ($value === AttributeSpecialUse::Fabric->value && $presentation === AttributePresentation::Color->value) {
                        $fail(__('validation.attribute_fabric_not_color'));
                    }
                },
            ],
        ];
    }

    /**
     * Rules of a value of `$attribute`. `$value` is the row being edited (null on creation); on edit
     * the optional fields are only validated when sent.
     *
     * @return array<string, list<mixed>>
     */
    public static function valueRules(CatalogAttribute $attribute, ?AttributeValue $value): array
    {
        $optional = $value === null ? [] : ['sometimes'];
        $tone = $attribute->presentation === AttributePresentation::Color
            ? [...$optional, 'required', 'string', 'regex:'.self::TONE_PATTERN]
            : [...$optional, 'prohibited'];

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('attribute_values', 'name')->where('catalog_attribute_id', $attribute->id)->ignore($value?->id)],
            'description' => [...$optional, 'nullable', 'string', 'max:255'],
            'tone' => $tone,
            'svg_layer' => self::layerRules($optional),
        ];
    }

    /**
     * Rules of a detail location (PRD-007): a unique name and an optional SVG layer. `$location` is
     * the row being edited (null on creation); on edit the layer is only validated when sent.
     *
     * @return array<string, list<mixed>>
     */
    public static function locationRules(?DetailLocation $location): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('detail_locations', 'name')->ignore($location?->id)],
            'svg_layer' => self::layerRules($location === null ? [] : ['sometimes']),
        ];
    }

    /** Upper bound of the default minimum stock (technical limit, design "Technical limits"). */
    public const MIN_STOCK_MAX = 9999;

    /**
     * Rules of the general data of a product (PRD-003, PRD-009, design Decision 10), shared by
     * `StoreProductRequest` and `UpdateProductRequest`. `$product` is the row being edited (null on
     * creation); `$supplyMode` is the submitted mode, which decides whether the default minimum is
     * mandatory (DEC-PRD-46, E-20) or not admitted, and whether custom color is admitted (DEC-PRD-34).
     * The category must be active, except that an edited product keeps its own category even if it was
     * deactivated afterwards.
     *
     * @return array<string, list<mixed>>
     */
    public static function productRules(?Product $product, mixed $supplyMode): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('products', 'name')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'product_category_id' => [
                'required',
                'integer',
                // Nested so the `or` stays inside the group, next to the rule's own `id = ?`.
                Rule::exists('product_categories', 'id')->where(fn (Builder $query) => $query->where(
                    fn (Builder $group) => $group
                        ->where('status', CatalogStatus::Active->value)
                        ->when($product !== null, fn (Builder $inner) => $inner->orWhere('id', $product?->product_category_id)),
                )),
            ],
            'business_line' => ['required', Rule::enum(BusinessLine::class)],
            'supply_mode' => ['required', Rule::enum(SupplyMode::class)],
            'min_stock_default' => $supplyMode === SupplyMode::StockWithMinimum->value
                ? ['required', 'integer', 'between:0,'.self::MIN_STOCK_MAX]
                : ['prohibited'],
            'allows_custom_color' => [
                'nullable',
                'boolean',
                function (string $field, mixed $value, Closure $fail) use ($supplyMode): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && $supplyMode !== SupplyMode::OnDemand->value) {
                        $fail(__('validation.product_custom_color_not_allowed'));
                    }
                },
            ],
            'portal_visible' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Rules of the detail locations and admitted customizations of an edited product (PRD-007,
     * PRD-008, design Decision 10). Both lists replace the stored set when they are sent. A location
     * or service must be active when it is added, but the product keeps the ones it already holds if
     * they were deactivated afterwards; a customization is a product in mode `service` and never the
     * product itself.
     *
     * @return array<string, list<mixed>>
     */
    public static function productRelationRules(Product $product): array
    {
        $heldLocations = $product->detailLocations()->pluck('detail_locations.id')->all();
        $heldServices = $product->customizations()->pluck('products.id')->all();

        return [
            'detail_location_ids' => ['sometimes', 'nullable', 'array'],
            'detail_location_ids.*' => [
                'integer',
                Rule::exists('detail_locations', 'id')->where(fn (Builder $query) => $query->where(
                    fn (Builder $group) => $group
                        ->where('status', CatalogStatus::Active->value)
                        ->orWhereIn('id', $heldLocations),
                )),
            ],
            'customization_ids' => ['sometimes', 'nullable', 'array'],
            'customization_ids.*' => [
                'integer',
                Rule::notIn([$product->id]),
                Rule::exists('products', 'id')->where(fn (Builder $query) => $query
                    ->where('supply_mode', SupplyMode::Service->value)
                    ->where(fn (Builder $group) => $group
                        ->where('status', CatalogStatus::Active->value)
                        ->orWhereIn('id', $heldServices))),
            ],
        ];
    }

    /**
     * Rule of the customizations a combination includes (PRD-008, DEC-PRD-47): products in mode
     * `service`, independent of the ones the product admits as extras.
     *
     * @return array<string, list<mixed>>
     */
    public static function includedCustomizationRules(): array
    {
        return [
            'included_customization_ids' => ['sometimes', 'nullable', 'array'],
            'included_customization_ids.*' => [
                'integer',
                Rule::exists('products', 'id')->where('supply_mode', SupplyMode::Service->value),
            ],
        ];
    }

    /**
     * Messages of `productRelationRules()` and `includedCustomizationRules()`.
     *
     * @return array<string, string>
     */
    public static function relationMessages(): array
    {
        return [
            'detail_location_ids.*.exists' => __('validation.detail_location_unavailable'),
            'customization_ids.*.exists' => __('validation.customization_unavailable'),
            'customization_ids.*.not_in' => __('validation.customization_unavailable'),
            'included_customization_ids.*.exists' => __('validation.included_customization_unavailable'),
        ];
    }

    /**
     * Messages of `productRules()` that differ from the generic ones.
     *
     * @return array<string, string>
     */
    public static function productMessages(): array
    {
        return [
            'name.unique' => __('validation.product_name_unique'),
            'product_category_id.exists' => __('validation.product_category_unavailable'),
            'min_stock_default.prohibited' => __('validation.product_min_stock_not_allowed'),
        ];
    }

    /**
     * Optional SVG layer (DEC-PRD-29): lowercase words joined by hyphens, never a reserved name.
     *
     * @param  list<string>  $prefix  leading rules (`sometimes` on edit)
     * @return list<mixed>
     */
    private static function layerRules(array $prefix): array
    {
        return [...$prefix, 'nullable', 'string', 'max:'.self::LAYER_MAX_LENGTH, 'regex:'.self::LAYER_PATTERN, Rule::notIn(self::RESERVED_LAYERS)];
    }

    /**
     * Field errors for a unique-index violation raised by a concurrent attribute write (the request
     * rules are the first line, the indexes the backstop).
     *
     * @return array<string, string>
     */
    public static function attributeUniqueErrors(UniqueConstraintViolationException $exception): array
    {
        // Only the violated key is inspected: the message also carries the whole SQL statement.
        $key = preg_match("/for key '([^']+)'/", $exception->getMessage(), $matches) === 1 ? $matches[1] : '';

        return match (true) {
            str_contains($key, 'special_use') => ['special_use' => __('validation.attribute_special_use_unique')],
            str_contains($key, 'color_marker') => ['presentation' => __('validation.attribute_color_unique')],
            default => ['name' => __('validation.attribute_name_unique')],
        };
    }
}
