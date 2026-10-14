<?php

namespace App\Actions\Pricing;

use App\Enums\ExchangeRateOrigin;
use App\Events\Pricing\ExchangeRateFetchFailed;
use App\Models\ExchangeRate;
use App\Support\Pricing\RateParser;
use App\Support\Time\OperatingTime;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the official rate of today from the API (COT-030, E-63, DEC-COT-43, design Decision 11). Only
 * the scheduled command calls it; the calculation and the issue read what is stored.
 *
 * - Today is the Caracas day of the run. When today already has an effective rate (API or manual), the
 *   API is not called.
 * - One run makes up to 3 attempts, waiting 2 s and then 10 s; the schedule adds later runs.
 * - On success the `promedio` is saved as the rate of today with origin `api`; `fechaActualizacion` is
 *   kept apart, in UTC, and never changes the date of the rate. API rows are not audited (COT-023).
 * - On failure (no connection, a non-2xx answer, no positive `promedio`) nothing is saved, the failure
 *   is logged and `ExchangeRateFetchFailed` is dispatched.
 * - A manual rate written for today while the API answered wins: the API insert breaks the unique key
 *   and is dropped.
 */
class FetchExchangeRate
{
    /** Waits between the attempts of one run, in milliseconds. */
    public const BACKOFF_MS = [2000, 10000];

    /**
     * The saved rate, or null when nothing was saved (today already had one, or the fetch failed).
     */
    public function handle(): ?ExchangeRate
    {
        $today = OperatingTime::today()->toDateString();

        if (ExchangeRate::query()->where('effective_date', $today)->exists()) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->retry(self::BACKOFF_MS, throw: false)
                ->acceptJson()
                ->get(config('services.exchange_rate.url'));
        } catch (ConnectionException) {
            return $this->fail('connection', null);
        }

        if (! $response->successful()) {
            return $this->fail('status', $response->status());
        }

        $value = RateParser::promedio($response->body());

        if ($value === null) {
            return $this->fail('invalid_rate', $response->status());
        }

        try {
            return ExchangeRate::query()->create([
                'rate_date' => $today,
                'value' => $value->toString(),
                'origin' => ExchangeRateOrigin::Api,
                'source_updated_at' => $this->sourceDate($response->json('fechaActualizacion')),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function fail(string $reason, ?int $status): null
    {
        Log::warning('exchange_rate.fetch_failed', ['reason' => $reason, 'status' => $status]);

        ExchangeRateFetchFailed::dispatch($reason);

        return null;
    }

    /**
     * The update date the source reports, in UTC, or null when it is missing or unreadable.
     */
    private function sourceDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
