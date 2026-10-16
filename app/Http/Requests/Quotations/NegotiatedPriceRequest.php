<?php

namespace App\Http\Requests\Quotations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The negotiated price of a draft line (COT-012): one price per size group the line uses, or the confirmation of
 * the reference price. Prices are strings (`29,00`), never floats; their rules belong to `SetNegotiatedPrice`.
 */
class NegotiatedPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('negotiate', $this->route('quotation')) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'prices' => ['nullable', 'array', 'required_unless:confirmed,true'],
            'prices.*.size_group_id' => ['nullable', 'integer'],
            'prices.*.price' => ['required', 'string'],
            'confirmed' => ['sometimes', 'boolean'],
        ];
    }
}
