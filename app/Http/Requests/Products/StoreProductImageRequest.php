<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Support\Products\Assets\ImageRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of the main image of a product (E-35). Authorization is `ProductPolicy::update`, so a user
 * without `products.update` gets a 403 before anything is validated (E-42).
 */
class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $this->user()->can('update', $product);
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
