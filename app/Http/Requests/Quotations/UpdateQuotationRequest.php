<?php

namespace App\Http\Requests\Quotations;

use App\Support\Quotations\Calculation\CalculationInput;
use App\Support\Quotations\QuotationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A draft save (COT-013, COT-014): the recipient, the advisor, the notes and every line with the calculation
 * shape. Referenced products, sizes, services and combos must exist; the size rows of a line follow COT-014
 * (E-35). Business problems of the lines (selection, price) do not stop the save.
 */
class UpdateQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('quotation')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...QuotationRules::recipient(),
            ...array_merge_recursive(CalculationInput::rules(), QuotationRules::lineReferences()),
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [QuotationRules::validateOneRecipient(...), QuotationRules::validateSizeRows(...)];
    }
}
