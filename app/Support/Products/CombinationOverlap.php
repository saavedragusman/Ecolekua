<?php

namespace App\Support\Products;

/**
 * Pure overlap rule of DEC-PRD-39 (design Decision 5): two combinations of a product overlap when,
 * for every axis attribute of the product, their value sets intersect, because then at least one
 * selection matches both. A product without axes overlaps vacuously, so it admits a single active
 * combination (note N-2). It has no database access: the Actions and the initial load pass the axes
 * of the other active combinations already resolved.
 */
final class CombinationOverlap
{
    /**
     * Id of the first of the other combinations that overlaps the candidate, in iteration order, or
     * null when none does. The candidate's attributes are the axes of the product.
     *
     * @param  array<int, list<int>>  $candidateAxes  attributeId => valueIds
     * @param  iterable<int, array<int, list<int>>>  $others  combinationId => axes
     */
    public static function firstOverlap(array $candidateAxes, iterable $others): ?int
    {
        foreach ($others as $combinationId => $axes) {
            if (self::intersectsOnEveryAxis($candidateAxes, $axes)) {
                return $combinationId;
            }
        }

        return null;
    }

    /**
     * @param  array<int, list<int>>  $candidateAxes
     * @param  array<int, list<int>>  $axes
     */
    private static function intersectsOnEveryAxis(array $candidateAxes, array $axes): bool
    {
        foreach ($candidateAxes as $attributeId => $valueIds) {
            if (array_intersect($valueIds, $axes[$attributeId] ?? []) === []) {
                return false;
            }
        }

        return true;
    }
}
