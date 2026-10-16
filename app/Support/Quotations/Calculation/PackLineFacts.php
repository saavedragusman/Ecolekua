<?php

namespace App\Support\Quotations\Calculation;

use Brick\Math\BigDecimal;

/**
 * What the pack notice needs to know about a priced product line (COT-009 step 11, design Decision 15):
 * the quantity of the line (the sum of its rows) and the unit price of each row.
 */
final readonly class PackLineFacts
{
    /**
     * @param  list<BigDecimal>  $unitPrices
     */
    public function __construct(
        public int $quantity,
        public array $unitPrices,
    ) {}

    /**
     * The line's single unit price, or null when its rows carry none or more than one distinct value.
     * Two size groups with the same price are one unit price (DEC-COT-54, DEC-COT-59).
     */
    public function singleUnitPrice(): ?BigDecimal
    {
        $single = null;

        foreach ($this->unitPrices as $price) {
            if ($single === null) {
                $single = $price;
            } elseif (! $single->isEqualTo($price)) {
                return null;
            }
        }

        return $single;
    }
}
