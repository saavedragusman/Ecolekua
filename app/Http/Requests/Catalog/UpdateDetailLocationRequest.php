<?php

namespace App\Http\Requests\Catalog;

use App\Models\DetailLocation;
use App\Support\Products\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDetailLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->location());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CatalogRules::locationRules($this->location());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('validation.location_name_unique'),
            'svg_layer.not_in' => __('validation.value_layer_reserved'),
        ];
    }

    private function location(): DetailLocation
    {
        /** @var DetailLocation */
        return $this->route('location');
    }
}
