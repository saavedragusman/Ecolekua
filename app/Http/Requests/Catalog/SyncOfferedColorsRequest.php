<?php

namespace App\Http\Requests\Catalog;

use App\Models\AttributeValue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The full set of colors offered in a fabric value (E-46). An empty list is valid and removes every
 * offered color; whether the ids are active colors is decided by `SyncFabricOfferedColors`.
 */
class SyncOfferedColorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', AttributeValue::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'color_ids' => ['present', 'array'],
            'color_ids.*' => ['integer'],
        ];
    }
}
