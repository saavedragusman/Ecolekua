<?php

namespace App\Support\Quotations\Calculation;

use App\Support\Pricing\Money;
use App\Support\Pricing\RateSnapshot;
use Brick\Math\BigDecimal;

/**
 * Converts a USD amount to its reference amount in Bs (COT-030): the amount times the rate, rounded per
 * amount HALF_UP to 2 decimals (DEC-COT-60). Without a rate there is no amount in Bs (E-65, "sin tasa").
 */
final class BsConverter
{
    public static function convert(BigDecimal $usd, ?RateSnapshot $rate): ?BigDecimal
    {
        if ($rate === null) {
            return null;
        }

        return Money::round($usd->multipliedBy($rate->value));
    }
}
