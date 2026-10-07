<?php

namespace App\Http\Requests\Catalog;

use App\Models\DetailLocation;
use App\Support\Products\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreDetailLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', DetailLocation::class);
    }

    /**
     * The `name` column is case-insensitive, so the unique rule ignores letter case (DEC-PRD-45, E-64).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CatalogRules::locationRules(null);
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
}
