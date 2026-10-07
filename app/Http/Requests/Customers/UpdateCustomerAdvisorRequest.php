<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerAdvisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return $this->user()->can('assign', $customer);
    }

    /**
     * `advisor_id` must be sent: `null` removes the advisor (DEC-CLI-18). Eligibility is checked by
     * `AssignCustomerAdvisor`, so every caller of the Action gets the same rule (E-28).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'advisor_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
