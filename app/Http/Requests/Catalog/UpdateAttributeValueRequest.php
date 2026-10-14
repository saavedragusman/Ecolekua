<?php

namespace App\Http\Requests\Catalog;

use App\Models\AttributeValue;
use App\Support\Products\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The attribute of a value never changes, so its presentation decides whether the tone is required
 * (color) or prohibited. Optional fields are only validated, and edited, when they are sent.
 */
class UpdateAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->value());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CatalogRules::valueRules($this->value()->catalogAttribute, $this->value());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('validation.value_name_unique'),
            'svg_layer.prohibited' => __('validation.value_layer_color_prohibited'),
            'svg_layer.not_in' => __('validation.value_layer_reserved'),
        ];
    }

    private function value(): AttributeValue
    {
        /** @var AttributeValue */
        return $this->route('value');
    }
}
