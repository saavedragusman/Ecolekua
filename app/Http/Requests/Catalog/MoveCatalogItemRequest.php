<?php

namespace App\Http\Requests\Catalog;

use App\Actions\Products\MoveCatalogItem;
use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Direction of a catalog row move. Authorization is the catalog `manage` ability, shared by every
 * catalog entity, so the class is checked rather than a specific model.
 */
class MoveCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ProductCategory::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', Rule::in([MoveCatalogItem::UP, MoveCatalogItem::DOWN])],
        ];
    }
}
