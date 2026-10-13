<?php

namespace App\Http\Requests\Products;

use App\Models\CatalogCode;
use App\Models\Combination;
use App\Support\Products\CombinationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCombinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->combination());
    }

    /**
     * The same rules as the creation (PRD-012): an edit that fails the creation validations is
     * rejected with a field error and nothing changes (E-68). The combination may keep its own code.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $registryId = CatalogCode::query()->where('combination_id', $this->combination()->id)->value('id');

        return CombinationRules::rules($registryId);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CombinationRules::messages();
    }

    private function combination(): Combination
    {
        /** @var Combination */
        return $this->route('combination');
    }
}
