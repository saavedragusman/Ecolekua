<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\CommercialParameters;
use App\Models\User;
use App\Support\Pricing\InvalidAmount;
use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Edits the commercial parameters (COT-006, E-11, design Decision 13): the advance percentage, between
 * 0 and 100 with at most 2 decimals, and the validity of quotations in calendar days, from 1 to 365 (the
 * upper bound is technical). New values apply to the quotations issued afterwards, which copy them.
 *
 * Both fields are checked before anything is written. The audit row `pricing.parameters_updated` carries
 * only the fields that changed, the percentage as a canonical string; nothing is written when nothing
 * changed.
 */
class UpdateCommercialParameters
{
    public const VALIDITY_MAX_DAYS = 365;

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @throws ValidationException on `advance_percentage` or `validity_days`
     */
    public function handle(string $advancePercentage, int $validityDays, User $actor): CommercialParameters
    {
        $errors = [];
        $percentage = $this->percentage($advancePercentage);

        if ($percentage === null) {
            $errors['advance_percentage'] = Lang::string('validation.advance_percentage_invalid');
        }

        if ($validityDays < 1 || $validityDays > self::VALIDITY_MAX_DAYS) {
            $errors['validity_days'] = Lang::string('validation.validity_days_invalid');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($percentage, $validityDays, $actor): CommercialParameters {
            $parameters = CommercialParameters::query()->lockForUpdate()->findOrFail(CommercialParameters::ROW_ID);

            $before = self::snapshot($parameters->advance_percentage, $parameters->validity_days);
            $after = self::snapshot($percentage, $validityDays);
            $changed = array_keys(array_diff_assoc($after, $before));

            if ($changed === []) {
                return $parameters;
            }

            $parameters->update([
                'advance_percentage' => $after['advance_percentage'],
                'validity_days' => $validityDays,
                'updated_by' => $actor->id,
            ]);

            $this->audit->handle(
                AuditAction::PricingParametersUpdated,
                $actor,
                $parameters,
                oldValues: array_intersect_key($before, array_flip($changed)),
                newValues: array_intersect_key($after, array_flip($changed)),
            );

            return $parameters;
        });
    }

    /**
     * The exact percentage, or null when it is not an amount between 0 and 100 with at most 2 decimals.
     */
    private function percentage(string $value): ?BigDecimal
    {
        try {
            $percentage = Money::parse($value, 2);
        } catch (InvalidAmount) {
            return null;
        }

        return $percentage->isNegative() || $percentage->isGreaterThan(100) ? null : $percentage;
    }

    /**
     * @return array{advance_percentage: string, validity_days: int}
     */
    private static function snapshot(BigDecimal $percentage, int $validityDays): array
    {
        return ['advance_percentage' => Money::toStorage($percentage, 2), 'validity_days' => $validityDays];
    }
}
