<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\CreateCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function store(StoreCustomerRequest $request, CreateCustomer $createCustomer): RedirectResponse
    {
        $customer = $createCustomer->handle(
            $request->validated(),
            $request->user(),
            confirmDuplicatePhone: $request->boolean('confirm_duplicate_phone'),
        );

        Inertia::flash(['type' => 'success', 'message' => 'El cliente fue registrado.']);

        // The detail page and its named route (`customers.show`) arrive with task 5.6; the URL is
        // already fixed by design "Routes and authorization", so the redirect is correct today.
        return redirect("/customers/{$customer->getKey()}");
    }
}
