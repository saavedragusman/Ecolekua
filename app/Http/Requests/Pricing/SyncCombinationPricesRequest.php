<?php

namespace App\Http\Requests\Pricing;

use App\Models\PriceScheme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The price table of one product (COT-003). The shape of each cell is checked here; the rules that
 * depend on the product, its scheme and the locked prices (belonging, priced tier, groups, amount)
 * belong to `SyncCombinationPrices`.
 *
 * `price` is a string (`40.00`, `40,00`) so no amount ever travels as a float; it must be present and
 * `null` means "delete this price" (DEC-COT-32).
 */
class SyncCombinationPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', PriceScheme::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'cells' => ['required', 'array', 'min:1'],
            'cells.*' => ['array'],
            'cells.*.combination_id' => ['required', 'integer', 'exists:combinations,id'],
            'cells.*.size_group_id' => ['nullable', 'integer', 'exists:size_groups,id'],
            'cells.*.price_tier_id' => ['required', 'integer', 'exists:price_tiers,id'],
            'cells.*.price' => ['present', 'nullable', 'string'],
        ];
    }
}
