<?php

use App\Enums\DocumentType;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Permission;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->viewer = userWithPermissions(PermissionName::CustomersView);
});

/**
 * @return array<string, mixed>
 */
function listProps(Assert $page): array
{
    return $page->toArray()['props'];
}

/**
 * @return list<string>
 */
function listedCustomerNames(Assert $page): array
{
    return collect(listProps($page)['customers']['data'])->pluck('name')->all();
}

it('E-04 (list) forbids the list to a user without customers.view', function () {
    $user = userWithPermissions(PermissionName::CustomersCreate, PermissionName::CustomersUpdate);

    $this->actingAs($user)->get('/customers')->assertForbidden();
});

it('E-04 (list) redirects a guest to the login', function () {
    $this->get('/customers')->assertRedirect('/login');
});

it('E-11 searches by a name fragment', function () {
    Customer::factory()->create(['name' => 'Textiles Aurora']);
    Customer::factory()->create(['name' => 'Pañales Luna']);
    Customer::factory()->create(['name' => 'María Aurora Pérez']);

    $this->actingAs($this->viewer)->get('/customers?q=aurora')->assertInertia(function (Assert $page) {
        $page->component('customers/Index')->where('q', 'aurora');

        expect(listedCustomerNames($page))->toBe(['María Aurora Pérez', 'Textiles Aurora']);
    });
});

it('E-11 matches a name regardless of accents and case', function () {
    Customer::factory()->create(['name' => 'María Pérez']);
    Customer::factory()->create(['name' => 'Otro cliente']);

    $this->actingAs($this->viewer)->get('/customers?q=MARIA')->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toBe(['María Pérez']);
    });
});

it('E-11 searches by document with or without letter, hyphens and case', function (string $term) {
    // Explicit phones: a digits-only term such as `1234` is also a phone fragment.
    Customer::factory()->company()->create(['name' => 'Con RIF', 'phone' => '+584240000001', 'document_type' => DocumentType::RifJ, 'document_number' => 'J123456784']);
    Customer::factory()->create(['name' => 'Sin documento', 'phone' => '+584240000002']);
    Customer::factory()->create(['name' => 'Otra cédula', 'phone' => '+584240000003', 'document_type' => DocumentType::CedulaV, 'document_number' => 'V99887766']);

    $this->actingAs($this->viewer)->get('/customers?'.http_build_query(['q' => $term]))->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toBe(['Con RIF']);
    });
})->with(['J-1234', 'j1234', '1234', 'J-12.345.678-4']);

it('E-11 searches by phone in local, spaced and international forms', function (string $term) {
    Customer::factory()->create(['name' => 'Coincide', 'phone' => '+584141234567']);
    Customer::factory()->create(['name' => 'No coincide', 'phone' => '+584249998877']);

    $this->actingAs($this->viewer)->get('/customers?'.http_build_query(['q' => $term]))->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toBe(['Coincide']);
    });
})->with(['0414 123', '414-123', '+58 414 123', '1234567']);

it('E-11 does not treat a term with fewer than 3 digits as a phone fragment', function () {
    Customer::factory()->create(['name' => 'Cliente uno', 'phone' => '+584141234567']);

    $this->actingAs($this->viewer)->get('/customers?q=41')->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toBe([]);
    });
});

it('E-11 escapes % and _ so they match literally', function () {
    Customer::factory()->create(['name' => 'Descuento 100% Hogar']);
    Customer::factory()->create(['name' => 'Descuento 1000 Hogar']);
    Customer::factory()->create(['name' => 'Taller A_B']);
    Customer::factory()->create(['name' => 'Taller AxB']);

    $this->actingAs($this->viewer)->get('/customers?'.http_build_query(['q' => '100%']))->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toBe(['Descuento 100% Hogar']);
    });

    $this->actingAs($this->viewer)->get('/customers?'.http_build_query(['q' => 'A_B']))->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toBe(['Taller A_B']);
    });
});

it('E-11 returns every active customer when the search is blank', function () {
    Customer::factory()->count(3)->create();

    $this->actingAs($this->viewer)->get('/customers?q=%20%20')->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toHaveCount(3);
        expect(listProps($page)['q'])->toBe('');
    });
});

it('E-12 lists only active customers by default and falls back to active on an unknown status', function (string $query) {
    Customer::factory()->create(['name' => 'Activo uno']);
    Customer::factory()->inactive()->create(['name' => 'Inactivo uno']);

    $this->actingAs($this->viewer)->get('/customers'.$query)->assertInertia(function (Assert $page) {
        $page->where('status', 'active');

        expect(listedCustomerNames($page))->toBe(['Activo uno']);
    });
})->with(['', '?status=borrados', '?status[]=inactive']);

