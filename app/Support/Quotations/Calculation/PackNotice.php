<?php

namespace App\Support\Quotations\Calculation;

use Brick\Math\BigDecimal;

/**
 * The informative pack notice of a line (DEC-COT-08): the pack, how many packs fit in the line's quantity
 * and the estimated saving if those units were sold as packs. The line itself is not changed.
 */
final readonly class PackNotice
{
    public function __construct(
        public string $comboCode,
        public string $comboName,
        public int $packQuantity,
        public int $packsFit,
        public BigDecimal $estimatedSaving,
    ) {}
}
