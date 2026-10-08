<?php

namespace App\Http\Requests\Products;

use App\Models\Combination;
use App\Support\Products\CombinationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreCombinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Combination::class);
    }

    /**
     * Shape of a new combination (PRD-005, DT-01). The rules that depend on the structure of the
     * product (axes, restrictions) and on the other combinations (overlap) run in `CreateCombination`.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CombinationRules::rules(null);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CombinationRules::messages();
    }
}
