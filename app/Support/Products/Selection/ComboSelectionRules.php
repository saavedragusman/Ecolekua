<?php

namespace App\Support\Products\Selection;

/**
 * Pure rules for the selection of a combo (PRD-010, PRD-011 rule 5, design Decision 14): the combo
 * must be offered and each component is validated with the rules of its product plus its own
 * restriction. Like `SelectionRules` it works on snapshots only and returns reason codes keyed by
 * field; every key of a component is prefixed `components.{componentId}.`.
 */
final class ComboSelectionRules
{
    /**
     * A combo is offered when it is active and every component can still be resolved: its product
     * is available and it keeps an option on every attribute (PRD-010, DEC-PRD-64, DEC-PRD-70).
     */
    public static function isOffered(ComboSnapshot $combo): bool
    {
        if (! $combo->active || $combo->components === []) {
            return false;
        }

        foreach ($combo->components as $component) {
            if (! SelectionRules::componentOffered($component->product, $component->restriction)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $selection  `components`: componentId => the selection of that component
     * @param  list<ValueSnapshot>  $palette  active values of the color attribute, for the details
     * @return ResolvedCombo|array<string, string>
     */
    public static function validate(ComboSnapshot $combo, array $selection, array $palette = []): ResolvedCombo|array
    {
        if (! self::isOffered($combo)) {
            return ['combo' => 'selection_unavailable'];
        }

        $sent = is_array($selection['components'] ?? null) ? $selection['components'] : [];
        $errors = [];
        $resolved = [];

        // DEC-PRD-77 by analogy: a component the combo does not have is an error, never ignored.
        $known = array_map(fn (ComponentSnapshot $component): int => $component->id, $combo->components);

        foreach (array_keys($sent) as $componentId) {
            if (! in_array($componentId, $known, true)) {
                $errors["components.$componentId"] = 'selection_component_not_in_combo';
            }
        }

        foreach ($combo->components as $component) {
            $choice = is_array($sent[$component->id] ?? null) ? $sent[$component->id] : [];
            $result = SelectionRules::validate($component->product, $choice, $palette, $component->restriction);

            if (is_array($result)) {
                foreach ($result as $key => $code) {
                    $errors["components.{$component->id}.$key"] = $code;
                }

                continue;
            }

            $resolved[] = ['component_id' => $component->id, 'quantity' => $component->quantity, 'selection' => $result];
        }

        return $errors === [] ? new ResolvedCombo($combo->id, $combo->name, $combo->code, $resolved) : $errors;
    }
}
