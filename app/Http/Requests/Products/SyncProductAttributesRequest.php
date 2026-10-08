<?php

namespace App\Http\Requests\Products;

use App\Enums\AttributeRole;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The whole structure of a product as an ordered list (PRD-004, design Decision 11). Only the shape
 * is checked here; the rules that need the catalog, the combinations or a lock (repeated attribute,
 * active attributes and values, roles, freeze, values in use) live in `SyncProductAttributes`.
 * An empty list is valid: a product MAY declare no attributes.
 */
class SyncProductAttributesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->product());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'attributes' => ['present', 'array'],
            'attributes.*' => ['array'],
            'attributes.*.attribute_id' => ['required', 'integer', Rule::exists('catalog_attributes', 'id')],
            'attributes.*.role' => ['required', Rule::enum(AttributeRole::class)],
            'attributes.*.allowed_value_ids' => ['sometimes', 'nullable', 'array'],
            'attributes.*.allowed_value_ids.*' => ['integer'],
        ];
    }

    private function product(): Product
    {
        /** @var Product */
        return $this->route('product');
    }
}
