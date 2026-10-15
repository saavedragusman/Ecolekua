<?php

namespace App\Support\Quotations\Calculation;

use Brick\Math\BigDecimal;

/**
 * One size row of a calculated product line (COT-009 step 4): its unit price, from the combination's tier
 * or from its size group, and its subtotal. Both are null when the row has no price or an error.
 */
final readonly class RowResult
{
    public function __construct(
        public ?int $sizeValueId,
        public int $quantity,
        public ?BigDecimal $unitPrice = null,
        public ?string $sizeGroupLabel = null,
    ) {}

    public function subtotal(): ?BigDecimal
    {
        return $this->unitPrice?->multipliedBy($this->quantity);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'size_value_id' => $this->sizeValueId,
            'quantity' => $this->quantity,
            'unit_price' => CalculationResult::money($this->unitPrice),
            'size_group_label' => $this->sizeGroupLabel,
            'subtotal' => CalculationResult::money($this->subtotal()),
        ];
    }
}
