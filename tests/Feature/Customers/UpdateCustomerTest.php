<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Customers\UpdateCustomer;
use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\PermissionName;
use App\Enums\VenezuelanState;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function customerUpdater(PermissionName ...$extra): User
{
    return userWithPermissions(PermissionName::CustomersUpdate, ...$extra);
}

function updatedAuditRows(): Collection
{
    return AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->get();
}

it('E-05 saves a changed phone in E.164 and audits only the previous and new phone', function () {
    $actor = customerUpdater();
    $customer = Customer::factory()->create(['phone' => '+584141234567']);

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['phone' => '0416-765.43.21']))
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->phone)->toBe('+584167654321');

    $audit = AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($customer->getMorphClass())
        ->and($audit->entity_id)->toBe($customer->id)
        ->and($audit->old_values)->toEqual(['phone' => '+584141234567'])
        ->and($audit->new_values)->toEqual(['phone' => '+584167654321']);
});

it('E-05 writes no audit row when the request changes nothing, even if the phone is typed differently', function () {
    $customer = Customer::factory()->create(['phone' => '+584141234567']);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['phone' => '0414-123.45.67']))
        ->assertRedirect();

    expect(updatedAuditRows())->toHaveCount(0)
        ->and($customer->fresh()->phone)->toBe('+584141234567');
});

it('E-05 audits several changed fields and leaves the unchanged ones out', function () {
    $customer = Customer::factory()->create(['name' => 'Nombre anterior', 'email' => 'anterior@cliente-prueba.test']);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'name' => 'Nombre nuevo',
            'birthday_day' => 29,
            'birthday_month' => 2,
        ]))
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole();

    expect($audit->old_values)->toEqual(['name' => 'Nombre anterior', 'birthday_day' => null, 'birthday_month' => null])
        ->and($audit->new_values)->toEqual(['name' => 'Nombre nuevo', 'birthday_day' => 29, 'birthday_month' => 2]);
});

it('E-13 (edit) rejects another customers document on document_number and leaves the row and audit untouched', function () {
    $other = Customer::factory()->withDocument()->create();
    $customer = Customer::factory()->create(['name' => 'Sin cambios']);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'name' => 'Cambio rechazado',
            'document_type' => $other->document_type->value,
            'document_number' => $other->document_number,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document_number']);

    expect($customer->fresh()->name)->toBe('Sin cambios')
        ->and($customer->fresh()->document_number)->toBeNull()
        ->and(updatedAuditRows())->toHaveCount(0);
});

it('E-13 (edit) lets a customer keep its own document, typed in any form', function () {
    $customer = Customer::factory()->withDocument(DocumentType::CedulaV)->create();
    $typed = 'v-'.substr((string) $customer->document_number, 1);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['document_number' => $typed, 'name' => 'Nombre nuevo']))
        ->assertRedirect();

    expect($customer->fresh()->name)->toBe('Nombre nuevo');
});

it('E-13 (edit) turns a unique-index race on the document into the same validation error and writes nothing', function () {
    $actor = customerUpdater();
    $other = Customer::factory()->withDocument(DocumentType::CedulaV)->create();
    $customer = Customer::factory()->create(['name' => 'Sin cambios']);

    // Calling the Action directly skips FormRequest validation, as a race that passed it would.
    $data = updateCustomerPayload($customer, [
        'name' => 'Cambio perdido',
        'document_type' => 'cedula_v',
        'document_number' => $other->document_number,
    ]);

    try {
        app(UpdateCustomer::class)->handle($customer, $data, $actor);
        $this->fail('The document race was not rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('document_number');
    }

    expect($customer->fresh()->name)->toBe('Sin cambios')
        ->and(updatedAuditRows())->toHaveCount(0);
});

it('E-13 (edit) rethrows a unique violation on any index other than the document one instead of converting it', function () {
    $customer = Customer::factory()->create(['name' => 'Sin cambios']);

    $violation = new UniqueConstraintViolationException(
        'mysql',
        'update `customers` set ...',
        [],
        new Exception("Duplicate entry 'x' for key 'customers.some_other_unique'"),
    );

    $this->mock(RecordAuditEvent::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow($violation));

    expect(fn () => app(UpdateCustomer::class)->handle(
        $customer,
        updateCustomerPayload($customer, ['name' => 'Cambio revertido']),
        customerUpdater(),
    ))->toThrow(UniqueConstraintViolationException::class);

    expect($customer->fresh()->name)->toBe('Sin cambios');
});

it('E-16 stores a contact person for a company without one and replaces it on the next edit', function () {
    $actor = customerUpdater();
    $customer = Customer::factory()->company()->create();

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'contact' => ['name' => 'Persona de contacto', 'position' => 'Compras', 'phone' => '0212-555.12.34', 'email' => 'contacto@empresa-prueba.test'],
        ]))
        ->assertRedirect();

    expect($customer->fresh()->contact?->only(['name', 'position', 'phone', 'email']))->toBe([
        'name' => 'Persona de contacto',
        'position' => 'Compras',
        'phone' => '+582125551234',
        'email' => 'contacto@empresa-prueba.test',
    ]);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole();

    expect($audit->old_values)->toEqual(['contact' => null])
        ->and($audit->new_values['contact']['phone'])->toBe('+582125551234');

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer->fresh(), [
            'contact' => ['name' => 'Otra persona', 'phone' => '0414-765.43.21'],
        ]))
        ->assertRedirect();

    expect(CustomerContact::query()->count())->toBe(1)
        ->and($customer->fresh()->contact?->name)->toBe('Otra persona')
        ->and($customer->fresh()->contact?->position)->toBeNull();
});

