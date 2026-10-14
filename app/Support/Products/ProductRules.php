<?php

namespace App\Support\Products;

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;

/**
 * Pure rules of a product's structure, shared by `SyncProductAttributes` and the initial load
 * (PRD-018) so both apply exactly the same criteria (design Decision 11). It has no database
 * access: callers pass the attributes already resolved.
 */
final class ProductRules
{
    /**
     * Role rule of DEC-PRD-50: the attribute with special use `fabric`, if declared, is an axis, and
     * the attribute with presentation `color`, if declared, is an order attribute. Returns the id of
     * the first offending attribute in declaration order, or null when every role is valid.
     *
     * @param  iterable<array{attribute_id: int, role: AttributeRole, presentation: AttributePresentation, special_use: AttributeSpecialUse|null}>  $declared
     */
    public static function roleViolation(iterable $declared): ?int
    {
        foreach ($declared as $attribute) {
            if (self::violatesRole($attribute['role'], $attribute['presentation'], $attribute['special_use'])) {
                return $attribute['attribute_id'];
            }
        }

        return null;
    }

    private static function violatesRole(AttributeRole $role, AttributePresentation $presentation, ?AttributeSpecialUse $specialUse): bool
    {
        return ($specialUse === AttributeSpecialUse::Fabric && $role !== AttributeRole::Axis)
            || ($presentation === AttributePresentation::Color && $role !== AttributeRole::Order);
    }
}