it('E-12 the Inactivos view lists only inactive customers', function () {
    Customer::factory()->create(['name' => 'Activo uno']);
    Customer::factory()->inactive()->create(['name' => 'Inactivo uno']);

    $this->actingAs($this->viewer)->get('/customers?status=inactive')->assertInertia(function (Assert $page) {
        $page->where('status', 'inactive');

        expect(listedCustomerNames($page))->toBe(['Inactivo uno']);
    });
});

it('E-12 the Todos view lists active and inactive customers', function () {
    Customer::factory()->create(['name' => 'Activo uno']);
    Customer::factory()->inactive()->create(['name' => 'Inactivo uno']);

    $this->actingAs($this->viewer)->get('/customers?status=all')->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toBe(['Activo uno', 'Inactivo uno']);
    });
});

it('E-12 counts match the database', function () {
    Customer::factory()->count(3)->create();
    Customer::factory()->count(2)->inactive()->create();

    $this->actingAs($this->viewer)->get('/customers')->assertInertia(function (Assert $page) {
        $page->where('counts', ['active' => 3, 'inactive' => 2, 'all' => 5]);
    });
});

it('E-12 counts apply the same search term, whatever the selected view', function () {
    Customer::factory()->create(['name' => 'Aurora activa']);
    Customer::factory()->create(['name' => 'Luna activa']);
    Customer::factory()->inactive()->create(['name' => 'Aurora inactiva']);
    Customer::factory()->inactive()->create(['name' => 'Sol inactiva']);

    $this->actingAs($this->viewer)->get('/customers?q=aurora&status=inactive')->assertInertia(function (Assert $page) {
        $page->where('counts', ['active' => 1, 'inactive' => 1, 'all' => 2]);

        expect(listedCustomerNames($page))->toBe(['Aurora inactiva']);
    });
});

it('E-15 shows every portfolio and unassigned customers without the filter, and only mine with it', function () {
    $actor = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersPortfolio);
    $other = userWithPermissions(PermissionName::CustomersPortfolio);

    Customer::factory()->assignedTo($actor)->create(['name' => 'De la actora']);
    Customer::factory()->assignedTo($other)->create(['name' => 'De la otra']);
    Customer::factory()->create(['name' => 'Sin asesora']);

    $this->actingAs($actor)->get('/customers')->assertInertia(function (Assert $page) {
        $page->where('canFilterMine', true)->where('mine', false);

        expect(listedCustomerNames($page))->toBe(['De la actora', 'De la otra', 'Sin asesora']);
    });

    $this->actingAs($actor)->get('/customers?mine=1')->assertInertia(function (Assert $page) {
        $page->where('mine', true);

        expect(listedCustomerNames($page))->toBe(['De la actora']);
    });
});

it('E-15 mine combines with the status view, the search term and the counts', function () {
    $actor = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersPortfolio);
    $other = userWithPermissions(PermissionName::CustomersPortfolio);

    Customer::factory()->assignedTo($actor)->create(['name' => 'Aurora mía activa']);
    Customer::factory()->assignedTo($actor)->inactive()->create(['name' => 'Aurora mía inactiva']);
    Customer::factory()->assignedTo($actor)->create(['name' => 'Luna mía']);
    Customer::factory()->assignedTo($other)->create(['name' => 'Aurora ajena']);

    $this->actingAs($actor)->get('/customers?mine=1&q=aurora&status=inactive')->assertInertia(function (Assert $page) {
        $page->where('counts', ['active' => 1, 'inactive' => 1, 'all' => 2]);

        expect(listedCustomerNames($page))->toBe(['Aurora mía inactiva']);
    });
});

it('E-15 treats mine=1 from a user without customers.portfolio as the filter off', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);

    Customer::factory()->assignedTo($advisor)->create(['name' => 'De la asesora']);
    Customer::factory()->create(['name' => 'Sin asesora']);

    $this->actingAs($this->viewer)->get('/customers?mine=1')->assertInertia(function (Assert $page) {
        $page->where('canFilterMine', false)->where('mine', false);

        expect(listedCustomerNames($page))->toBe(['De la asesora', 'Sin asesora']);
    });
});

