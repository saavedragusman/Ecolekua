<?php

namespace App\Http\Requests\Pricing;

use App\Models\ExchangeRate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The manual rate of today (COT-030, E-64). The value is a string (`875,6505`) so it never travels as a
 * float; its amount rules belong to `RecordManualExchangeRate`.
 */
class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ExchangeRate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'value' => ['required', 'string'],
        ];
    }
}
