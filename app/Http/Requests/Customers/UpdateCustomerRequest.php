<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Support\Customers\CustomerRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->customer());
    }

    /**
     * Validated against the submitted final type, ignoring the customer's own document (E-13, E-24).
     * `advisor_id`, `status` and `created_by` are not rules, so `validated()` never carries them.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CustomerRules::customer($this->customer());
    }

    /**
     * Cross-field checks: day/month combinations and document type against customer type.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return CustomerRules::after();
    }

    private function customer(): Customer
    {
        /** @var Customer */
        return $this->route('customer');
    }
}
