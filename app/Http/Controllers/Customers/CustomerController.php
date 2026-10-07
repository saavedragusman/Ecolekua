<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Enums\CustomerStatus;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Support\Customers\CustomerPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /**
     * Design Decision 13: `?status=active|inactive|all&q=…&mine=1`. The default and any unknown
     * status is `active`; `mine` only applies to users holding `customers.portfolio`; the counts
     * of the three views use the same `q` and `mine` filters, whatever the selected view.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $actor = $request->user();
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ['active', 'inactive', 'all'], true) ? $status : 'active';
        $term = $request->query('q');
        $term = is_string($term) ? mb_substr(trim($term), 0, 100) : '';
        $canFilterMine = $actor->hasPermission(PermissionName::CustomersPortfolio);
        $mine = $canFilterMine && $request->boolean('mine');

        $filtered = Customer::query()
            ->search($term)
            ->when($mine, fn (Builder $query) => $query->assignedTo($actor));

        $totals = (clone $filtered)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $active = (int) ($totals[CustomerStatus::Active->value] ?? 0);
        $inactive = (int) ($totals[CustomerStatus::Inactive->value] ?? 0);

        $page = $filtered
            ->when($status !== 'all', fn (Builder $query) => $query->withStatus(CustomerStatus::from($status)))
            ->with('advisor')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $availableAdvisorIds = CustomerPresenter::availableAdvisorIds($page->getCollection());

        return Inertia::render('customers/Index', [
            'status' => $status,
            'q' => $term,
            'mine' => $mine,
            'canFilterMine' => $canFilterMine,
            'counts' => ['active' => $active, 'inactive' => $inactive, 'all' => $active + $inactive],
            'customers' => $page->through(fn (Customer $customer): array => CustomerPresenter::listRow($customer, $availableAdvisorIds)),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('customers/Create', CustomerPresenter::formOptions());
    }

    public function show(Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        $customer->load(['contact', 'address', 'advisor']);

        return Inertia::render('customers/Show', [
            'customer' => CustomerPresenter::detail($customer, CustomerPresenter::availableAdvisorIds(collect([$customer]))),
        ]);
    }

    public function store(StoreCustomerRequest $request, CreateCustomer $createCustomer): RedirectResponse
    {
        $customer = $createCustomer->handle(
            $request->validated(),
            $request->user(),
            confirmDuplicatePhone: $request->boolean('confirm_duplicate_phone'),
        );

        Inertia::flash(['type' => 'success', 'message' => 'El cliente fue registrado.']);

        return redirect()->route('customers.show', $customer);
    }

    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        $customer->load(['contact', 'address']);

        return Inertia::render('customers/Edit', [
            'customer' => CustomerPresenter::editable($customer),
            ...CustomerPresenter::formOptions(),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomer $updateCustomer): RedirectResponse
    {
        $customer = $updateCustomer->handle(
            $customer,
            $request->validated(),
            $request->user(),
            confirmDuplicatePhone: $request->boolean('confirm_duplicate_phone'),
        );

        Inertia::flash(['type' => 'success', 'message' => 'El cliente fue actualizado.']);

        return redirect()->route('customers.show', $customer);
    }
}