it('E-16 deletes the contact person when the request sends null', function () {
    $customer = Customer::factory()->company()->withContact()->create();

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['contact' => null]))
        ->assertRedirect();

    expect(CustomerContact::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole();

    expect($audit->old_values['contact'])->toBeArray()
        ->and($audit->new_values)->toEqual(['contact' => null]);
});

it('E-16 rejects a contact person for a natural customer on contact', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'contact' => ['name' => 'Persona de contacto', 'phone' => '0212-555.12.34'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact']);

    expect(CustomerContact::query()->count())->toBe(0);
});

it('E-17 registers an address, keeps exactly one address on edit and rejects an unknown state', function () {
    $actor = customerUpdater();
    $customer = Customer::factory()->create();

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'address' => ['line' => 'Calle 1, local 2', 'city' => 'Caracas', 'state' => 'distrito_capital', 'reference' => 'Frente a la plaza'],
        ]))
        ->assertRedirect();

    expect($customer->fresh()->address?->state->value)->toBe('distrito_capital');

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer->fresh(), [
            'address' => ['line' => 'Avenida 3', 'city' => 'Valencia', 'state' => 'carabobo'],
        ]))
        ->assertRedirect();

    expect(CustomerAddress::query()->count())->toBe(1)
        ->and($customer->fresh()->address?->only(['line', 'city', 'reference']))->toBe(['line' => 'Avenida 3', 'city' => 'Valencia', 'reference' => null]);

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer->fresh(), [
            'address' => ['line' => 'Avenida 3', 'city' => 'Valencia', 'state' => 'Narnia'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address.state']);

    expect($customer->fresh()->address?->state->value)->toBe('carabobo');
});

it('E-17 deletes the address when the request sends null', function () {
    $customer = Customer::factory()->withAddress()->create();

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['address' => null]))
        ->assertRedirect();

    expect(CustomerAddress::query()->count())->toBe(0);
});

it('E-23 clears the anniversary and the contact person when a company becomes natural, and audits what was removed', function () {
    $customer = Customer::factory()->company()->withContact()->create(['anniversary_day' => 15, 'anniversary_month' => 6]);
    $contact = $customer->contact->only(['name', 'position', 'phone', 'email']);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'type' => 'natural',
            'anniversary_day' => null,
            'anniversary_month' => null,
            'contact' => null,
        ]))
        ->assertRedirect();

    $customer = $customer->fresh();

    expect($customer->type)->toBe(CustomerType::Natural)
        ->and($customer->anniversary_day)->toBeNull()
        ->and($customer->anniversary_month)->toBeNull()
        ->and($customer->contact)->toBeNull()
        ->and(CustomerContact::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole();

    expect($audit->old_values)->toEqual([
        'type' => 'company',
        'anniversary_day' => 15,
        'anniversary_month' => 6,
        'contact' => $contact,
    ])->and($audit->new_values)->toEqual([
        'type' => 'natural',
        'anniversary_day' => null,
        'anniversary_month' => null,
        'contact' => null,
    ]);
});