it('CLI-011 paginates 15 per page and keeps the query string in the links', function () {
    foreach (range(1, 20) as $number) {
        Customer::factory()->create(['name' => sprintf('Cliente %02d', $number)]);
    }

    $this->actingAs($this->viewer)->get('/customers?q=cliente&status=active')->assertInertia(function (Assert $page) {
        $customers = listProps($page)['customers'];

        expect($customers['data'])->toHaveCount(15)
            ->and($customers['last_page'])->toBe(2)
            ->and($customers['next_page_url'])->toContain('q=cliente')->toContain('status=active');
    });

    $this->actingAs($this->viewer)->get('/customers?q=cliente&page=2')->assertInertia(function (Assert $page) {
        expect(listedCustomerNames($page))->toHaveCount(5);
    });
});

it('CLI-011 orders by name and then by id', function () {
    $second = Customer::factory()->create(['name' => 'Mismo nombre']);
    $first = Customer::factory()->create(['name' => 'Alfa']);
    $third = Customer::factory()->create(['name' => 'Mismo nombre']);

    $this->actingAs($this->viewer)->get('/customers')->assertInertia(function (Assert $page) use ($first, $second, $third) {
        expect(collect(listProps($page)['customers']['data'])->pluck('id')->all())
            ->toBe([$first->id, $second->id, $third->id]);
    });
});

it('CLI-011 each row carries the display strings the list shows', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $advisor->update(['first_name' => 'Ana', 'last_name' => 'Gómez']);

    $company = Customer::factory()->company()->assignedTo($advisor)->create([
        'name' => 'Textiles Aurora',
        'document_type' => DocumentType::RifJ,
        'document_number' => 'J123456784',
        'phone' => '+584141234567',
    ]);

    $this->actingAs($this->viewer)->get('/customers')->assertInertia(function (Assert $page) use ($company, $advisor) {
        $page->where('customers.data.0.id', $company->id)
            ->where('customers.data.0.name', 'Textiles Aurora')
            ->where('customers.data.0.type', 'company')
            ->where('customers.data.0.type_label', 'Empresa')
            ->where('customers.data.0.document', 'J-12345678-4')
            ->where('customers.data.0.phone', '0414-123-4567')
            ->where('customers.data.0.status', 'active')
            ->where('customers.data.0.status_label', 'Activo')
            ->where('customers.data.0.advisor.id', $advisor->id)
            ->where('customers.data.0.advisor.name', 'Ana Gómez');
    });
});

it('CLI-011 a customer without document nor advisor has null document and advisor', function () {
    Customer::factory()->create(['name' => 'Sin extras']);

    $this->actingAs($this->viewer)->get('/customers')->assertInertia(function (Assert $page) {
        $page->where('customers.data.0.document', null)->where('customers.data.0.advisor', null);
    });
});

it('E-33 (list) flags an advisor that is no longer eligible as unavailable, without unassigning', function () {
    $eligible = userWithPermissions(PermissionName::CustomersPortfolio);
    $deactivated = userWithPermissions(PermissionName::CustomersPortfolio);
    $revoked = userWithPermissions(PermissionName::CustomersPortfolio);

    Customer::factory()->assignedTo($eligible)->create(['name' => 'Cliente A']);
    Customer::factory()->assignedTo($deactivated)->create(['name' => 'Cliente B']);
    Customer::factory()->assignedTo($revoked)->create(['name' => 'Cliente C']);

    $deactivated->update(['is_active' => false]);
    $permission = Permission::query()->where('name', PermissionName::CustomersPortfolio->value)->firstOrFail();
    $revoked->roles()->firstOrFail()->permissions()->detach($permission->id);

    $this->actingAs($this->viewer)->get('/customers')->assertInertia(function (Assert $page) use ($eligible, $deactivated, $revoked) {
        $page->where('customers.data.0.advisor.id', $eligible->id)
            ->where('customers.data.0.advisor.available', true)
            ->where('customers.data.1.advisor.id', $deactivated->id)
            ->where('customers.data.1.advisor.available', false)
            ->where('customers.data.2.advisor.id', $revoked->id)
            ->where('customers.data.2.advisor.available', false);
    });

    expect(Customer::query()->whereNotNull('advisor_id')->count())->toBe(3);
});

it('CLI-011 an inactive customer row shows the Inactivo label', function () {
    Customer::factory()->inactive()->create();

    $this->actingAs($this->viewer)->get('/customers?status=inactive')->assertInertia(function (Assert $page) {
        $page->where('customers.data.0.status_label', 'Inactivo');
    });
});
