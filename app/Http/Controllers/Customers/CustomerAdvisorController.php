<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\AssignCustomerAdvisor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\UpdateCustomerAdvisorRequest;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CustomerAdvisorController extends Controller
{
    public function update(UpdateCustomerAdvisorRequest $request, Customer $customer, AssignCustomerAdvisor $assignCustomerAdvisor): RedirectResponse
    {
        $advisorId = $request->validated('advisor_id');
        $advisor = $advisorId === null ? null : User::query()->whereKey($advisorId)->firstOrFail();

        $assignCustomerAdvisor->handle($customer, $advisor, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La asesora del cliente fue actualizada.']);

        return redirect()->route('customers.show', $customer);
    }
}
