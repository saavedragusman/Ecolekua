<?php

namespace App\Http\Requests\Catalog;

use App\Support\Products\Assets\ImageRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of the image of an attribute value or a detail location (E-35). The route carries either
 * `{value}` or `{location}`; authorization is `CatalogPolicy::manage` (`products.catalog`, E-42).
 */
class StoreCatalogImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('value') ?? $this->route('location'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ImageRules::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ImageRules::messages();
    }
}
