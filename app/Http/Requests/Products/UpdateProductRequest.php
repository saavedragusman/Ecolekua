<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Support\Products\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->product());
    }

    /**
     * The same rules as the creation (PRD-012): an edit that fails the creation validations is
     * rejected with a field error and nothing changes (E-68).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...CatalogRules::productRules($this->product(), $this->input('supply_mode')),
            ...CatalogRules::productRelationRules($this->product()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...CatalogRules::productMessages(), ...CatalogRules::relationMessages()];
    }

    private function product(): Product
    {
        /** @var Product */
        return $this->route('product');
    }
}
