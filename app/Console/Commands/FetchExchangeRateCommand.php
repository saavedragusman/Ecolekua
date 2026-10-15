<?php

namespace App\Console\Commands;

use App\Actions\Pricing\FetchExchangeRate;
use Illuminate\Console\Command;

/**
 * Scheduled fetch of the rate of today (COT-030). A failed fetch is logged and announced by the Action;
 * the run itself ends successfully so the later runs of the day still try.
 */
class FetchExchangeRateCommand extends Command
{
    protected $signature = 'exchange-rate:fetch';

    protected $description = 'Fetch the official exchange rate of today from the API (COT-030)';

    public function handle(FetchExchangeRate $fetchExchangeRate): int
    {
        $rate = $fetchExchangeRate->handle();

        $this->info($rate === null
            ? 'No rate was saved: today already has one or the fetch failed (see the log).'
            : "Rate of {$rate->rate_date->toDateString()} saved: {$rate->value}.");

        return self::SUCCESS;
    }
}
