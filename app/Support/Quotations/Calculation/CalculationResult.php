<?php

namespace App\Support\Quotations\Calculation;

use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;

/**
 * The result of a quotation calculation (COT-009 output): the lines, the pieces by business line, the total of
 * the priced lines, whether the quotation requires an advisor and whether it is complete (no error, no line
 * without price, no line waiting for an advisor). Amounts travel as `{value, display}` strings, never as
 * numbers (DT-01).
 */
final readonly class CalculationResult
{
    /**
     * @param  list<LineResult>  $lines
     * @param  array<string, int>  $piecesByLine
     */
    public function __construct(
        public array $lines,
        public array $piecesByLine,
    ) {}

    public function total(): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->lines as $line) {
            if ($line->addsToTotal() && $line->subtotal !== null) {
                $total = $total->plus($line->subtotal);
            }
        }

        return Money::round($total);
    }

    public function requiresAdvisor(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->requiresAdvisor) {
                return true;
            }
        }

        return false;
    }

    public function complete(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->addsToTotal()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (LineResult $line): array => $line->toArray(), $this->lines),
            'pieces_by_line' => $this->piecesByLine,
            'total' => self::money($this->total()),
            'requires_advisor' => $this->requiresAdvisor(),
            'complete' => $this->complete(),
        ];
    }

    /**
     * An amount as the screens and the API receive it: the canonical value and its es-VE display.
     *
     * @return array{value: string, display: string}|null
     */
    public static function money(?BigDecimal $amount): ?array
    {
        if ($amount === null) {
            return null;
        }

        $rounded = Money::round($amount);

        return ['value' => Money::toStorage($rounded, 2), 'display' => Money::display($rounded)];
    }
}
