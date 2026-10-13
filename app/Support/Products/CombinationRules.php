<?php

namespace App\Support\Products;

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Models\Combination;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rules of a commercial combination shared by the form requests, `CreateCombination`,
 * `UpdateCombination` and, later, the initial load (PRD-018), so all of them apply exactly the same
 * criteria (design Decisions 4 and 12). The structural checks are pure: callers pass the attributes
 * the product declares already resolved, as for `ProductRules`.
 */
final class CombinationRules
{
    /** DT-01: a code is 1 to 30 characters, stored as typed after trimming. */
    public const CODE_MAX_LENGTH = 30;

    /** DT-01: no whitespace inside the code. */
    public const CODE_PATTERN = '/^\S+$/';

    public const DESCRIPTION_MAX_LENGTH = 255;

    /**
     * Shape of the payload of a create or edit. The rules that need the structure of the product or
     * a lock (axes, restrictions, overlap) live in the Actions through `validate()`.
     * `$registryId` is the registry row of the combination being edited (null on creation), so a
     * combination can keep its own code (DEC-PRD-01, E-08). `$combination` is the one being edited:
     * the services it already includes stay valid even if deactivated later (DEC-PRD-56).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?int $registryId, ?Combination $combination = null): array
    {
        $heldServices = $combination?->customizations()->pluck('products.id')->values()->all() ?? [];

        return [
            'code' => [
                'required',
                'string',
                'max:'.self::CODE_MAX_LENGTH,
                'regex:'.self::CODE_PATTERN,
                Rule::unique('catalog_codes', 'code')->ignore($registryId),
            ],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'axes' => ['sometimes', 'array'],
            'axes.*' => ['array'],
            'axes.*.*' => ['integer'],
            'restrictions' => ['sometimes', 'nullable', 'array'],
            'restrictions.*' => ['array'],
            'restrictions.*.*' => ['integer'],
            ...CatalogRules::includedCustomizationRules($heldServices),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            ...CatalogRules::relationMessages(),
            'code.unique' => __('validation.combination_code_unique'),
            'code.regex' => __('validation.combination_code_format'),
        ];
    }

    /**
     * Checks axes and restrictions against the structure of the product and returns them normalized
     * (integer ids, no repeated value, no empty list).
     *
     * - every axis of the product has at least one value among its admitted ones (E-10, DEC-PRD-33);
     * - restrictions only apply to order attributes, each a subset of the admitted values (E-56,
     *   DEC-PRD-36), and never to the color of a product that declares the fabric (DEC-PRD-35).
     *
     * @param  list<array{attribute_id: int, role: AttributeRole, presentation: AttributePresentation, special_use: AttributeSpecialUse|null, allowed: list<int>}>  $declared
     * @param  array<array-key, mixed>  $axes  attributeId => valueIds
     * @param  array<array-key, mixed>  $restrictions  attributeId => valueIds
     * @return array{axes: array<int, list<int>>, restrictions: array<int, list<int>>}
     *
     * @throws ValidationException on the field `axes.{attributeId}` or `restrictions.{attributeId}`
     */
    public static function validate(array $declared, array $axes, array $restrictions): array
    {
        $axes = self::normalize($axes);
        $restrictions = self::normalize($restrictions);
        $errors = [];

        $declaresFabric = false;
        $roles = [];
        $allowed = [];
        $colorAttributes = [];

        foreach ($declared as $attribute) {
            $roles[$attribute['attribute_id']] = $attribute['role'];
            $allowed[$attribute['attribute_id']] = $attribute['allowed'];
            $declaresFabric = $declaresFabric || $attribute['special_use'] === AttributeSpecialUse::Fabric;

            if ($attribute['presentation'] === AttributePresentation::Color) {
                $colorAttributes[$attribute['attribute_id']] = true;
            }
        }

        foreach ($roles as $attributeId => $role) {
            if ($role === AttributeRole::Axis && ! isset($axes[$attributeId])) {
                $errors["axes.{$attributeId}"] = __('validation.combination_axis_required');
            }
        }

        foreach ($axes as $attributeId => $valueIds) {
            if (($roles[$attributeId] ?? null) !== AttributeRole::Axis) {
                $errors["axes.{$attributeId}"] = __('validation.combination_axis_unknown');
            } elseif (array_diff($valueIds, $allowed[$attributeId]) !== []) {
                $errors["axes.{$attributeId}"] = __('validation.combination_axis_value_not_allowed');
            }
        }

        foreach ($restrictions as $attributeId => $valueIds) {
            if (($roles[$attributeId] ?? null) !== AttributeRole::Order) {
                $errors["restrictions.{$attributeId}"] = __('validation.combination_restriction_not_order');
            } elseif ($declaresFabric && isset($colorAttributes[$attributeId])) {
                $errors["restrictions.{$attributeId}"] = __('validation.combination_restriction_color_fabric');
            } elseif (array_diff($valueIds, $allowed[$attributeId]) !== []) {
                $errors["restrictions.{$attributeId}"] = __('validation.combination_restriction_value_not_allowed');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['axes' => $axes, 'restrictions' => $restrictions];
    }

    /**
     * The field a duplicate-key failure belongs to: the code registry or, for an exact duplicate of
     * the active axes that slipped past the overlap check, the axes (DEC-PRD-39).
     */
    public static function duplicateKeyError(UniqueConstraintViolationException $exception): ValidationException
    {
        if (str_contains($exception->getMessage(), 'active_signature')) {
            return ValidationException::withMessages(['axes' => __('validation.combination_axes_duplicate')]);
        }

        return ValidationException::withMessages(['code' => __('validation.combination_code_unique')]);
    }

    /**
     * @param  array<array-key, mixed>  $map
     * @return array<int, list<int>>
     */
    private static function normalize(array $map): array
    {
        $normalized = [];

        foreach ($map as $attributeId => $valueIds) {
            $ids = array_values(array_unique(array_map('intval', (array) $valueIds)));

            if ($ids !== []) {
                $normalized[(int) $attributeId] = $ids;
            }
        }

        return $normalized;
    }
}
