<?php

namespace App\Support\Products;

/**
 * Signature of the axis values of a combination (design Decision 5): the SHA-256 of the sorted
 * `attributeId:valueId` pairs, independent of the order of values and attributes. The database
 * derives `active_signature` from it and its unique index blocks exact duplicates among the active
 * combinations of a product (DEC-PRD-39) even if the overlap check is bypassed.
 */
final class AxisSignature
{
    /**
     * @param  array<int, list<int>>  $axes  attributeId => valueIds
     */
    public static function of(array $axes): string
    {
        $pairs = [];

        foreach ($axes as $attributeId => $valueIds) {
            foreach (array_unique($valueIds) as $valueId) {
                $pairs[] = $attributeId.':'.$valueId;
            }
        }

        sort($pairs);

        return hash('sha256', implode(',', $pairs));
    }
}
