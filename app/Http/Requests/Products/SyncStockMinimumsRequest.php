<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The own minimum stock of the articles of a product as a full list (PRD-009, design Decision 13).
 * Only the shape is checked here; the rules that need the product, its mode or its structure live in
 * `SyncStockMinimumOverrides`. An empty list is valid and clears every override.
 */
class SyncStockMinimumsRequest extends FormRequest
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
            'overrides' => ['present', 'array'],
            'overrides.*' => ['array'],
            'overrides.*.combination_id' => ['required', 'integer'],
            'overrides.*.size_value_id' => ['nullable', 'integer'],
            'overrides.*.minimum' => ['required', 'integer', 'between:0,9999'],
        ];
    }

    private function product(): Product
    {
        /** @var Product */
        return $this->route('product');
    }
}
