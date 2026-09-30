<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Single place that converts between UTC storage and the operating timezone
 * (`config('app.operating_timezone')`, DEC-019; design Decision 16).
 */
final class OperatingTime
{
    public static function format(CarbonInterface $utc): string
    {
        return CarbonImmutable::instance($utc)->setTimezone(self::timezone())->format('d/m/Y H:i:s');
    }

    /**
     * Inclusive UTC lower bound: local 00:00 of the given `Y-m-d` day.
     */
    public static function dayStartUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone())->utc();
    }

    /**
     * Exclusive UTC upper bound: local 00:00 of the day after the given `Y-m-d` day.
     */
    public static function nextDayStartUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone())->addDay()->utc();
    }

    private static function timezone(): string
    {
        return config('app.operating_timezone');
    }
}
