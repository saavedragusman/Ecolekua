<?php

namespace App\Http\Requests\Pricing;

use App\Enums\PriceSchemeType;
use App\Models\PriceScheme;
use App\Support\Pricing\SchemeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', PriceScheme::class);
    }

    /**
     * Shape of the edit form (COT-001). `type` may be sent but `UpdatePriceScheme` rejects a different
     * one (DEC-COT-51). A tiered scheme sends its full ordered tier list, existing tiers with their
     * `id`; a fixed scheme has a single system tier that is not editable, so it sends none.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var PriceScheme $scheme */
        $scheme = $this->route('scheme');

        return [
            'name' => SchemeInput::nameRules($scheme),
            'type' => ['sometimes', Rule::enum(PriceSchemeType::class)],
            'tiers' => [$scheme->type === PriceSchemeType::Fixed ? 'prohibited' : 'required', 'array', 'min:1'],
            'confirm_price_removal' => ['sometimes', 'boolean'],
            ...SchemeInput::tierRules(withId: true),
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
