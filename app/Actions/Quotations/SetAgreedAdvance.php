<?php

namespace App\Actions\Quotations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Pricing\InvalidAmount;
use App\Support\Pricing\Money;
use App\Support\Quotations\Calculation\CalculationInput;
use App\Support\Quotations\DraftInput;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers the agreed advance of a draft's negotiated lines (COT-010, DEC-COT-11, DEC-COT-40, E-62): one amount
 * in USD between zero and their subtotal, with its author and date, audited with the previous and the new value.
 * The system proposes the advance percent of the negotiated subtotal; the user may change it.
 */
class SetAgreedAdvance
{
    private const NOT_DRAFT = 'La cotización no está en borrador; duplíquela para cambiarla.';

    public function __construct(
        private readonly CalculateQuotation $calculateQuotation,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @throws BusinessRuleViolation when the quotation is not a draft
     * @throws ValidationException when the amount is not acceptable or there is no negotiated line
     */
    public function handle(Quotation $quotation, string $amount, User $actor): void
    {
        DB::transaction(function () use ($quotation, $amount, $actor): void {
            $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== QuotationStatus::Draft) {
                throw new BusinessRuleViolation(self::NOT_DRAFT);
            }

            $agreed = $this->amount($amount);
            $input = [...DraftInput::from($locked), 'agreed_advance' => null];
            $negotiated = $this->calculateQuotation->handle(CalculationInput::fromArray($input))->negotiatedSubtotal();

            if ($negotiated === null) {
                throw ValidationException::withMessages(['amount' => __('validation.agreed_advance_without_negotiation')]);
            }

            if ($agreed->isGreaterThan($negotiated)) {
                throw ValidationException::withMessages(['amount' => __('validation.agreed_advance_over_subtotal', ['subtotal' => Money::display($negotiated)])]);
            }

            $previous = $locked->agreed_advance === null ? null : Money::toStorage($locked->agreed_advance, 2);

            $locked->forceFill(['agreed_advance' => $agreed, 'agreed_advance_by' => $actor->id, 'agreed_advance_at' => now()])->save();

            $this->audit->handle(AuditAction::QuotationAgreedAdvanceSet, $actor, $locked,
                oldValues: ['agreed_advance' => $previous],
                newValues: ['agreed_advance' => Money::toStorage($agreed, 2)],
            );
        });
    }

    /**
     * @throws ValidationException
     */
    private function amount(string $amount): BigDecimal
    {
        try {
            $parsed = Money::parse($amount, 2);
        } catch (InvalidAmount) {
            $parsed = null;
        }

        return $parsed === null || $parsed->isNegative()
            ? throw ValidationException::withMessages(['amount' => __('validation.agreed_advance_invalid')])
            : $parsed;
    }
}
