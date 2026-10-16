<?php

namespace App\Http\Requests\Pricing;

use App\Models\PriceScheme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The commercial parameters (COT-006). The shape is checked here; the ranges belong to
 * `UpdateCommercialParameters`, so both fields are reported together.
 *
 * `advance_percentage` is a string (`40`, `37,5`) so it never travels as a float.
 */
class UpdateCommercialParametersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', PriceScheme::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'advance_percentage' => ['required', 'string'],
            'validity_days' => ['required', 'integer'],
        ];
    }
}
