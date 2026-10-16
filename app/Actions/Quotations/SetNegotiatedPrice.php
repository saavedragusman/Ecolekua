<?php

namespace App\Actions\Quotations;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AttributeValue;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Support\Pricing\InvalidAmount;
use App\Support\Pricing\Money;
use App\Support\Quotations\Calculation\CalculationInput;
use App\Support\Quotations\Calculation\LineResult;
use App\Support\Quotations\DraftInput;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers the negotiated unit price of a draft line that requires an advisor (COT-012, DEC-COT-12, design
 * Decision 17): one price per size group the line's rows use (DEC-COT-66), or the reference price confirmed for
 * a custom color. It stores the prices with their author, date and the basis they were negotiated for
 * (DEC-COT-55), recalculates the draft cache and is audited. A line that does not require an advisor, has an
 * error (such as a size in no group, DEC-COT-67) or a quotation that is not a draft is rejected.
 */
class SetNegotiatedPrice
{
    private const NOT_DRAFT = 'La cotización no está en borrador; duplíquela para cambiarla.';

    public function __construct(
        private readonly CalculateQuotation $calculateQuotation,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array{prices?: list<array{size_group_id?: ?int, price: string}>|null, confirmed?: bool}  $data
     *
     * @throws BusinessRuleViolation when the quotation is not a draft
     * @throws ValidationException when the line cannot be negotiated or the prices are not acceptable
     */
    public function handle(QuotationLine $line, array $data, User $actor): void
    {
        DB::transaction(function () use ($line, $data, $actor): void {
            $quotation = Quotation::query()->whereKey($line->quotation_id)->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::Draft) {
                throw new BusinessRuleViolation(self::NOT_DRAFT);
            }

            $input = DraftInput::from($quotation);
            $index = (int) array_search($line->id, array_column($input['lines'], 'id'), true);
            $input['lines'][$index]['negotiated'] = null;

            $calculated = $this->calculateQuotation->handle(CalculationInput::fromArray($input))->lines[$index];
            $confirmed = (bool) ($data['confirmed'] ?? false);
            $prices = $this->prices($calculated, $data['prices'] ?? [], $confirmed);

            DB::table('quotation_line_negotiated_prices')->where('quotation_line_id', $line->id)->delete();

            foreach ($prices as $groupId => $price) {
                DB::table('quotation_line_negotiated_prices')->insert([
                    'quotation_line_id' => $line->id,
                    'size_group_id' => $groupId === 0 ? null : $groupId,
                    'unit_price' => Money::toStorage($price, 2),
                ]);
            }

            $basis = $calculated->negotiationBasis;
            $line->forceFill([
                'negotiated_by' => $actor->id,
                'negotiated_at' => now(),
                'negotiation_confirmed' => $confirmed,
                'negotiated_combination_id' => $basis?->combinationId,
                'negotiated_size_group_ids' => $basis?->sizeGroupIds,
            ])->save();

            $result = $this->calculateQuotation->handle(CalculationInput::fromArray(DraftInput::from($quotation->fresh() ?? $quotation)));
            $quotation->forceFill(['total' => $result->total(), 'requires_advisor' => $result->requiresAdvisor()]);

            // An agreed advance above the new negotiated subtotal goes, unaudited (COT-010, DEC-COT-68).
            if ($result->agreedAdvanceDiscarded) {
                $quotation->forceFill(['agreed_advance' => null, 'agreed_advance_by' => null, 'agreed_advance_at' => null]);
            }

            $quotation->save();

            $this->audit->handle(AuditAction::QuotationNegotiatedPriceSet, $actor, $quotation, newValues: [
                'line' => $line->position,
                'prices' => array_map(fn (int $groupId, BigDecimal $price): array => [
                    'size_group_id' => $groupId === 0 ? null : $groupId,
                    'price' => Money::toStorage($price, 2),
                ], array_keys($prices), $prices),
                'confirmed' => $confirmed,
            ]);
        });
    }

    /**
     * The prices to store, by size group (0 for a line without groups).
     *
     * @param  list<array{size_group_id?: ?int, price: string}>  $sent
     * @return array<int, BigDecimal>
     *
     * @throws ValidationException
     */
    private function prices(LineResult $line, array $sent, bool $confirmed): array
    {
        if (! $line->requiresAdvisor) {
            throw ValidationException::withMessages(['price' => __('validation.negotiation_not_required')]);
        }

        if ($line->errors !== [] || $line->negotiationBasis === null) {
            $ungrouped = $this->ungroupedSizes($line);

            throw ValidationException::withMessages(['price' => match (count($ungrouped)) {
                0 => __('validation.negotiation_line_unpriced'),
                1 => __('validation.negotiation_size_ungrouped', ['sizes' => $ungrouped[0]]),
                default => __('validation.negotiation_sizes_ungrouped', ['sizes' => implode(', ', $ungrouped)]),
            }]);
        }

        $groups = $line->negotiationBasis->sizeGroupIds === [] ? [0] : $line->negotiationBasis->sizeGroupIds;

        if ($confirmed) {
            // Confirming takes the reference price; prices sent with it are contradictory, not ignored.
            if ($sent !== []) {
                throw ValidationException::withMessages(['prices' => __('validation.negotiation_confirm_with_prices')]);
            }

            return $this->referencePrices($line, $groups);
        }

        $prices = [];

        foreach ($sent as $j => $price) {
            try {
                $amount = Money::parse((string) $price['price'], 2);
            } catch (InvalidAmount) {
                throw ValidationException::withMessages(["prices.{$j}.price" => __('validation.price_invalid')]);
            }

            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(["prices.{$j}.price" => __('validation.price_invalid')]);
            }

            $prices[(int) ($price['size_group_id'] ?? 0)] = $amount;
        }

        $keys = array_keys($prices);
        sort($keys);

        if ($keys !== $groups || count($sent) !== count($groups)) {
            throw ValidationException::withMessages(['prices' => __('validation.negotiation_groups_mismatch')]);
        }

        return $prices;
    }

    /**
     * Names of the line's sizes that are in no group of a combination with groups (DEC-COT-67).
     *
     * @return list<string>
     */
    private function ungroupedSizes(LineResult $line): array
    {
        if ($line->combinationId === null) {
            return [];
        }

        $grouped = DB::table('size_group_values')->where('combination_id', $line->combinationId)->pluck('size_value_id')->map(fn (mixed $id): int => (int) $id)->all();
        $sizes = array_values(array_filter(array_map(fn ($row): ?int => $row->sizeValueId, $line->rows)));
        $missing = $grouped === [] ? [] : array_values(array_diff($sizes, $grouped));

        return $missing === [] ? [] : array_values(AttributeValue::query()->whereKey($missing)->orderBy('sort_order')->get()
            ->map(fn (AttributeValue $value): string => $value->name)->all());
    }

    /**
     * The tier price of each group, confirmed as the negotiated one for a custom color (COT-012). A negotiation
     * tier has no reference price to confirm.
     *
     * @param  list<int>  $groups
     * @return array<int, BigDecimal>
     */
    private function referencePrices(LineResult $line, array $groups): array
    {
        $prices = [];

        foreach ($line->rows as $row) {
            if ($row->unitPrice === null) {
                throw ValidationException::withMessages(['prices' => __('validation.negotiation_no_reference')]);
            }

            $prices[$row->sizeGroupId ?? 0] = $row->unitPrice;
        }

        ksort($prices);

        return array_keys($prices) === $groups ? $prices : throw ValidationException::withMessages(['prices' => __('validation.negotiation_no_reference')]);
    }
}
