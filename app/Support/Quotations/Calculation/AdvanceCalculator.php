<?php

namespace App\Support\Quotations\Calculation;

use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;

/**
 * Computes the required advance of a quotation (COT-010, design Decision 17).
 *
 * - automatic: the advance percent of the subtotals of on-demand and service lines with an automatic price;
 * - stock: the full subtotals of stock-with-minimum, stock-depletable and combo lines with an automatic price;
 * - agreed: the single agreed advance of the negotiated lines, when there are any.
 *
 * The required advance is the exact sum rounded once, HALF_UP to 2 decimals (E-30, DEC-COT-02). The stock
 * and agreed parts already have 2 decimals, so the rounded automatic part plus the other two add up to it.
 */
final class AdvanceCalculator
{
    public static function required(
        BigDecimal $percent,
        BigDecimal $percentBase,
        BigDecimal $fullBase,
        ?BigDecimal $agreed,
    ): AdvanceBreakdown {
        $automatic = Money::percentOf($percent, $percentBase);
        $exact = $automatic->plus($fullBase)->plus($agreed ?? BigDecimal::zero());

        return new AdvanceBreakdown(
            automatic: Money::round($automatic),
            stock: Money::round($fullBase),
            agreed: $agreed === null ? null : Money::round($agreed),
            required: Money::round($exact),
        );
    }

    /** The agreed advance the system proposes: the advance percent of the negotiated subtotal (COT-010, E-62). */
    public static function proposedAgreed(BigDecimal $percent, BigDecimal $negotiatedSubtotal): BigDecimal
    {
        return Money::round(Money::percentOf($percent, $negotiatedSubtotal));
    }
}
