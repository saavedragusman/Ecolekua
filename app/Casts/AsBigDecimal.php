<?php

namespace App\Casts;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a `DECIMAL` column to `BigDecimal` and back (DT-01, design Decision 3), declared as
 * `AsBigDecimal::class.':2'`. Writing a value with more decimals than the scale is a programming
 * error and throws; it never rounds silently. Models never use the `decimal:2` string cast for
 * amounts that take part in arithmetic.
 *
 * @implements CastsAttributes<BigDecimal, BigDecimal|string|int>
 */
final class AsBigDecimal implements CastsAttributes
{
    /**
     * @param  non-negative-int  $scale  Decimals of the column
     */
    public function __construct(private readonly int $scale = 2) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        return BigDecimal::of($value)->toScale($this->scale);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RoundingNecessaryException When the value has more decimals than the scale
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return BigDecimal::of($value)->toScale($this->scale)->toString();
    }
}
