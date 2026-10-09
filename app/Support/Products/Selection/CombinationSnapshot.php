<?php

namespace App\Support\Products\Selection;

/**
 * An active combination of a product (PRD-005). `axes` and `restrictions` map an attribute id to
 * value ids; a missing restriction means the combination admits every value of the product
 * (DEC-PRD-36). `included` are the customizations its price already covers (DEC-PRD-47).
 */
final readonly class CombinationSnapshot
{
    /**
     * @param  array<int, list<int>>  $axes
     * @param  array<int, list<int>>  $restrictions
     * @param  list<ServiceSnapshot>  $included
     */
    public function __construct(
        public int $id,
        public string $code,
        public array $axes,
        public array $restrictions,
        public array $included,
    ) {}
}
