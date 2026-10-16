<?php

namespace App\Http\Requests\Quotations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The agreed advance of a draft's negotiated lines (COT-010, E-62). The amount is a string (`400,00`), never a
 * float; its rules belong to `SetAgreedAdvance`.
 */
class AgreedAdvanceRequest extends FormRequest
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
            'amount' => ['required', 'string'],
        ];
    }
}
