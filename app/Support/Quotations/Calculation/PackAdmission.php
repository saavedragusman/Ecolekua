<?php

namespace App\Support\Quotations\Calculation;

use App\Enums\AttributeRole;
use App\Support\Products\ComboMembership;
use App\Support\Products\Selection\ComponentSnapshot;
use App\Support\Products\Selection\ResolvedSelection;
use App\Support\Products\Selection\SelectionRules;
use App\Support\Products\Selection\ValueSnapshot;

/**
 * Whether a pack's component admits a line (DEC-PRD-42 plus the line's own selection, design Decision 15): for
 * every row, the component includes the row's combination on the product's axes (the 003 `ComboMembership`
 * rule) and the row's exact choice resolves, under the component's restriction, to the same combination (the
 * check a combo makes for its components, so a restriction on an order attribute such as color rejects it).
 * Pure over the loaded snapshots.
 */
final class PackAdmission
{
    /**
     * @param  list<array<string, mixed>>  $rowChoices  the input each row was resolved with
     * @param  list<int>  $rowCombinationIds
     * @param  list<ValueSnapshot>  $palette
     */
    public static function admits(ComponentSnapshot $component, array $rowChoices, array $rowCombinationIds, array $palette): bool
    {
        if ($rowChoices === [] || count($rowChoices) !== count($rowCombinationIds)) {
            return false;
        }

        $axisIds = [];

        foreach ($component->product->attributes as $attribute) {
            if ($attribute->role === AttributeRole::Axis) {
                $axisIds[] = $attribute->id;
            }
        }

        $axesOf = [];

        foreach ($component->product->combinations as $combination) {
            $axesOf[$combination->id] = $combination->axes;
        }

        foreach ($rowChoices as $j => $choice) {
            $combinationId = $rowCombinationIds[$j];

            if (! isset($axesOf[$combinationId]) || ! ComboMembership::includes($component->restriction, $axesOf[$combinationId], $axisIds)) {
                return false;
            }

            $resolved = SelectionRules::validate($component->product, $choice, $palette, $component->restriction);

            if (! $resolved instanceof ResolvedSelection || $resolved->combinationId !== $combinationId) {
                return false;
            }
        }

        return true;
    }
}