it('E-23 cleans the anniversary and the contact even if a caller omits them from the payload', function () {
    $customer = Customer::factory()->company()->withContact()->create(['anniversary_day' => 15, 'anniversary_month' => 6]);

    $data = updateCustomerPayload($customer, ['type' => 'natural']);
    unset($data['anniversary_day'], $data['anniversary_month'], $data['contact']);

    app(UpdateCustomer::class)->handle($customer, $data, customerUpdater());

    expect($customer->fresh()->anniversary_day)->toBeNull()
        ->and($customer->fresh()->contact)->toBeNull();
});

it('E-24 rejects a company becoming natural while keeping a RIF J on document_type and changes nothing', function () {
    $customer = Customer::factory()->company()->withContact()->withDocument(DocumentType::RifJ)->create(['anniversary_day' => 15, 'anniversary_month' => 6]);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'type' => 'natural',
            'anniversary_day' => null,
            'anniversary_month' => null,
            'contact' => null,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document_type']);

    $customer = $customer->fresh();

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->anniversary_day)->toBe(15)
        ->and($customer->contact)->not->toBeNull()
        ->and(updatedAuditRows())->toHaveCount(0);
});

it('E-24 accepts the same change once the document is replaced by one valid for a natural customer', function () {
    $customer = Customer::factory()->company()->withDocument(DocumentType::RifJ)->create();

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'type' => 'natural',
            'document_type' => 'cedula_v',
            'document_number' => 'V-12.345.678',
        ]))
        ->assertRedirect();

    expect($customer->fresh()->type)->toBe(CustomerType::Natural)
        ->and($customer->fresh()->document_number)->toBe('V12345678');
});

it('E-38 saves a landline contact phone normalized and rejects a foreign one on contact.phone', function () {
    $actor = customerUpdater();
    $customer = Customer::factory()->company()->create();

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'contact' => ['name' => 'Persona de contacto', 'phone' => '0212-555.12.34'],
        ]))
        ->assertRedirect();

    expect($customer->fresh()->contact?->phone)->toBe('+582125551234');

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer->fresh(), [
            'contact' => ['name' => 'Persona de contacto', 'phone' => '+1 305 555 0100'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact.phone']);

    expect($customer->fresh()->contact?->phone)->toBe('+582125551234');
});

it('CLI-007 edits the notes, audits the change and enforces the 5000 character limit', function () {
    $customer = Customer::factory()->create(['notes' => 'Nota anterior']);
    $notes = str_repeat('a', 5000);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['notes' => $notes]))
        ->assertRedirect();

    expect($customer->fresh()->notes)->toBe($notes);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole();

    expect($audit->old_values)->toEqual(['notes' => 'Nota anterior'])
        ->and($audit->new_values)->toEqual(['notes' => $notes]);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['notes' => str_repeat('a', 5001)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notes']);
});

it('E-03 (update) denies a user without customers.update, changes nothing and audits authorization.denied', function () {
    $actor = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersCreate);
    $customer = Customer::factory()->create(['name' => 'Sin cambios']);

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['name' => 'Cambio denegado']))
        ->assertForbidden();

    expect($customer->fresh()->name)->toBe('Sin cambios');

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toBe('customers.update')
        ->and($audit->context['method'])->toBe('PUT')
        ->and(updatedAuditRows())->toHaveCount(0);
});

it('CLI-008 ignores advisor_id, status and created_by in the payload and edits an inactive customer', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $intruder = userWithPermissions(PermissionName::CustomersPortfolio);
    $creator = User::factory()->create();
    $customer = Customer::factory()->inactive()->assignedTo($advisor)->create(['created_by' => $creator->id, 'name' => 'Nombre anterior']);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'name' => 'Nombre nuevo',
            'advisor_id' => $intruder->id,
            'status' => 'active',
            'created_by' => $intruder->id,
        ]))
        ->assertRedirect();

    $customer = $customer->fresh();

    expect($customer->name)->toBe('Nombre nuevo')
        ->and($customer->advisor_id)->toBe($advisor->id)
        ->and($customer->status)->toBe(CustomerStatus::Inactive)
        ->and($customer->created_by)->toBe($creator->id);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole();

    expect(array_keys($audit->new_values))->toBe(['name']);
});

it('CLI-008 rejects a missing mandatory field on that field and changes nothing', function (string $field) {
    $customer = Customer::factory()->create();
    $payload = updateCustomerPayload($customer);
    unset($payload[$field]);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(updatedAuditRows())->toHaveCount(0);
})->with(['type', 'name', 'phone']);

