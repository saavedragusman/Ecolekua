<?php

namespace App\Support\Products\Selection;

/**
 * One component of a combo with the snapshot of its product (PRD-010). `restriction` maps an
 * attribute id to the value ids the component admits; an attribute without an entry is not
 * restricted (DEC-PRD-44).
 */
final readonly class ComponentSnapshot
{
    /**
     * @param  array<int, list<int>>  $restriction
     */
    public function __construct(
        public int $id,
        public int $quantity,
        public array $restriction,
        public ProductSnapshot $product,
    ) {}
}
