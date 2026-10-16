<?php

namespace App\Http\Requests\Quotations;

use App\Models\Quotation;
use App\Support\Quotations\QuotationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A new draft quotation (COT-013): its recipient (a customer or a prospect) and, optionally, its advisor.
 */
class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Quotation::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return QuotationRules::recipient();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [QuotationRules::validateOneRecipient(...)];
    }
}
