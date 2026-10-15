<?php

namespace App\Support\Quotations\Calculation;

use App\Enums\PriceTierKind;

/**
 * One tier of a price scheme as the calculation reads it (COT-001): its limits, inclusive, with an open
 * upper limit when `max` is null, and whether it is priced or settled by negotiation. A fixed scheme has a
 * single tier from 1 with no upper limit.
 */
final readonly class TierFacts
{
    public function __construct(
        public int $id,
        public string $label,
        public int $min,
        public ?int $max,
        public PriceTierKind $kind,
    ) {}

    public function contains(int $quantity): bool
    {
        return $quantity >= $this->min && ($this->max === null || $quantity <= $this->max);
    }
}
