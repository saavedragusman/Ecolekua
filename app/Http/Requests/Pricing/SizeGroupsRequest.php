<?php

namespace App\Http\Requests\Pricing;

use App\Models\PriceScheme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The size groups proposed for a combination, for the preview and for the save (COT-004, E-70). Only
 * the shape is checked here: `groups` is a list of lists of size ids (an empty list removes the
 * grouping) and `confirm_token` is the token the preview returned. That the groups partition the sizes
 * the combination admits is `SizeGroupRules`, run by the Actions on the locked state.
 */
class SizeGroupsRequest extends FormRequest
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
            'groups' => ['present', 'array'],
            'groups.*' => ['array'],
            'groups.*.*' => ['integer'],
            'confirm_token' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return list<list<int>>
     */
    public function groups(): array
    {
        return array_values(array_map(
            fn (array $sizeIds): array => array_values(array_map(intval(...), $sizeIds)),
            $this->validated('groups'),
        ));
    }

    public function confirmToken(): ?string
    {
        $token = $this->validated('confirm_token');

        return is_string($token) ? $token : null;
    }
}
