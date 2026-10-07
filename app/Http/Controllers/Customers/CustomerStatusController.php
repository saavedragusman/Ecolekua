<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\ActivateCustomer;
use App\Actions\Customers\DeactivateCustomer;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class CustomerStatusController extends Controller
{
    public function activate(Request $request, Customer $customer, ActivateCustomer $activateCustomer): RedirectResponse
    {
        Gate::authorize('deactivate', $customer);

        $activateCustomer->handle($customer, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El cliente fue reactivado.']);

        return redirect()->route('customers.show', $customer);
    }

    public function deactivate(Request $request, Customer $customer, DeactivateCustomer $deactivateCustomer): RedirectResponse
    {
        Gate::authorize('deactivate', $customer);

        $deactivateCustomer->handle($customer, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El cliente fue desactivado.']);

        return redirect()->route('customers.show', $customer);
    }
}
