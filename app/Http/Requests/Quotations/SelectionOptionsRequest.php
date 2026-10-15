<?php

namespace App\Http\Requests\Quotations;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A partial selection the editor asks options for (PRD-019): a product, or a combo and one of its
 * components, with the axes chosen so far. The selection rules themselves belong to the 003 engine.
 */
class SelectionOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('calculate', Quotation::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer'],
            'combo_id' => ['nullable', 'integer'],
            'component_id' => ['nullable', 'integer'],
            'axes' => ['nullable', 'array'],
        ];
    }
}