it('E-03 (edit form) forbids the edit form to a user without customers.update', function () {
    $customer = Customer::factory()->create();
    $user = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersCreate);

    $this->actingAs($user)->get("/customers/{$customer->id}/edit")->assertForbidden();
});

it('E-03 (edit form) redirects a guest to the login', function () {
    $customer = Customer::factory()->create();

    $this->get("/customers/{$customer->id}/edit")->assertRedirect('/login');
});

it('CLI-008 renders the edit form with the stored customer as an editable DTO and the form options', function () {
    $customer = Customer::factory()->company()->create([
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
    ]);
    CustomerContact::factory()->for($customer)->create([
        'name' => 'Luis Pérez', 'position' => 'Compras', 'phone' => '+584249876543', 'email' => 'luis@aurora.example.test',
    ]);
    CustomerAddress::factory()->for($customer)->create([
        'line' => 'Av. Principal, local 3', 'city' => 'Valencia', 'state' => 'carabobo', 'reference' => 'Frente a la plaza',
    ]);

    $this->actingAs(customerUpdater())
        ->get("/customers/{$customer->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Edit')
            ->where('customer', [
                'id' => $customer->id,
                'type' => 'company',
                'name' => 'Textiles Aurora',
                'document_type' => 'rif_j',
                'document_number' => 'J123456784',
                'phone' => '0414-123-4567',
                'email' => 'ventas@aurora.example.test',
                'birthday_day' => 29,
                'birthday_month' => 2,
                'anniversary_day' => 5,
                'anniversary_month' => 11,
                'notes' => 'Prefiere entregas por la tarde.',
                'contact' => [
                    'name' => 'Luis Pérez',
                    'position' => 'Compras',
                    'phone' => '0424-987-6543',
                    'email' => 'luis@aurora.example.test',
                ],
                'address' => [
                    'line' => 'Av. Principal, local 3',
                    'city' => 'Valencia',
                    'state' => 'carabobo',
                    'reference' => 'Frente a la plaza',
                ],
            ])
            ->has('customerTypes', 2)
            ->has('documentTypes.natural', 3)
            ->has('documentTypes.company', 5)
            ->has('states', count(VenezuelanState::cases()))
        );
});

it('CLI-008 sends null for the optional values and the missing contact and address of a bare customer', function () {
    $customer = Customer::factory()->create([
        'name' => 'Cliente sin extras',
        'phone' => '+584141234567',
        'email' => null,
        'notes' => null,
    ]);

    $this->actingAs(customerUpdater())
        ->get("/customers/{$customer->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/Edit')
            ->where('customer.type', 'natural')
            ->where('customer.document_type', null)
            ->where('customer.document_number', null)
            ->where('customer.email', null)
            ->where('customer.birthday_day', null)
            ->where('customer.anniversary_month', null)
            ->where('customer.notes', null)
            ->where('customer.contact', null)
            ->where('customer.address', null)
        );
});

it('DEC-CLI-28 lets an inactive customer open the edit form', function () {
    $customer = Customer::factory()->inactive()->create();

    $this->actingAs(customerUpdater())
        ->get("/customers/{$customer->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/Edit')->where('customer.id', $customer->id));
});

it('CLI-008 answers 404 for the edit form of a customer that does not exist', function () {
    $this->actingAs(customerUpdater())->get('/customers/999999/edit')->assertNotFound();
});

it('CLI-008 deletes the stored address when the update omits the address key (full-replace semantics)', function () {
    $customer = Customer::factory()->create();
    CustomerAddress::factory()->for($customer)->create();
    $payload = updateCustomerPayload($customer);
    unset($payload['address']);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", $payload)
        ->assertRedirect("/customers/{$customer->id}");

    expect(CustomerAddress::query()->where('customer_id', $customer->id)->exists())->toBeFalse();
});

it('CLI-008 keeps the stored address when the update sends it, so a normal edit never drops it', function () {
    $customer = Customer::factory()->create();
    $address = CustomerAddress::factory()->for($customer)->create(['city' => 'Valencia']);

    $this->actingAs(customerUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['name' => 'Nombre nuevo']))
        ->assertRedirect();

    expect($customer->fresh()->name)->toBe('Nombre nuevo')
        ->and($address->fresh()->city)->toBe('Valencia');
});
