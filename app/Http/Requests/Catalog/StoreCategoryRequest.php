<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ProductCategory::class);
    }

    /**
     * The `name` column is case-insensitive, so the unique rule ignores letter case (DEC-PRD-45, E-64).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('product_categories', 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => __('validation.category_name_unique')];
    }
}
