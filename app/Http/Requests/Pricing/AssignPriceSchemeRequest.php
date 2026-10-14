<?php

namespace App\Http\Requests\Pricing;

use App\Models\PriceScheme;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Assignment of a scheme to products (COT-002). The bulk route sends `product_ids`; the single-product
 * route takes the product from the URL, so both reach `AssignPriceScheme` with the same input.
 */
class AssignPriceSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', PriceScheme::class);
    }

    protected function prepareForValidation(): void
    {
        $product = $this->route('product');

        if ($product instanceof Product) {
            $this->merge(['product_ids' => [$product->id]]);
        }
    }

    /**
     * That the scheme is active and that confirmation is given when prices would be deleted are rules
     * of the Action (DEC-COT-22, DEC-COT-23): they depend on locked state.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'price_scheme_id' => ['required', 'integer', 'exists:price_schemes,id'],
            'confirm_price_removal' => ['sometimes', 'boolean'],
        ];
    }
}
