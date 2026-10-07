<?php

namespace App\Support\Products;

use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;

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
