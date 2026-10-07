<?php

namespace App\Http\Requests\Catalog;

use App\Models\CatalogAttribute;
use App\Support\Products\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The edit sends the whole attribute: the special use must be present (null clears it) so that an
 * omitted field never removes a use by accident.
 */
class UpdateAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->attribute());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CatalogRules::attributeRules($this->attribute(), $this->input('presentation'), specialUseRequired: true);
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

    private function attribute(): CatalogAttribute
    {
        /** @var CatalogAttribute */
        return $this->route('attribute');
    }
}
