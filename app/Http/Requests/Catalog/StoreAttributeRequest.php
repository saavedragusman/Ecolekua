<?php

namespace App\Http\Requests\Catalog;

use App\Models\CatalogAttribute;
use App\Support\Products\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', CatalogAttribute::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CatalogRules::attributeRules(null, $this->input('presentation'), specialUseRequired: false);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('validation.attribute_name_unique'),
            'special_use.unique' => __('validation.attribute_special_use_unique'),
        ];
    }
}
