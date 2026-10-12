<?php

namespace App\Http\Requests\Catalog;

use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Support\Products\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', AttributeValue::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CatalogRules::valueRules($this->attribute(), null);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('validation.value_name_unique'),
            'svg_layer.not_in' => __('validation.value_layer_reserved'),
        ];
    }

    private function attribute(): CatalogAttribute
    {
        /** @var CatalogAttribute */
        return $this->route('attribute');
    }
}
