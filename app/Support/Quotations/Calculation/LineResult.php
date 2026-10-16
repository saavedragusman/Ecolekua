<?php

namespace App\Support\Quotations\Calculation;

use App\Enums\LineKind;
use App\Enums\PriceOrigin;
use Brick\Math\BigDecimal;

/**
 * One calculated line (COT-009 output): the resolved code and descriptive name, the applied tier, the price
 * origin, the rows with their prices (or, for a combo, the price per combo), the subtotal, the customizations
 * (never charged, COT-011), whether it requires an advisor and why, and its errors keyed by field.
 *
 * The subtotal is null when the line has an error or no automatic price. A line that requires an advisor
 * shows the tier price as a reference (custom color, E-23) but does not add to the total until a negotiated
 * price exists (COT-012). `paidInFull` marks the stock and combo lines, whose advance is the whole subtotal
 * (COT-010); the pack notice is informative and never changes the line (DEC-COT-08). The resolved combination of
 * the line, and of each combo component, is what a saved draft stores as its history anchor (Decision 22).
 * `negotiationBasis` is the line's current basis (DEC-COT-55); `negotiatedDiscardedReason` says why a negotiated
 * price was dropped (`no_longer_requires_advisor`, `combination_changed`, `size_groups_changed`).
 */
final readonly class LineResult
{
    /**
     * @param  list<RowResult>  $rows
     * @param  list<array{id: int, name: string, quantity_per_garment: int}>  $customizations
     * @param  list<array{id: int, name: string}>  $includedCustomizations
     * @param  array<string, string>  $errors  field => message
     * @param  array<int, int>  $componentCombinationIds  combo component id => resolved combination id
     */
    public function __construct(
        public LineKind $kind,
        public ?string $code,
        public ?string $descriptiveName,
        public ?string $tierLabel,
        public ?PriceOrigin $priceOrigin,
        public array $rows,
        public ?BigDecimal $unitPrice,
        public ?BigDecimal $subtotal,
        public array $customizations,
        public array $includedCustomizations,
        public bool $requiresAdvisor,
        public ?string $reason,
        public array $errors,
        public bool $paidInFull = false,
        public ?PackNotice $packNotice = null,
        public ?int $combinationId = null,
        public array $componentCombinationIds = [],
        public bool $negotiated = false,
        public ?string $negotiatedDiscardedReason = null,
        public ?NegotiationBasis $negotiationBasis = null,
        public ?string $notice = null,
    ) {}

    /**
     * Whether the subtotal adds to the quotation total: a price, no error, and no advisor pending (a line that
     * requires an advisor adds only with its negotiated or confirmed price, COT-012).
     */
    public function addsToTotal(): bool
    {
        return $this->subtotal !== null && $this->errors === [] && (! $this->requiresAdvisor || $this->negotiated);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'code' => $this->code,
            'descriptive_name' => $this->descriptiveName,
            'tier_label' => $this->tierLabel,
            'price_origin' => $this->priceOrigin?->value,
            'rows' => array_map(fn (RowResult $row): array => $row->toArray(), $this->rows),
            'unit_price' => CalculationResult::money($this->unitPrice),
            'subtotal' => CalculationResult::money($this->subtotal),
            'customizations' => array_map(fn (array $customization): array => [...$customization, 'charge' => 'sin cargo'], $this->customizations),
            'included_customizations' => $this->includedCustomizations,
            'requires_advisor' => $this->requiresAdvisor,
            'reason' => $this->reason,
            'negotiated_discarded' => $this->negotiatedDiscardedReason !== null,
            'negotiated_discarded_reason' => $this->negotiatedDiscardedReason,
            'negotiation_basis' => $this->negotiationBasis === null ? null : [
                'combination_id' => $this->negotiationBasis->combinationId,
                'size_group_ids' => $this->negotiationBasis->sizeGroupIds,
            ],
            'notice' => $this->notice,
            'pack_notice' => $this->packNotice === null ? null : [
                'combo_code' => $this->packNotice->comboCode,
                'combo_name' => $this->packNotice->comboName,
                'packs_fit' => $this->packNotice->packsFit,
                'estimated_saving' => CalculationResult::money($this->packNotice->estimatedSaving),
            ],
            'errors' => $this->errors,
        ];
    }
}
