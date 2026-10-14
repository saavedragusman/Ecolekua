<?php

namespace App\Actions\Pricing;

use App\Models\ExchangeRate;
use App\Support\Pricing\RateSnapshot;
use App\Support\Time\OperatingTime;
use Carbon\CarbonImmutable;

/**
 * The exchange rate in force (COT-030, design Decision 11): the effective rate of today or, when today
 * has none, the most recent effective one, with its own date. Null when no rate is stored. Nothing here
 * calls the API: the calculation and the issue only read what is stored.
 */
class CurrentExchangeRate
{
    public function get(): ?RateSnapshot
    {
        $rate = ExchangeRate::query()
            ->whereNotNull('effective_date')
            ->where('effective_date', '<=', OperatingTime::today()->toDateString())
            ->orderByDesc('effective_date')
            ->first();

        if ($rate === null) {
            return null;
        }

        return new RateSnapshot($rate->value, CarbonImmutable::parse($rate->rate_date->toDateString()), $rate->origin);
    }
}
