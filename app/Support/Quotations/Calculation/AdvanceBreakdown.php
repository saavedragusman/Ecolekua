<?php

namespace App\Support\Quotations\Calculation;

use Brick\Math\BigDecimal;

/**
 * The required advance of a quotation and its three parts (COT-010, design Decision 17): the percent of the
 * on-demand and service lines, the full subtotal of the stock and combo lines, and the agreed advance of the
 * negotiated lines (null when there is none). Every amount has 2 decimals and the parts add up to the
 * required advance.
 */
final readonly class AdvanceBreakdown
{
    public function __construct(
        public BigDecimal $automatic,
        public BigDecimal $stock,
        public ?BigDecimal $agreed,
        public BigDecimal $required,
    ) {}
}
