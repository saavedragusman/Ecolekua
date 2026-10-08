<?php

namespace App\Support\Products\Selection;

use App\Enums\AttributeRole;
use App\Support\Products\CatalogRules;

/**
 * Pure rules of the selection engine (design Decision 14, DT-02): which combinations a partial
 * selection can still reach and whether a complete selection is valid. It works on a
 * `ProductSnapshot` only, with no database access and no translation: errors are reason codes keyed
 * by the field they belong to, and the Action turns them into messages. Prices and stock never
 * enter here.
 *
 * Field keys: `axes.{attributeId}`, `order.{attributeId}`, `details.{i}.location_id|color_value_id`,
 * `customizations.{i}` and `product` for "the selection is not available".
 */
final class SelectionRules
{
    /** Longest note of a custom color (DEC-PRD-34). */
    public const NOTE_MAX_LENGTH = 100;

    /** Value of the color chosen when the client picks the "Personalizado" option (PRD-004). */
    public const CUSTOM_COLOR = 'custom';

    /**
     * Active combinations a partial selection can still reach: compatible with the chosen axes and
     * with at least one active, admitted value on every axis. With a component restriction
     * (`attributeId => admitted value ids`, PRD-010) that value must also be admitted by the
     * component; an attribute without restriction admits everything the product does.
     *
     * @param  array<int, int>  $chosenAxes  attributeId => valueId
     * @param  array<int, list<int>>|null  $componentRestriction
     * @return list<CombinationSnapshot>
     */
    public static function reachableCombinations(ProductSnapshot $snapshot, array $chosenAxes, ?array $componentRestriction = null): array
    {
        $axes = self::attributesWithRole($snapshot, AttributeRole::Axis);

        return array_values(array_filter($snapshot->combinations, function (CombinationSnapshot $combination) use ($snapshot, $chosenAxes, $axes, $componentRestriction): bool {
            foreach ($chosenAxes as $attributeId => $valueId) {
                if (! in_array($valueId, $combination->axes[$attributeId] ?? [], true)) {
                    return false;
                }
            }

            foreach ($axes as $attribute) {
                if (self::admittedValues($snapshot, $attribute, $combination->axes[$attribute->id] ?? [], $componentRestriction) === []) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Values the client does not have to choose because the attribute is left with exactly one
     * admitted active value, after the optional component restriction (PRD-010). The color is left
     * to the client when the product offers the "Personalizado" option, which is a second choice,
     * unless the component restricts the color: then only the listed colors are options.
     *
     * @param  array<int, list<int>>|null  $componentRestriction
     * @return array<int, int> attributeId => valueId
     */
    public static function autoApplied(ProductSnapshot $snapshot, ?array $componentRestriction): array
    {
        $applied = [];

        foreach ($snapshot->attributes as $attribute) {
            if ($attribute->isColor() && $snapshot->admitsCustomColor && ! isset($componentRestriction[$attribute->id])) {
                continue;
            }

            $candidates = self::admittedValues($snapshot, $attribute, $attribute->allowed, $componentRestriction);

            if (count($candidates) === 1) {
                $applied[$attribute->id] = $candidates[0];
            }
        }

        return $applied;
    }

    /**
     * Whether a component of a combo can still be resolved: its product is available, an active
     * combination is reachable under the restriction and every order attribute keeps at least one
     * option. A component that fails makes the combo not offered (PRD-010, DEC-PRD-64, DEC-PRD-70).
     *
     * @param  array<int, list<int>>  $componentRestriction
     */
    public static function componentOffered(ProductSnapshot $snapshot, array $componentRestriction): bool
    {
        if (! self::isAvailable($snapshot) || self::reachableCombinations($snapshot, [], $componentRestriction) === []) {
            return false;
        }

        foreach (self::attributesWithRole($snapshot, AttributeRole::Order) as $attribute) {
            if (! self::hasOptions($snapshot, $attribute, $componentRestriction)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The client sends nothing for an attribute left with exactly one admitted value (PRD-010):
     * fill it in unless the client sent something, which is validated as usual.
     *
     * @param  array<array-key, mixed>  $axes
     * @param  array<array-key, mixed>  $order
     * @param  array<int, list<int>>  $componentRestriction
     * @return array{0: array<array-key, mixed>, 1: array<array-key, mixed>}
     */
    private static function withAutoApplied(ProductSnapshot $snapshot, array $axes, array $order, array $componentRestriction): array
    {
        foreach (self::autoApplied($snapshot, $componentRestriction) as $attributeId => $valueId) {
            $isAxis = count(array_filter($snapshot->attributes, fn (AttributeSnapshot $attribute): bool => $attribute->id === $attributeId && $attribute->role === AttributeRole::Axis)) === 1;
            $sent = $isAxis ? ($axes[$attributeId] ?? null) : ($order[$attributeId] ?? null);

            if ($sent !== null && $sent !== '') {
                continue;
            }

            if ($isAxis) {
                $axes[$attributeId] = $valueId;
            } else {
                $order[$attributeId] = $valueId;
            }
        }

        return [$axes, $order];
    }

    /**
     * Whether an order attribute offers at least one value under the component restriction. The
     * color of a product with fabric is offered by the admitted fabrics (DEC-PRD-35), and the custom
     * color is an option of its own unless the component restricts the color.
     *
     * @param  array<int, list<int>>  $componentRestriction
     */
    private static function hasOptions(ProductSnapshot $snapshot, AttributeSnapshot $attribute, array $componentRestriction): bool
    {
        $restricted = $componentRestriction[$attribute->id] ?? null;
        $fabric = self::fabricAttribute($snapshot);

        if ($attribute->isColor() && $snapshot->admitsCustomColor && $restricted === null) {
            return true;
        }

        if (! $attribute->isColor() || $fabric === null) {
            return self::admittedValues($snapshot, $attribute, $attribute->allowed, $componentRestriction) !== [];
        }

        foreach (self::admittedValues($snapshot, $fabric, $fabric->allowed, $componentRestriction) as $fabricValue) {
            foreach ($snapshot->fabricColors[$fabricValue] ?? [] as $colorId) {
                if (($snapshot->values[$colorId]->active ?? false) && ($restricted === null || in_array($colorId, $restricted, true))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validates a complete selection (PRD-011 rules 1 to 4) and resolves it to its combination.
     * Returns the resolved selection, or the reason codes by field key when it is not valid. The
     * product must be available first (rule 1); the rest of the problems are reported together.
     *
     * @param  array<string, mixed>  $selection  `axes`, `order`, `custom_color`, `details`, `customizations`
     * @param  list<ValueSnapshot>  $palette  active values of the color attribute, for the details
     * @param  array<int, list<int>>|null  $componentRestriction  set for a component of a combo (PRD-010): the values
     *                                                            it admits per attribute, and the only admitted value of an attribute is applied
     *                                                            when the client sends none (DEC-PRD-81); null for a product alone
     * @return ResolvedSelection|array<string, string>
     */
    public static function validate(ProductSnapshot $snapshot, array $selection, array $palette = [], ?array $componentRestriction = null): ResolvedSelection|array
    {
        if (! self::isAvailable($snapshot)) {
            return ['product' => 'selection_unavailable'];
        }

        $errors = [];
        $axesInput = self::map($selection['axes'] ?? null);
        $orderInput = self::map($selection['order'] ?? null);

        if ($componentRestriction !== null) {
            [$axesInput, $orderInput] = self::withAutoApplied($snapshot, $axesInput, $orderInput, $componentRestriction);
        }

        $fabric = self::fabricAttribute($snapshot);
        $axisValues = [];
        $orderChoices = [];

        foreach ($snapshot->attributes as $attribute) {
            if ($attribute->role === AttributeRole::Axis) {
                $choice = self::plainValue($snapshot, $attribute, $axesInput[$attribute->id] ?? null, $componentRestriction);

                if (is_int($choice)) {
                    $axisValues[$attribute->id] = $choice;
                } else {
                    $errors['axes.'.$attribute->id] = $choice;
                }

                continue;
            }

            $input = $orderInput[$attribute->id] ?? null;
            $fabricValue = $fabric === null ? null : ($axisValues[$fabric->id] ?? null);
            $choice = $attribute->isColor()
                ? self::colorChoice($snapshot, $attribute, $input, $selection['custom_color'] ?? null, $fabric !== null, $fabricValue, $componentRestriction)
                : self::plainValue($snapshot, $attribute, $input, $componentRestriction);

            if (is_string($choice)) {
                $errors['order.'.$attribute->id] = $choice;
            } else {
                $orderChoices[$attribute->id] = $choice;
            }
        }

        // DEC-PRD-77: an attribute the product does not declare in that role is an error, never ignored.
        $errors = [...$errors, ...self::undeclared($snapshot, 'axes', $axesInput, AttributeRole::Axis), ...self::undeclared($snapshot, 'order', $orderInput, AttributeRole::Order)];

        $combination = null;

        if (count($axisValues) === count(self::attributesWithRole($snapshot, AttributeRole::Axis))) {
            $reachable = self::reachableCombinations($snapshot, $axisValues, $componentRestriction);

            if (count($reachable) === 1) {
                $combination = $reachable[0];
            } else {
                $errors['product'] = $reachable === [] ? 'selection_unavailable' : 'selection_ambiguous';
            }
        }

        if ($combination !== null) {
            foreach (self::restrictionErrors($snapshot, $combination, $orderChoices, $fabric !== null) as $key => $code) {
                $errors[$key] = $code;
            }
        }

        [$details, $detailErrors] = self::details($snapshot, $selection['details'] ?? null, $palette);
        [$customizations, $customizationErrors] = self::customizations($snapshot, $selection['customizations'] ?? null);
        $errors = [...$errors, ...$detailErrors, ...$customizationErrors];

        if ($errors !== [] || $combination === null) {
            return $errors;
        }

        $axisRows = self::axisRows($snapshot, $axisValues);

        return new ResolvedSelection(
            kind: 'product',
            code: $combination->code,
            combinationId: $combination->id,
            productId: $snapshot->id,
            productName: $snapshot->name,
            descriptiveName: implode(' · ', [$snapshot->name, ...array_column($axisRows, 'value')]),
            axes: $axisRows,
            order: self::orderRows($snapshot, $orderChoices),
            details: $details,
            customizations: $customizations,
            includedCustomizations: array_map(fn (ServiceSnapshot $service): array => ['id' => $service->id, 'name' => $service->name], $combination->included),
            requiresAdvisor: array_filter($orderChoices, 'is_array') !== [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $input  `attributeId => value` as sent under `$prefix`
     * @return array<string, string>
     */
    private static function undeclared(ProductSnapshot $snapshot, string $prefix, array $input, AttributeRole $role): array
    {
        $declared = array_map(fn (AttributeSnapshot $attribute): int => $attribute->id, self::attributesWithRole($snapshot, $role));
        $errors = [];

        foreach (array_keys($input) as $key) {
            if (! in_array($key, $declared, true)) {
                $errors["$prefix.$key"] = 'selection_attribute_not_declared';
            }
        }

        return $errors;
    }

    /**
     * Rule 1 (PRD-011, DEC-PRD-51): the product and its category are active, and so is every
     * attribute the product declares.
     */
    private static function isAvailable(ProductSnapshot $snapshot): bool
    {
        if (! $snapshot->active || ! $snapshot->categoryActive) {
            return false;
        }

        foreach ($snapshot->attributes as $attribute) {
            if (! $attribute->active) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<AttributeSnapshot>
     */
    private static function attributesWithRole(ProductSnapshot $snapshot, AttributeRole $role): array
    {
        return array_values(array_filter($snapshot->attributes, fn (AttributeSnapshot $attribute): bool => $attribute->role === $role));
    }

    private static function fabricAttribute(ProductSnapshot $snapshot): ?AttributeSnapshot
    {
        foreach ($snapshot->attributes as $attribute) {
            if ($attribute->isFabric()) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * The ids among `$valueIds` that the product admits for the attribute, that are active and that
     * the optional component restriction admits.
     *
     * @param  list<int>  $valueIds
     * @param  array<int, list<int>>|null  $componentRestriction
     * @return list<int>
     */
    private static function admittedValues(ProductSnapshot $snapshot, AttributeSnapshot $attribute, array $valueIds, ?array $componentRestriction): array
    {
        $restricted = $componentRestriction[$attribute->id] ?? null;

        return array_values(array_filter($valueIds, fn (int $valueId): bool => in_array($valueId, $attribute->allowed, true)
            && ($snapshot->values[$valueId]->active ?? false)
            && ($restricted === null || in_array($valueId, $restricted, true))));
    }

    /**
     * A value of an axis or of an order attribute other than the color: present, admitted by the
     * product and active, and inside the component restriction when there is one. Returns its id,
     * or the reason code.
     *
     * @param  array<int, list<int>>|null  $componentRestriction
     */
    private static function plainValue(ProductSnapshot $snapshot, AttributeSnapshot $attribute, mixed $input, ?array $componentRestriction): int|string
    {
        if ($input === null || $input === '') {
            return 'selection_value_required';
        }

        $valueId = self::id($input);

        if ($valueId === null || self::admittedValues($snapshot, $attribute, [$valueId], null) === []) {
            return 'selection_value_not_allowed';
        }

        return self::admittedValues($snapshot, $attribute, [$valueId], $componentRestriction) === [] ? 'selection_component_restricted' : $valueId;
    }

    /**
     * The color of the garment: one offered by the chosen fabric or, without fabric, one of the
     * product, or the custom color when the product admits it (PRD-004, E-43, E-44, E-49, E-50).
     * Returns the value id, the custom color as `{tone, note}`, or the reason code. When the fabric
     * itself is invalid its own error stands and the offered colors cannot be checked. A component
     * of a combo narrows the colors further, with or without fabric (DEC-PRD-44, DEC-PRD-65).
     *
     * @param  array<int, list<int>>|null  $componentRestriction
     * @return int|string|array{tone: string, note: string|null}
     */
    private static function colorChoice(ProductSnapshot $snapshot, AttributeSnapshot $attribute, mixed $input, mixed $customColor, bool $declaresFabric, ?int $fabricValue, ?array $componentRestriction): int|string|array
    {
        if ($input === null || $input === '') {
            return 'selection_value_required';
        }

        if ($input === self::CUSTOM_COLOR) {
            $custom = self::customColor($snapshot, $customColor);

            // A component that lists its colors admits only those: the custom color is not one of them.
            return is_array($custom) && isset($componentRestriction[$attribute->id]) ? 'selection_component_restricted' : $custom;
        }

        $valueId = self::id($input);
        $value = $valueId === null ? null : ($snapshot->values[$valueId] ?? null);

        if ($valueId === null || $value === null || ! $value->active || $value->attributeId !== $attribute->id) {
            return 'selection_color_not_offered';
        }

        $offered = $declaresFabric
            ? $fabricValue === null || in_array($valueId, $snapshot->fabricColors[$fabricValue] ?? [], true)
            : in_array($valueId, $attribute->allowed, true);

        if (! $offered) {
            return 'selection_color_not_offered';
        }

        $restricted = $componentRestriction[$attribute->id] ?? null;

        return $restricted === null || in_array($valueId, $restricted, true) ? $valueId : 'selection_component_restricted';
    }

    /**
     * @return string|array{tone: string, note: string|null}
     */
    private static function customColor(ProductSnapshot $snapshot, mixed $customColor): string|array
    {
        if (! $snapshot->admitsCustomColor) {
            return 'selection_custom_color_not_admitted';
        }

        $tone = is_array($customColor) && is_string($customColor['tone'] ?? null) ? $customColor['tone'] : null;

        if ($tone === null || preg_match(CatalogRules::TONE_PATTERN, $tone) !== 1) {
            return 'selection_custom_tone_invalid';
        }

        // A note that is not text, or only blanks, counts as no note.
        $note = is_string($customColor['note'] ?? null) ? trim($customColor['note']) : '';

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            return 'selection_custom_note_too_long';
        }

        return ['tone' => (string) CatalogRules::normalizeTone($tone), 'note' => $note === '' ? null : $note];
    }

    /**
     * Order values must sit inside the restriction of the resolved combination (DEC-PRD-36, E-54).
     * It does not apply to the color of a product with fabric, which comes from the fabric chosen,
     * nor to the custom color.
     *
     * @param  array<int, int|array{tone: string, note: string|null}>  $orderChoices
     * @return array<string, string>
     */
    private static function restrictionErrors(ProductSnapshot $snapshot, CombinationSnapshot $combination, array $orderChoices, bool $declaresFabric): array
    {
        $errors = [];

        foreach ($snapshot->attributes as $attribute) {
            $choice = $orderChoices[$attribute->id] ?? null;
            $restriction = $combination->restrictions[$attribute->id] ?? null;

            if (! is_int($choice) || $restriction === null || ($attribute->isColor() && $declaresFabric)) {
                continue;
            }

            if (! in_array($choice, $restriction, true)) {
                $errors['order.'.$attribute->id] = 'selection_value_restricted';
            }
        }

        return $errors;
    }

    /**
     * Rule 4: each location is one the product admits and its color is an active palette value; the
     * custom color is never a palette value (E-19, E-50).
     *
     * @param  list<ValueSnapshot>  $palette
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private static function details(ProductSnapshot $snapshot, mixed $input, array $palette): array
    {
        $locations = [];
        $colors = [];

        foreach ($snapshot->detailLocations as $location) {
            $locations[$location->id] = $location;
        }

        foreach ($palette as $color) {
            $colors[$color->id] = $color;
        }

        $rows = [];
        $errors = [];
        $seen = [];

        foreach (array_values(self::map($input)) as $position => $detail) {
            $detail = self::map($detail);
            $locationId = self::id($detail['location_id'] ?? null);
            $colorId = self::id($detail['color_value_id'] ?? null);
            $location = $locationId === null ? null : ($locations[$locationId] ?? null);
            $color = $colorId === null ? null : ($colors[$colorId] ?? null);

            if ($location === null) {
                $errors["details.$position.location_id"] = 'selection_location_not_admitted';
            } elseif (isset($seen[$location->id])) {
                // DEC-PRD-78: a location has one color; the repeated entry is the one reported.
                $errors["details.$position.location_id"] = 'selection_location_repeated';
            }

            if ($location !== null) {
                $seen[$location->id] = true;
            }

            if ($color === null || ! $color->active) {
                $errors["details.$position.color_value_id"] = 'selection_location_color_invalid';
            }

            if ($location !== null && $color !== null && $color->active) {
                $rows[] = [
                    'location_id' => $location->id,
                    'location' => $location->name,
                    'color' => ['id' => $color->id, 'name' => $color->name, 'tone' => $color->tone],
                ];
            }
        }

        return [$rows, $errors];
    }

    /**
     * Rule 5: each extra customization is a service the product admits and that is active. The ones
     * a combination includes are returned separately and are not offered as extras (E-34, E-67).
     *
     * @return array{0: list<array{id: int, name: string}>, 1: array<string, string>}
     */
    private static function customizations(ProductSnapshot $snapshot, mixed $input): array
    {
        $admitted = [];

        foreach ($snapshot->customizations as $service) {
            if ($service->active) {
                $admitted[$service->id] = $service;
            }
        }

        $rows = [];
        $errors = [];
        $seen = [];

        foreach (array_values(self::map($input)) as $position => $serviceId) {
            $id = self::id($serviceId);
            $service = $id === null ? null : ($admitted[$id] ?? null);

            if ($service === null) {
                $errors["customizations.$position"] = 'selection_customization_not_admitted';

                continue;
            }

            // DEC-PRD-78: the quantity of customizations is defined by 004, not by repeating an entry.
            if (isset($seen[$service->id])) {
                $errors["customizations.$position"] = 'selection_customization_repeated';

                continue;
            }

            $seen[$service->id] = true;

            $rows[] = ['id' => $service->id, 'name' => $service->name];
        }

        return [$rows, $errors];
    }

    /**
     * @param  array<int, int>  $axisValues
     * @return list<array{attribute_id: int, attribute: string, value_id: int, value: string}>
     */
    private static function axisRows(ProductSnapshot $snapshot, array $axisValues): array
    {
        $rows = [];

        foreach (self::attributesWithRole($snapshot, AttributeRole::Axis) as $attribute) {
            $valueId = $axisValues[$attribute->id];
            $rows[] = ['attribute_id' => $attribute->id, 'attribute' => $attribute->name, 'value_id' => $valueId, 'value' => $snapshot->values[$valueId]->name];
        }

        return $rows;
    }

    /**
     * @param  array<int, int|array{tone: string, note: string|null}>  $orderChoices
     * @return list<array<string, mixed>>
     */
    private static function orderRows(ProductSnapshot $snapshot, array $orderChoices): array
    {
        $rows = [];

        foreach (self::attributesWithRole($snapshot, AttributeRole::Order) as $attribute) {
            $choice = $orderChoices[$attribute->id];
            $base = ['attribute_id' => $attribute->id, 'attribute' => $attribute->name];

            if (is_array($choice)) {
                $rows[] = [...$base, 'value_id' => null, 'value' => null, 'custom' => true, 'tone' => $choice['tone'], 'note' => $choice['note']];

                continue;
            }

            $value = $snapshot->values[$choice];
            $rows[] = [...$base, 'value_id' => $value->id, 'value' => $value->name, ...($attribute->isColor() ? ['tone' => $value->tone] : [])];
        }

        return $rows;
    }

    /**
     * An id sent as an integer or as digits; anything else is not an id.
     */
    private static function id(mixed $input): ?int
    {
        if (is_int($input)) {
            return $input;
        }

        return is_string($input) && ctype_digit($input) ? (int) $input : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $input): array
    {
        return is_array($input) ? $input : [];
    }
}
