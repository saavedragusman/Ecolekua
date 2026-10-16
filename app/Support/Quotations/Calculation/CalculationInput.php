<?php

namespace App\Support\Quotations\Calculation;

use App\Enums\LineKind;
use App\Support\Pricing\InvalidAmount;
use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The lines a quotation calculation receives (COT-009, design Decision 14). Only a malformed structure is
 * rejected here; business problems (an invalid selection, a line without price) come back as errors of the
 * result, so a draft can be saved with them.
 *
 * A product line carries its product, its 003 selection without the size, one row per size (or one row
 * without size) and the customizations added to every garment. A combo line carries its combo, the number of
 * combos and the selection of each component.
 */
final readonly class CalculationInput
{
    /**
     * @param  list<array{kind: LineKind, product_id: ?int, selection: array<string, mixed>, rows: list<array{size_value_id: ?int, quantity: int}>, customizations: list<array{service_product_id: int, quantity_per_garment: int}>, combo_id: ?int, combo_quantity: int, components: array<int|string, mixed>, negotiated: array{prices: array<int, BigDecimal>, confirmed: bool, basis: ?NegotiationBasis}|null}>  $lines
     */
    private function __construct(
        public array $lines,
        public ?string $agreedAdvance,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the structure is malformed
     */
    public static function fromArray(array $data): self
    {
        /** @var array{lines: list<array<string, mixed>>, agreed_advance?: ?string} $valid */
        $valid = Validator::make($data, self::rules())->validate();
        $lines = [];

        foreach ($valid['lines'] as $line) {
            $lines[] = [
                'kind' => LineKind::from((string) $line['kind']),
                'product_id' => isset($line['product_id']) ? (int) $line['product_id'] : null,
                'selection' => is_array($line['selection'] ?? null) ? $line['selection'] : [],
                'rows' => array_values(array_map(fn (array $row): array => [
                    'size_value_id' => isset($row['size_value_id']) ? (int) $row['size_value_id'] : null,
                    'quantity' => (int) $row['quantity'],
                ], $line['rows'] ?? [])),
                'customizations' => array_values(array_map(fn (array $customization): array => [
                    'service_product_id' => (int) $customization['service_product_id'],
                    'quantity_per_garment' => (int) ($customization['quantity_per_garment'] ?? 1),
                ], $line['customizations'] ?? [])),
                'combo_id' => isset($line['combo_id']) ? (int) $line['combo_id'] : null,
                'combo_quantity' => (int) ($line['combo_quantity'] ?? 0),
                'components' => is_array($line['components'] ?? null) ? $line['components'] : [],
                'negotiated' => self::negotiated($line['negotiated'] ?? null, count($lines)),
            ];
        }

        return new self($lines, $valid['agreed_advance'] ?? null);
    }

    /**
     * The negotiated prices of a line by size group (null key for a line without groups), whether they confirm
     * the reference price, and the basis they were negotiated for (DEC-COT-55). Null when the line has none.
     *
     * @return array{prices: array<int, BigDecimal>, confirmed: bool, basis: ?NegotiationBasis}|null
     *
     * @throws ValidationException when a price is not an amount with at most 2 decimals
     */
    private static function negotiated(mixed $negotiated, int $index): ?array
    {
        if (! is_array($negotiated) || ($negotiated['prices'] ?? []) === []) {
            return null;
        }

        $prices = [];

        foreach ($negotiated['prices'] as $j => $price) {
            try {
                $amount = Money::parse((string) $price['price'], 2);
            } catch (InvalidAmount) {
                $amount = null;
            }

            if ($amount === null || ! $amount->isPositive()) {
                throw ValidationException::withMessages(["lines.{$index}.negotiated.prices.{$j}.price" => __('validation.price_invalid')]);
            }

            $prices[(int) ($price['size_group_id'] ?? 0)] = $amount;
        }

        $basis = is_array($negotiated['basis'] ?? null) && isset($negotiated['basis']['combination_id'])
            ? NegotiationBasis::of((int) $negotiated['basis']['combination_id'], array_values(array_map(intval(...), $negotiated['basis']['size_group_ids'] ?? [])))
            : null;

        return ['prices' => $prices, 'confirmed' => (bool) ($negotiated['confirmed'] ?? false), 'basis' => $basis];
    }

    /**
     * Structure rules, shared with the HTTP requests that carry a calculation.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'lines' => ['present', 'array'],
            'lines.*' => ['array'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.kind' => ['required', Rule::enum(LineKind::class)],
            'lines.*.product_id' => ['required_if:lines.*.kind,product', 'nullable', 'integer'],
            'lines.*.selection' => ['nullable', 'array'],
            'lines.*.rows' => ['required_if:lines.*.kind,product', 'array'],
            'lines.*.rows.*.size_value_id' => ['nullable', 'integer'],
            'lines.*.rows.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.customizations' => ['nullable', 'array'],
            'lines.*.customizations.*.service_product_id' => ['required', 'integer'],
            'lines.*.customizations.*.quantity_per_garment' => ['nullable', 'integer', 'min:1', 'max:99'],
            'lines.*.combo_id' => ['required_if:lines.*.kind,combo', 'nullable', 'integer'],
            'lines.*.combo_quantity' => ['required_if:lines.*.kind,combo', 'nullable', 'integer', 'min:1'],
            'lines.*.components' => ['nullable', 'array'],
            'lines.*.negotiated' => ['nullable', 'array'],
            'lines.*.negotiated.prices' => ['nullable', 'array'],
            'lines.*.negotiated.prices.*.size_group_id' => ['nullable', 'integer'],
            'lines.*.negotiated.prices.*.price' => ['required', 'string'],
            'lines.*.negotiated.confirmed' => ['nullable', 'boolean'],
            'lines.*.negotiated.basis' => ['required_with:lines.*.negotiated.prices', 'array'],
            'lines.*.negotiated.basis.combination_id' => ['required_with:lines.*.negotiated.basis', 'integer'],
            'lines.*.negotiated.basis.size_group_ids' => ['nullable', 'array'],
            'lines.*.negotiated.basis.size_group_ids.*' => ['integer'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
            'agreed_advance' => ['nullable', 'string'],
        ];
    }
}
