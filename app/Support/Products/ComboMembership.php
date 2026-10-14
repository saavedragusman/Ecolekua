<?php

namespace App\Support\Products;

/**
 * Pure rule of DEC-PRD-42 (PRD-014, design Decision 17): a combination is part of a combo when its
 * product is a component of the combo and the customer could choose that combination in the combo,
 * that is, when on every axis of the product the component is unrestricted or admits at least one
 * value the combination has. Restrictions on attributes that are not axes (order attributes such as
 * color) do not take part. It has no database access: the caller passes the component restrictions
 * and the axes already resolved.
 */
final class ComboMembership
{
    /**
     * @param  array<int, list<int>>  $componentRestrictions  attributeId => valueIds the component admits; a missing or empty entry means unrestricted (DEC-PRD-44)
     * @param  array<int, list<int>>  $combinationAxes  attributeId => valueIds of the combination
     * @param  list<int>  $axisAttributeIds  attributes the product declares as axes
     */
    public static function includes(array $componentRestrictions, array $combinationAxes, array $axisAttributeIds): bool
    {
        foreach ($axisAttributeIds as $attributeId) {
            $admitted = $componentRestrictions[$attributeId] ?? [];

            if ($admitted === []) {
                continue;
            }

            if (array_intersect($admitted, $combinationAxes[$attributeId] ?? []) === []) {
                return false;
            }
        }

        return true;
    }
}
