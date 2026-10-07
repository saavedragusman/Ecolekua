<?php

use App\Actions\Customers\CreateCustomer;
use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Validation\ValidationException;

function customerCreator(PermissionName ...$extra): User
{
    return userWithPermissions(PermissionName::CustomersCreate, ...$extra);
}

it('E-01 creates an active customer with the minimum data, the actor as creator and one created audit row', function () {
    $actor = customerCreator();

    $response = $this->actingAs($actor)->postJson('/customers', createCustomerPayload());

    $customer = Customer::query()->sole();

    $response->assertRedirect("/customers/{$customer->id}");

    expect($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->type)->toBe(CustomerType::Natural)
        ->and($customer->name)->toBe('Cliente de prueba')
        ->and($customer->created_by)->toBe($actor->id)
        ->and($customer->contact)->toBeNull()
        ->and($customer->address)->toBeNull();

    $audit = AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($customer->getMorphClass())
        ->and($audit->entity_id)->toBe($customer->id)
        ->and($audit->old_values)->toBe([])
        ->and($audit->new_values)->toEqual([
            'type' => 'natural',
            'name' => 'Cliente de prueba',
            'document_type' => null,
            'document_number' => null,
            'phone' => '+584141234567',
            'email' => null,
            'birthday_day' => null,
            'birthday_month' => null,
            'anniversary_day' => null,
            'anniversary_month' => null,
            'notes' => null,
            'status' => 'active',
            'advisor_id' => null,
            'advisor_name' => null,
            'contact' => null,
            'address' => null,
        ]);
});

it('E-01 creates a company with document, contact person and address, and audits the nested values', function () {
    $actor = customerCreator();

    $this->actingAs($actor)->postJson('/customers', createCustomerPayload([
        'type' => 'company',
        'document_type' => 'rif_j',
        'document_number' => 'J-12345678-4',
        'email' => 'compras@empresa-prueba.test',
        'anniversary_day' => 29,
        'anniversary_month' => 2,
        'contact' => ['name' => 'Persona de contacto', 'position' => 'Compras', 'phone' => '0212-555.12.34', 'email' => 'contacto@empresa-prueba.test'],
        'address' => ['line' => 'Calle 1, local 2', 'city' => 'Caracas', 'state' => 'distrito_capital', 'reference' => 'Frente a la plaza'],
    ]))->assertRedirect();

    $customer = Customer::query()->sole();

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->document_type)->toBe(DocumentType::RifJ)
        ->and($customer->document_number)->toBe('J123456784')
        ->and($customer->anniversary_day)->toBe(29)
        ->and($customer->anniversary_month)->toBe(2)
        ->and($customer->contact?->phone)->toBe('+582125551234')
        ->and($customer->address?->state->value)->toBe('distrito_capital')
        ->and(CustomerContact::query()->count())->toBe(1)
        ->and(CustomerAddress::query()->count())->toBe(1);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->sole();

    expect($audit->new_values['document_number'])->toBe('J123456784')
        ->and($audit->new_values['contact'])->toEqual([
            'name' => 'Persona de contacto',
            'position' => 'Compras',
            'phone' => '+582125551234',
            'email' => 'contacto@empresa-prueba.test',
        ])
        ->and($audit->new_values['address'])->toEqual([
            'line' => 'Calle 1, local 2',
            'city' => 'Caracas',
            'state' => 'distrito_capital',
            'reference' => 'Frente a la plaza',
        ]);
});

it('E-02 rejects a missing mandatory field on that field and creates nothing', function (string $field) {
    $payload = createCustomerPayload();
    unset($payload[$field]);

    $this->actingAs(customerCreator())
        ->postJson('/customers', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Customer::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->count())->toBe(0);
})->with(['type', 'name', 'phone']);

it('E-02 rejects an empty name and an empty phone', function (string $field) {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload([$field => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Customer::query()->count())->toBe(0);
})->with(['name', 'phone']);

it('E-03 denies a user without customers.create, creates nothing and audits authorization.denied', function () {
    $actor = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersUpdate);

    $this->actingAs($actor)->postJson('/customers', createCustomerPayload())->assertForbidden();

    expect(Customer::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toBe('customers.store')
        ->and($audit->context['method'])->toBe('POST')
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->count())->toBe(0);
});

it('E-03 denies a request from a user with no permission at all, whatever the payload', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/customers', createCustomerPayload(['type' => 'invalid']))
        ->assertForbidden();

    expect(Customer::query()->count())->toBe(0);
});

it('E-06 stores any accepted phone form as E.164', function (string $typed) {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload(['phone' => $typed]))
        ->assertRedirect();

    expect(Customer::query()->sole()->phone)->toBe('+584141234567');
})->with([
    'local with separators' => '0414-123.45.67',
    'international plus' => '+58 414 123 45 67',
    'international 00' => '0058 4141234567',
    'no trunk' => '4141234567',
]);

