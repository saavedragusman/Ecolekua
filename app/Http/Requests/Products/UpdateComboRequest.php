<?php

namespace App\Http\Requests\Products;

use App\Models\CatalogCode;
use App\Models\Combo;
use App\Support\Products\ComboRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->combo());
    }

    /**
     * The same rules as the creation (PRD-012): an edit that fails the creation validations is
     * rejected with a field error and nothing changes. The combo may keep its own name and code.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $registryId = CatalogCode::query()->where('combo_id', $this->combo()->id)->value('id');

        return ComboRules::rules($registryId, $this->combo());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ComboRules::messages();
    }

    private function combo(): Combo
    {
        /** @var Combo */
        return $this->route('combo');
    }
}
