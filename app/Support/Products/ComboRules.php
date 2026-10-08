<?php

namespace App\Support\Products;

use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Models\Combo;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rules of a combo shared by the form requests, `CreateCombo`, `UpdateCombo` and, later, the initial
 * load (PRD-018), so all of them apply exactly the same criteria (design Decision 15). `rules()` is
 * the shape of the payload; `validateComponents()` checks every component against the product it
 * names and runs inside the Action, under the product locks.
 */
final class ComboRules
{
    public const NAME_MAX_LENGTH = 150;

    /** Technical upper bound of the quantity of a component (design "Limits"); the lower bound is the spec's. */
    public const QUANTITY_MAX = 999;

    /**
     * Shape of the payload of a create or edit. `$registryId` is the registry row of the combo being
     * edited (null on creation), so a combo can keep its own code (DEC-PRD-14, E-08); `$combo` is the
     * one being edited, whose own name is not a duplicate (E-64).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?int $registryId, ?Combo $combo = null): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH, Rule::unique('combos', 'name')->ignore($combo?->id)],
            'code' => CombinationRules::codeRules($registryId),
            'portal_visible' => ['nullable', 'boolean'],
            'components' => ['required', 'array', 'min:1'],
            'components.*' => ['array'],
            'components.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'components.*.quantity' => ['required', 'integer', 'between:1,'.self::QUANTITY_MAX],
            'components.*.values' => ['sometimes', 'nullable', 'array'],
            'components.*.values.*' => ['array'],
            'components.*.values.*.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.unique' => __('validation.combo_name_unique'),
            'code.unique' => __('validation.combination_code_unique'),
            'code.regex' => __('validation.combination_code_format'),
            'components.required' => __('validation.combo_components_required'),
            'components.min' => __('validation.combo_components_required'),
            'components.*.product_id.exists' => __('validation.combo_component_product_unknown'),
        ];
    }

    /**
     * Checks every component against its product and returns them normalized (integer ids, no
     * repeated value, no empty list):
     *
     * - the product is in the diaper line (E-62, DEC-PRD-43) and is not a service (E-22);
     * - each restricted attribute is declared by the product, as an axis or as an order attribute
     *   (DEC-PRD-44), and the values are among the ones the product admits for it;
     * - for the color of a product that declares the fabric, which admits no colors of its own, the
     *   values are among the colors offered by the fabrics the product admits (E-22, DEC-PRD-35).
     *
     * - the product is active when added as a component; a product that is already a component of the
     *   combo being edited (`$heldProductIds`) is kept even if it was deactivated since (DEC-PRD-64).
     *
     * The same product may appear in several components (N-3). An attribute without values is not
     * restricted. Callers hold the locks of the products.
     *
     * @param  array<array-key, mixed>  $components  shaped by `rules()`
     * @param  list<int>  $heldProductIds  products of the components the combo being edited already has
     * @return list<array{product_id: int, quantity: int, values: array<int, list<int>>}>
     *
     * @throws ValidationException on `components.{i}.product_id` or `components.{i}.values.{attributeId}`
     */
    public static function validateComponents(array $components, array $heldProductIds = []): array
    {
        $components = array_values($components);
        $products = Product::query()->whereIn('id', array_map(fn (mixed $component): int => (int) $component['product_id'], $components))->get()->keyBy('id');

        $normalized = [];
        $errors = [];

        foreach ($components as $index => $component) {
            $product = $products->get((int) $component['product_id']);
            $values = CombinationRules::normalize((array) ($component['values'] ?? []));

            $normalized[] = ['product_id' => (int) $component['product_id'], 'quantity' => (int) $component['quantity'], 'values' => $values];

            if ($product === null) {
                $errors["components.{$index}.product_id"] = __('validation.combo_component_product_unknown');
            } elseif ($product->business_line !== BusinessLine::Diapers) {
                $errors["components.{$index}.product_id"] = __('validation.combo_component_not_diapers');
            } elseif ($product->supply_mode === SupplyMode::Service) {
                $errors["components.{$index}.product_id"] = __('validation.combo_component_service');
            } elseif ($product->status !== CatalogStatus::Active && ! in_array($product->id, $heldProductIds, true)) {
                $errors["components.{$index}.product_id"] = __('validation.combo_component_inactive');
            } else {
                $admitted = self::admittedValues($product);

                foreach ($values as $attributeId => $valueIds) {
                    if (! isset($admitted[$attributeId])) {
                        $errors["components.{$index}.values.{$attributeId}"] = __('validation.combo_component_attribute_unknown');
                    } elseif (array_diff($valueIds, $admitted[$attributeId]) !== []) {
                        $errors["components.{$index}.values.{$attributeId}"] = __('validation.combo_component_value_not_allowed');
                    }
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * The field a duplicate-key failure belongs to: the name or, otherwise, the registry code.
     */
    public static function duplicateKeyError(UniqueConstraintViolationException $exception): ValidationException
    {
        // Only the violated key is inspected: the message also carries the whole SQL statement.
        $key = preg_match("/for key '([^']+)'/", $exception->getMessage(), $matches) === 1 ? $matches[1] : '';

        if (str_contains($key, 'name')) {
            return ValidationException::withMessages(['name' => __('validation.combo_name_unique')]);
        }

        return ValidationException::withMessages(['code' => __('validation.combination_code_unique')]);
    }

    /**
     * Values a combo component may admit, by attribute id, for the attributes the product declares.
     * The color of a product with fabric has no allowed values of its own: its colors are the ones
     * offered by the fabrics the product admits.
     *
     * @return array<int, list<int>>
     */
    private static function admittedValues(Product $product): array
    {
        $declared = ProductCombinations::declared($product);

        $fabricIds = [];

        foreach ($declared as $attribute) {
            if ($attribute['special_use'] === AttributeSpecialUse::Fabric) {
                $fabricIds = $attribute['allowed'];
            }
        }

        $admitted = [];

        foreach ($declared as $attribute) {
            $admitted[$attribute['attribute_id']] = $fabricIds !== [] && $attribute['presentation'] === AttributePresentation::Color
                ? self::offeredColors($fabricIds)
                : $attribute['allowed'];
        }

        return $admitted;
    }

    /**
     * @param  list<int>  $fabricValueIds
     * @return list<int>
     */
    private static function offeredColors(array $fabricValueIds): array
    {
        $ids = DB::table('fabric_offered_colors')
            ->whereIn('fabric_value_id', $fabricValueIds)
            ->distinct()
            ->pluck('color_value_id');

        return array_values($ids->map(fn (mixed $id): int => (int) $id)->all());
    }
}
