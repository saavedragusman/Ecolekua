<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use App\Support\Customers\CustomerRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return CustomerRules::customer();
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
}
