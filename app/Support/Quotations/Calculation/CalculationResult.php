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
        public ?BigDecimal $proposedAgreedAdvance = null,
        public bool $agreedAdvanceDiscarded = false,
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

    /** The subtotal of the negotiated lines, or null when there is none (COT-010). */
    public function negotiatedSubtotal(): ?BigDecimal
    {
        return self::negotiatedSubtotalOf($this->lines);
    }

    /**
     * The subtotal of the lines priced by a negotiated or confirmed price, or null when there is none: the base
     * of the agreed advance (COT-010, DEC-COT-11).
     *
     * @param  list<LineResult>  $lines
     */
    public static function negotiatedSubtotalOf(array $lines): ?BigDecimal
    {
        $subtotal = null;

        foreach ($lines as $line) {
            if ($line->negotiated && $line->addsToTotal() && $line->subtotal !== null) {
                $subtotal = ($subtotal ?? BigDecimal::zero())->plus($line->subtotal);
            }
        }

        return $subtotal;
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

    /**
     * No error, no line without price, no line waiting for an advisor, and an agreed advance when there are
     * negotiated lines (COT-012).
     */
    public function complete(): bool
    {
        $negotiated = false;

        foreach ($this->lines as $line) {
            if (! $line->addsToTotal()) {
                return false;
            }

            $negotiated = $negotiated || $line->negotiated;
        }

        return ! $negotiated || $this->advance->agreed !== null;
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
            'proposed_agreed_advance' => self::money($this->proposedAgreedAdvance),
            'agreed_advance_discarded' => $this->agreedAdvanceDiscarded,
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
