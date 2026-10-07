<?php

use App\Enums\DocumentType;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->viewer = userWithPermissions(PermissionName::CustomersView);
});

it('E-04 (detail) forbids the page to a user without customers.view', function () {
    $customer = Customer::factory()->create();
    $user = userWithPermissions(PermissionName::CustomersCreate, PermissionName::CustomersUpdate);

    $this->actingAs($user)->get("/customers/{$customer->id}")->assertForbidden();
});

it('E-04 (detail) redirects a guest to the login', function () {
    $customer = Customer::factory()->create();

    $this->get("/customers/{$customer->id}")->assertRedirect('/login');
});

it('CLI-013 answers 404 for a customer that does not exist', function () {
    $this->actingAs($this->viewer)->get('/customers/999999')->assertNotFound();
});

it('CLI-013 the page exposes the general data, contact, address, notes and advisor with display strings', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $advisor->update(['first_name' => 'Ana', 'last_name' => 'Gómez']);

    $customer = Customer::factory()->company()->assignedTo($advisor)->create([
        'name' => 'Textiles Aurora',
        'document_type' => DocumentType::RifJ,
        'document_number' => 'J123456784',
        'phone' => '+584141234567',
        'email' => 'ventas@aurora.example.test',
        'birthday_day' => 29,
        'birthday_month' => 2,
        'anniversary_day' => 5,
        'anniversary_month' => 11,
        'notes' => 'Prefiere entregas por la tarde.',
        'created_at' => '2026-03-01 02:30:00',
    ]);
    CustomerContact::factory()->for($customer)->create([
        'name' => 'Luis Pérez', 'position' => 'Compras', 'phone' => '+584249876543', 'email' => 'luis@aurora.example.test',
    ]);
    CustomerAddress::factory()->for($customer)->create([
        'line' => 'Av. Principal, local 3', 'city' => 'Valencia', 'state' => 'carabobo', 'reference' => 'Frente a la plaza',
    ]);

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) use ($customer, $advisor) {
        $page->component('customers/Show')
            ->where('customer.id', $customer->id)
            ->where('customer.name', 'Textiles Aurora')
            ->where('customer.type', 'company')
            ->where('customer.type_label', 'Empresa')
            ->where('customer.status', 'active')
            ->where('customer.status_label', 'Activo')
            ->where('customer.document_type_label', DocumentType::RifJ->label())
            ->where('customer.document', 'J-12345678-4')
            ->where('customer.phone', '0414-123-4567')
            ->where('customer.email', 'ventas@aurora.example.test')
            ->where('customer.birthday', '29 de febrero')
            ->where('customer.anniversary', '5 de noviembre')
            ->where('customer.notes', 'Prefiere entregas por la tarde.')
            // 2026-03-01 02:30 UTC is 28/02/2026 22:30 in America/Caracas (UTC-4).
            ->where('customer.created_at', '28/02/2026 22:30:00')
            ->where('customer.contact.name', 'Luis Pérez')
            ->where('customer.contact.position', 'Compras')
            ->where('customer.contact.phone', '0424-987-6543')
            ->where('customer.contact.email', 'luis@aurora.example.test')
            ->where('customer.address.line', 'Av. Principal, local 3')
            ->where('customer.address.city', 'Valencia')
            ->where('customer.address.state_label', 'Carabobo')
            ->where('customer.address.reference', 'Frente a la plaza')
            ->where('customer.advisor.id', $advisor->id)
            ->where('customer.advisor.name', 'Ana Gómez');
    });
});

it('CLI-013 optional sections are null when the customer has none of that data', function () {
    $customer = Customer::factory()->create(['email' => null, 'notes' => null]);

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) {
        $page->where('customer.document', null)
            ->where('customer.document_type_label', null)
            ->where('customer.email', null)
            ->where('customer.birthday', null)
            ->where('customer.anniversary', null)
            ->where('customer.notes', null)
            ->where('customer.contact', null)
            ->where('customer.address', null)
            ->where('customer.advisor', null);
    });
});

it('CLI-013 shows an inactive customer with its Inactivo label', function () {
    $customer = Customer::factory()->inactive()->create();

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) {
        $page->where('customer.status', 'inactive')->where('customer.status_label', 'Inactivo');
    });
});

it('CLI-013 advisorOptions is not sent until the assignment control exists', function () {
    $customer = Customer::factory()->create();
    $assigner = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersAssign);

    $this->actingAs($assigner)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) {
        $page->missing('advisorOptions');
    });
});

it('E-06 the customer page shows a stored +584141234567 as 0414-123-4567', function () {
    $customer = Customer::factory()->create(['phone' => '+584141234567']);

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) {
        $page->where('customer.phone', '0414-123-4567');
    });
});

it('E-16 the detail shows the contact person of a company', function () {
    $customer = Customer::factory()->company()->withContact()->create();

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) use ($customer) {
        $page->where('customer.contact.name', $customer->contact->name);
    });
});

it('E-17 the detail shows the address with the state label', function () {
    $customer = Customer::factory()->create();
    CustomerAddress::factory()->for($customer)->create(['state' => 'distrito_capital']);

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) {
        $page->where('customer.address.state_label', 'Distrito Capital');
    });
});

it('E-20 the detail shows a birthday without a year', function (int $day, int $month, string $expected) {
    $customer = Customer::factory()->create(['birthday_day' => $day, 'birthday_month' => $month]);

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) use ($expected) {
        $page->where('customer.birthday', $expected);
    });
})->with([
    [1, 1, '1 de enero'],
    [29, 2, '29 de febrero'],
    [31, 12, '31 de diciembre'],
]);

it('E-33 (detail) keeps the assignment and flags the advisor unavailable after deactivating the user', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $customer = Customer::factory()->assignedTo($advisor)->create();

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) {
        $page->where('customer.advisor.available', true);
    });

    $advisor->update(['is_active' => false]);

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) use ($advisor) {
        $page->where('customer.advisor.id', $advisor->id)->where('customer.advisor.available', false);
    });

    expect($customer->refresh()->advisor_id)->toBe($advisor->id);
});

it('E-33 (detail) keeps the assignment and flags the advisor unavailable after revoking customers.portfolio', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $customer = Customer::factory()->assignedTo($advisor)->create();

    $permission = Permission::query()->where('name', PermissionName::CustomersPortfolio->value)->firstOrFail();
    $advisor->roles()->firstOrFail()->permissions()->detach($permission->id);

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) use ($advisor) {
        $page->where('customer.advisor.id', $advisor->id)->where('customer.advisor.available', false);
    });

    expect($customer->refresh()->advisor_id)->toBe($advisor->id);
});

it('E-33 (detail) an advisor that is active and holds customers.portfolio is available', function () {
    $advisor = User::factory()->withPermissions(PermissionName::CustomersPortfolio)->create();
    $customer = Customer::factory()->assignedTo($advisor)->create();

    $this->actingAs($this->viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) {
        $page->where('customer.advisor.available', true);
    });
});