it('E-13 rejects a document already registered by an active or inactive customer and creates nothing', function (CustomerStatus $status) {
    $existing = Customer::factory()->company()->withDocument(DocumentType::RifJ)->create(['status' => $status]);

    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload([
            'type' => 'company',
            'document_type' => 'rif_j',
            'document_number' => (string) $existing->document_number,
            'phone' => '0416-765.43.21',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document_number']);

    expect(Customer::query()->count())->toBe(1);
})->with([CustomerStatus::Active, CustomerStatus::Inactive]);

it('E-13 accepts the same document number under a different document type', function () {
    Customer::factory()->create(['document_type' => DocumentType::CedulaV, 'document_number' => 'V12345678']);

    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload([
            'document_type' => 'cedula_e',
            'document_number' => 'E12345678',
            'phone' => '0416-765.43.21',
        ]))
        ->assertRedirect();

    expect(Customer::query()->count())->toBe(2);
});

it('E-13 turns a unique-index race on the document into the same validation error and writes nothing', function () {
    $actor = customerCreator();
    Customer::factory()->create(['document_type' => DocumentType::CedulaV, 'document_number' => 'V12345678']);

    // Calling the Action directly skips FormRequest validation, as a race that passed it would.
    $data = createCustomerPayload([
        'document_type' => 'cedula_v',
        'document_number' => 'V-12.345.678',
        'phone' => '0416-765.43.21',
    ]);

    try {
        app(CreateCustomer::class)->handle($data, $actor);
        $this->fail('The document race was not rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('document_number');
    }

    expect(Customer::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->count())->toBe(0);
});

it('E-25 makes a creator with customers.portfolio the advisor of the new customer', function () {
    $actor = customerCreator(PermissionName::CustomersPortfolio);

    $this->actingAs($actor)->postJson('/customers', createCustomerPayload())->assertRedirect();

    $customer = Customer::query()->sole();

    expect($customer->advisor_id)->toBe($actor->id);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->sole();

    expect($audit->new_values['advisor_id'])->toBe($actor->id)
        ->and($audit->new_values['advisor_name'])->toBe("{$actor->first_name} {$actor->last_name}")
        ->and(AuditLog::query()->where('action', AuditAction::CustomerAdvisorAssigned->value)->count())->toBe(0);
});

it('E-25 leaves the advisor empty when the creator has no customers.portfolio', function () {
    $this->actingAs(customerCreator())->postJson('/customers', createCustomerPayload())->assertRedirect();

    expect(Customer::query()->sole()->advisor_id)->toBeNull();
});

it('E-25 never assigns an ineligible creator as advisor', function () {
    $actor = customerCreator(PermissionName::CustomersPortfolio);
    $actor->forceFill(['is_active' => false])->save();

    $customer = app(CreateCustomer::class)->handle(createCustomerPayload(), $actor);

    expect($customer->advisor_id)->toBeNull();
});

it('E-34 rejects malformed documents on document_number and creates nothing', function (string $type, string $number, string $customerType) {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload([
            'type' => $customerType,
            'document_type' => $type,
            'document_number' => $number,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document_number']);

    expect(Customer::query()->count())->toBe(0);
})->with([
    'cédula with 5 digits' => ['cedula_v', '12345', 'natural'],
    'cédula with 10 digits' => ['cedula_v', '1234567890', 'natural'],
    'RIF with a wrong check digit' => ['rif_j', 'J-12345678-5', 'company'],
    'passport with 4 characters' => ['passport', 'AB12', 'natural'],
    'passport with 21 characters' => ['passport', 'AB123456789012345678C', 'natural'],
]);

it('E-34 accepts the boundary documents', function (string $type, string $number, string $customerType) {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload([
            'type' => $customerType,
            'document_type' => $type,
            'document_number' => $number,
        ]))
        ->assertRedirect();

    expect(Customer::query()->count())->toBe(1);
})->with([
    'cédula with 6 digits' => ['cedula_v', '123456', 'natural'],
    'cédula with 9 digits' => ['cedula_e', '123456789', 'natural'],
    'passport with 5 characters' => ['passport', 'AB123', 'natural'],
    'passport with 20 characters' => ['passport', 'AB1234567890123456CD', 'natural'],
    'RIF with the right check digit' => ['rif_j', 'J-12345678-4', 'company'],
]);

it('E-35 rejects a document type that does not fit the customer type on document_type', function (string $customerType, string $type, string $number) {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload([
            'type' => $customerType,
            'document_type' => $type,
            'document_number' => $number,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document_type']);

    expect(Customer::query()->count())->toBe(0);
})->with([
    'natural with RIF J' => ['natural', 'rif_j', 'J-12345678-4'],
    'company with cédula V' => ['company', 'cedula_v', 'V-12345678'],
]);

it('E-36 stores the canonical document and rejects the same document typed differently', function () {
    $actor = customerCreator();

    $this->actingAs($actor)->postJson('/customers', createCustomerPayload([
        'type' => 'company',
        'document_type' => 'rif_j',
        'document_number' => 'J-12345678-4',
    ]))->assertRedirect();

    expect(Customer::query()->sole()->document_number)->toBe('J123456784');

    $this->actingAs($actor)->postJson('/customers', createCustomerPayload([
        'type' => 'company',
        'document_type' => 'rif_j',
        'document_number' => 'j.12345678 4',
        'phone' => '0416-765.43.21',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['document_number']);

    expect(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->sole()->document_number)->toBe('J123456784');
});

it('E-37 rejects landline and foreign customer phones on phone', function (string $phone) {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload(['phone' => $phone]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);

    expect(Customer::query()->count())->toBe(0);
})->with([
    'landline' => '0212-555.12.34',
    'foreign +1' => '+1 305 555 0100',
    'foreign 0044' => '0044 20 7946 0958',
]);

it('E-38 accepts a landline for the contact person and rejects a foreign one on contact.phone', function () {
    $actor = customerCreator();

    $this->actingAs($actor)->postJson('/customers', createCustomerPayload([
        'type' => 'company',
        'contact' => ['name' => 'Persona de contacto', 'phone' => '0212-555.12.34'],
    ]))->assertRedirect();

    expect(Customer::query()->sole()->contact?->phone)->toBe('+582125551234');

    $this->actingAs($actor)->postJson('/customers', createCustomerPayload([
        'type' => 'company',
        'phone' => '0416-765.43.21',
        'contact' => ['name' => 'Otra persona', 'phone' => '+1 305 555 0100'],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['contact.phone']);

    expect(Customer::query()->count())->toBe(1);
});

it('CLI-007 saves the notes and enforces the 5000 character limit', function () {
    $actor = customerCreator();
    $notes = str_repeat('a', 5000);

    $this->actingAs($actor)
        ->postJson('/customers', createCustomerPayload(['notes' => $notes]))
        ->assertRedirect();

    expect(Customer::query()->sole()->notes)->toBe($notes);

    $this->actingAs($actor)
        ->postJson('/customers', createCustomerPayload(['notes' => str_repeat('a', 5001), 'phone' => '0416-765.43.21']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notes']);

    expect(Customer::query()->count())->toBe(1);
});

it('CLI-007 accepts a customer without notes', function () {
    $this->actingAs(customerCreator())->postJson('/customers', createCustomerPayload())->assertRedirect();

    expect(Customer::query()->sole()->notes)->toBeNull();
});

it('CLI-005 and CLI-006 reject an empty JSON object for contact and address with the resolved Spanish message', function (string $field) {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload(['type' => 'company', $field => new stdClass]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    $message = $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload(['type' => 'company', $field => new stdClass]))
        ->json("errors.{$field}.0");

    $expected = __('validation.not_empty_array', ['attribute' => __("validation.attributes.{$field}")]);

    expect($message)->toBe($expected)
        ->and($message)->not->toBe('validation.not_empty_array')
        ->and($message)->toContain('no puede estar vacío')
        ->and(Customer::query()->count())->toBe(0);
})->with(['contact', 'address']);

it('CLI-005 and CLI-006 reject an empty list for contact and address too', function (string $field) {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload(['type' => 'company', $field => []]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Customer::query()->count())->toBe(0);
})->with(['contact', 'address']);

it('CLI-005 rejects a contact person for a natural customer on contact', function () {
    $this->actingAs(customerCreator())
        ->postJson('/customers', createCustomerPayload([
            'contact' => ['name' => 'Persona de contacto', 'phone' => '0212-555.12.34'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact']);

    expect(Customer::query()->count())->toBe(0);
});

it('CLI-001 ignores client-sent status, created_by and advisor_id', function () {
    $actor = customerCreator();
    $other = User::factory()->create();

    $this->actingAs($actor)->postJson('/customers', createCustomerPayload([
        'status' => 'inactive',
        'created_by' => $other->id,
        'advisor_id' => $other->id,
    ]))->assertRedirect();

    $customer = Customer::query()->sole();

    expect($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->created_by)->toBe($actor->id)
        ->and($customer->advisor_id)->toBeNull();
});

it('CLI-001 sends a failed browser submission back with errors and a valid one to the new customer', function () {
    $actor = customerCreator();

    $this->actingAs($actor)
        ->from('/customers/create')
        ->post('/customers', createCustomerPayload(['name' => '']))
        ->assertRedirect('/customers/create')
        ->assertSessionHasErrors(['name']);

    expect(Customer::query()->count())->toBe(0);

    $response = $this->actingAs($actor)->post('/customers', createCustomerPayload());

    $response->assertRedirect('/customers/'.Customer::query()->sole()->id);
});
