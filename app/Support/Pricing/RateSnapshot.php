<?php

namespace App\Support\Pricing;

use App\Enums\ExchangeRateOrigin;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The exchange rate a calculation or an issue uses (COT-030): its value in Bs per USD, the day it
 * belongs to and how it was obtained, so the quotation can show its date.
 */
final readonly class RateSnapshot
{
    public function __construct(
        public BigDecimal $value,
        public CarbonImmutable $date,
        public ExchangeRateOrigin $origin,
    ) {}
}
