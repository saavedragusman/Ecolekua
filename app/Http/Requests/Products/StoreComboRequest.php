<?php

namespace App\Http\Requests\Products;

use App\Models\Combo;
use App\Support\Products\ComboRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Combo::class);
    }

    /**
     * Shape of a new combo (PRD-010, DT-01). The rules that depend on the product of each component
     * (line, mode, admitted values) run in `CreateCombo`.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ComboRules::rules(null);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ComboRules::messages();
    }
}
