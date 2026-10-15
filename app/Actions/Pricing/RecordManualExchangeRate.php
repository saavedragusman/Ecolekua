<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ExchangeRateOrigin;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Pricing\InvalidAmount;
use App\Support\Pricing\Money;
use App\Support\Time\OperatingTime;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Records the rate of today by hand (COT-030, E-64, design Decision 11): greater than zero with at most
 * 4 decimals, and no larger than the history can store (`ExchangeRate::MAX_VALUE`). It becomes the rate in force of today and supersedes the effective one (API or manual),
 * which stays in the history; a later API run does not replace it.
 *
 * `effective_date` is unique and derived from `superseded_at`, so the writes follow this order inside
 * one transaction: lock today's effective row; mark it superseded (frees the key); insert the manual
 * row; point the old row to it; audit `pricing.exchange_rate_recorded` with the previous effective rate
 * (or none) and the new one, values as strings.
 *
 * When today had no rate and the API command inserts one before the manual insert, the insert breaks
 * the unique key: the whole transaction is rolled back and run once more, now superseding the API row,
 * so the manual rate always wins. Deadlocks are retried by the transaction attempts.
 */
class RecordManualExchangeRate
{
    public const VALUE_SCALE = 4;

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @throws ValidationException on `value`
     */
    public function handle(string $value, User $actor): ExchangeRate
    {
        $amount = $this->amount($value);

        try {
            return $this->record($amount, $actor);
        } catch (UniqueConstraintViolationException) {
            return $this->record($amount, $actor);
        }
    }

    private function record(BigDecimal $amount, User $actor): ExchangeRate
    {
        return DB::transaction(function () use ($amount, $actor): ExchangeRate {
            $today = OperatingTime::today()->toDateString();
            $previous = ExchangeRate::query()->where('effective_date', $today)->lockForUpdate()->first();

            $previous?->forceFill(['superseded_at' => now()])->save();

            $rate = ExchangeRate::query()->create([
                'rate_date' => $today,
                'value' => Money::toStorage($amount, self::VALUE_SCALE),
                'origin' => ExchangeRateOrigin::Manual,
                'recorded_by' => $actor->id,
            ]);

            $previous?->forceFill(['superseded_by_id' => $rate->id])->save();

            $this->audit->handle(
                AuditAction::ExchangeRateRecorded,
                $actor,
                $rate,
                oldValues: $previous === null ? [] : self::snapshot($previous),
                newValues: self::snapshot($rate),
            );

            return $rate;
        }, attempts: 3);
    }

    /**
     * @throws ValidationException when the value is not an amount greater than zero with at most 4 decimals
     */
    private function amount(string $value): BigDecimal
    {
        try {
            $amount = Money::parse($value, self::VALUE_SCALE);
        } catch (InvalidAmount) {
            $amount = null;
        }

        if ($amount === null || ! $amount->isPositive() || $amount->isGreaterThan(ExchangeRate::MAX_VALUE)) {
            throw ValidationException::withMessages(['value' => Lang::string('validation.exchange_rate_invalid')]);
        }

        return $amount;
    }

    /**
     * @return array{date: string, value: string, origin: string}
     */
    private static function snapshot(ExchangeRate $rate): array
    {
        return [
            'date' => $rate->rate_date->toDateString(),
            'value' => Money::toStorage($rate->value, self::VALUE_SCALE),
            'origin' => $rate->origin->value,
        ];
    }
}
