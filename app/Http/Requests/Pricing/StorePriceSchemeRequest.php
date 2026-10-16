<?php

namespace App\Http\Requests\Pricing;

use App\Enums\PriceSchemeType;
use App\Models\PriceScheme;
use App\Support\Pricing\SchemeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', PriceScheme::class);
    }

    /**
     * Shape of a new scheme (COT-001). The tier set itself (contiguity, negotiation tier, labels) is
     * checked by `SchemeRules` inside `CreatePriceScheme`. A fixed scheme takes no tiers: it gets its
     * single system tier.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => SchemeInput::nameRules(null),
            'type' => ['required', Rule::enum(PriceSchemeType::class)],
            'tiers' => [$this->input('type') === PriceSchemeType::Fixed->value ? 'prohibited' : 'required', 'array', 'min:1'],
            ...SchemeInput::tierRules(withId: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => __('validation.scheme_name_unique')];
    }
}
