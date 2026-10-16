<?php

namespace App\Support\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;

/**
 * Exact money arithmetic helpers (DT-01, design Decision 3). Every amount is a `BigDecimal`; no
 * `float` ever touches money. Rounding happens only where the spec says so (DEC-COT-02) and always
 * with `RoundingMode::HalfUp`: `round()` is the only method that rounds, every other one is exact
 * and throws when asked to lose digits.
 */
final class Money
{
    /**
     * Optional minus sign, integer digits and an optional fraction with a single `.` or `,` separator.
     * No exponent, no thousands separator, no plus sign.
     */
    private const PATTERN = '/^(-?)(\d+)(?:[.,](\d+))?$/';

    /**
     * Parses user or file input. The sign is accepted so the caller decides what a negative amount
     * means (for prices it is an error of its own).
     *
     * @throws InvalidAmount When the text is not a plain decimal number or has more decimals than `$maxScale`
     */
    public static function parse(string $input, int $maxScale): BigDecimal
    {
        if (preg_match(self::PATTERN, trim($input), $parts) !== 1) {
            throw new InvalidAmount('The text is not a plain decimal amount.');
        }

        $sign = $parts[1];
        $integer = $parts[2];
        $fraction = $parts[3] ?? '';

        if (strlen($fraction) > $maxScale) {
            throw new InvalidAmount("The amount has more than {$maxScale} decimals.");
        }

        return BigDecimal::of($sign.$integer.($fraction === '' ? '' : '.'.$fraction));
    }

    /**
     * Rounds half up to `$scale` decimals (DEC-COT-02).
     *
     * @param  non-negative-int  $scale
     */
    public static function round(BigDecimal $amount, int $scale = 2): BigDecimal
    {
        return $amount->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Canonical string stored in DECIMAL columns, JSON columns and audit payloads (`"40.00"`).
     *
     * @param  non-negative-int  $scale
     *
     * @throws RoundingNecessaryException When the amount has more decimals than `$scale`
     */
    public static function toStorage(BigDecimal $amount, int $scale): string
    {
        return $amount->toScale($scale)->toString();
    }

    /**
     * es-VE display: `.` for thousands and `,` for decimals (`1.150,00`).
     *
     * @param  non-negative-int  $scale
     *
     * @throws RoundingNecessaryException When the amount has more decimals than `$scale`
     */
    public static function display(BigDecimal|string $amount, int $scale = 2): string
    {
        $canonical = self::toStorage(BigDecimal::of($amount), $scale);

        $sign = str_starts_with($canonical, '-') ? '-' : '';
        $parts = explode('.', ltrim($canonical, '-'), 2);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';

        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);

        return $sign.$grouped.($fraction === '' ? '' : ','.$fraction);
    }

    /**
     * `$percent`% of `$amount`, exact: the result keeps every decimal of the product.
     */
    public static function percentOf(BigDecimal $percent, BigDecimal $amount): BigDecimal
    {
        return $amount->multipliedBy($percent)->withPointMovedLeft(2);
    }
}
