<?php

namespace App\Http\Requests\Pricing;

use App\Models\PriceScheme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The component prices of one combo (COT-005). The shape of each entry is checked here; the rules that
 * depend on the combo and the locked prices (belonging, repetition, amount) belong to
 * `SyncComboItemPrices`.
 *
 * `price` is a string (`15.00`, `15,00`) so no amount ever travels as a float; it must be present and
 * `null` means "delete this price".
 */
class SyncComboItemPricesRequest extends FormRequest
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
            'prices' => ['required', 'array', 'min:1'],
            'prices.*' => ['array'],
            'prices.*.combo_component_id' => ['required', 'integer', 'exists:combo_components,id'],
            'prices.*.price' => ['present', 'nullable', 'string'],
        ];
    }
}
