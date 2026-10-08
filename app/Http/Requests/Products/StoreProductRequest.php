<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Support\Products\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * General data of a product (PRD-003, DEC-PRD-46, DEC-PRD-34): the supply mode decides which of
     * the minimum stock and custom color fields are admitted.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CatalogRules::productRules(null, $this->input('supply_mode'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CatalogRules::productMessages();
    }
}
