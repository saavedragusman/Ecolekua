<?php

namespace App\Support\Products;

use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use Closure;
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
            'svg_layer' => [...$optional, 'nullable', 'string', 'max:'.self::LAYER_MAX_LENGTH, 'regex:'.self::LAYER_PATTERN, Rule::notIn(self::RESERVED_LAYERS)],
        ];
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
