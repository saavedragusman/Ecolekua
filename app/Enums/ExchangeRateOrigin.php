<?php

namespace App\Enums;

/**
 * How an exchange rate entered the system (COT-030): fetched from the API or typed manually. The
 * backed value is stored in `exchange_rates.origin` and `quotations.rate_origin`.
 */
enum ExchangeRateOrigin: string
{
    case Api = 'api';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'API',
            self::Manual => 'Manual',
        };
    }
}
