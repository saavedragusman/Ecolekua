<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Support\Products\Assets\AssetLimits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of an SVG template of a product (E-37..E-41). Authorization is `ProductPolicy::update`, so a
 * user without `products.update` gets a 403 before anything is validated (E-42). The request only
 * checks that a file arrived within the size bound; whether it is a safe SVG with the required layers
 * is decided by `UploadProductTemplate` after sanitizing, never by the name or the declared type.
 */
class StoreProductTemplateRequest extends FormRequest
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
        return [
            'template' => ['required', 'file', 'max:'.AssetLimits::MAX_KILOBYTES],
            'variant_value_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template.required' => __('validation.template_required'),
            'template.file' => __('validation.template_upload_failed'),
            'template.uploaded' => __('validation.template_upload_failed'),
            'template.max' => __('validation.template_size'),
            'variant_value_id.integer' => __('validation.template_variant_invalid'),
        ];
    }
}
