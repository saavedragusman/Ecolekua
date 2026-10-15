<?php

namespace App\Support\Quotations\Calculation;

use App\Support\Pricing\Money;
use App\Support\Pricing\RateSnapshot;
use Brick\Math\BigDecimal;

/**
 * The result of a quotation calculation (COT-009 output): the lines, the pieces by business line, the total of
 * the priced lines, the required advance with its breakdown (COT-010), the rate in force with the total and the
 * advance in Bs (COT-030, "sin tasa" when none is stored), whether the quotation requires an advisor and why,
 * and whether it is complete (no error, no line without price, no line waiting for an advisor). Amounts travel
 * as `{value, display}` strings, never as numbers (DT-01).
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
        public BigDecimal $advancePercentage,
        public AdvanceBreakdown $advance,
        public ?RateSnapshot $rate,
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
        return $this->reasons() !== [];
    }

    /**
     * The distinct reasons the quotation requires an advisor, in line order.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        $reasons = [];

        foreach ($this->lines as $line) {
            if ($line->requiresAdvisor && $line->reason !== null && ! in_array($line->reason, $reasons, true)) {
                $reasons[] = $line->reason;
            }
        }

        return $reasons;
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
        $total = $this->total();

        return [
            'lines' => array_map(fn (LineResult $line): array => $line->toArray(), $this->lines),
            'pieces_by_line' => $this->piecesByLine,
            'total' => self::money($total),
            'advance_percentage' => Money::toStorage($this->advancePercentage, 2),
            'required_advance' => self::money($this->advance->required),
            'breakdown' => [
                'automatic' => self::money($this->advance->automatic),
                'stock' => self::money($this->advance->stock),
                'agreed' => self::money($this->advance->agreed),
            ],
            'rate' => $this->rate === null ? null : [
                'value' => Money::toStorage($this->rate->value, 2),
                'date' => $this->rate->date->toDateString(),
                'origin' => $this->rate->origin->value,
            ],
            'total_bs' => self::money(BsConverter::convert($total, $this->rate)),
            'advance_bs' => self::money(BsConverter::convert($this->advance->required, $this->rate)),
            'requires_advisor' => $this->requiresAdvisor(),
            'reasons' => $this->reasons(),
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
