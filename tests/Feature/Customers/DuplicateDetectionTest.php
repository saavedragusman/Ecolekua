<?php

use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Enums\DocumentType;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Support\Customers\DocumentNumber;

/**
 * Phone stored by the factory below and the several ways of typing it (design Decision 4).
 */
const DUPLICATE_STORED_PHONE = '+584141234567';
const DUPLICATE_TYPED_PHONE = '0414-123.45.67';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function duplicatePhonePayload(array $overrides = []): array
{
    return createCustomerPayload(['name' => 'Cliente nuevo', 'phone' => DUPLICATE_TYPED_PHONE, ...$overrides]);
}

it('E-14 (create) warns about an existing phone without writing anything and lists the match', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    $existing = Customer::factory()->withDocument()->create(['name' => 'Cliente existente', 'phone' => DUPLICATE_STORED_PHONE]);

    $this->actingAs($actor)
        ->postJson('/customers', duplicatePhonePayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirm_duplicate_phone'])
        ->assertJsonPath('errors.confirm_duplicate_phone.0', 'Este teléfono ya está registrado en otro cliente. Revise las coincidencias y confirme si desea guardar de todos modos.')
        ->assertJsonPath('duplicate_phone_matches.0.id', $existing->id)
        ->assertJsonPath('duplicate_phone_matches.0.name', 'Cliente existente')
        ->assertJsonPath('duplicate_phone_matches.0.status', 'active')
        ->assertJsonPath('duplicate_phone_matches.0.status_label', 'Activo')
        ->assertJsonCount(1, 'duplicate_phone_matches');

    expect(Customer::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->count())->toBe(0);
});

it('E-14 (create) shows the document of a match as people read it, or null without document', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    Customer::factory()->company()->create([
        'phone' => DUPLICATE_STORED_PHONE,
        'document_type' => DocumentType::RifJ,
        'document_number' => 'J123456784',
        'name' => 'Con documento',
    ]);
    Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE, 'name' => 'Sin documento']);

    $response = $this->actingAs($actor)->postJson('/customers', duplicatePhonePayload());

    $response->assertUnprocessable()
        ->assertJsonPath('duplicate_phone_matches.0.name', 'Con documento')
        ->assertJsonPath('duplicate_phone_matches.0.document', 'J-12345678-4')
        ->assertJsonPath('duplicate_phone_matches.1.name', 'Sin documento')
        ->assertJsonPath('duplicate_phone_matches.1.document', null);
});

it('E-14 (create) also matches inactive customers and orders the matches by name', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    $zeta = Customer::factory()->inactive()->create(['phone' => DUPLICATE_STORED_PHONE, 'name' => 'Zeta']);
    $alfa = Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE, 'name' => 'Alfa']);
    Customer::factory()->create(['phone' => '+584169999999', 'name' => 'Otro teléfono']);

    $this->actingAs($actor)
        ->postJson('/customers', duplicatePhonePayload())
        ->assertUnprocessable()
        ->assertJsonCount(2, 'duplicate_phone_matches')
        ->assertJsonPath('duplicate_phone_matches.0.id', $alfa->id)
        ->assertJsonPath('duplicate_phone_matches.1.id', $zeta->id)
        ->assertJsonPath('duplicate_phone_matches.1.status', CustomerStatus::Inactive->value)
        ->assertJsonPath('duplicate_phone_matches.1.status_label', 'Inactivo');

    expect(Customer::query()->count())->toBe(3);
});

it('E-14 (create) saves the same payload once confirm_duplicate_phone is true', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE]);

    $this->actingAs($actor)
        ->postJson('/customers', duplicatePhonePayload(['confirm_duplicate_phone' => true]))
        ->assertRedirect();

    $created = Customer::query()->where('name', 'Cliente nuevo')->sole();

    expect($created->phone)->toBe(DUPLICATE_STORED_PHONE)
        ->and(Customer::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->sole()->entity_id)->toBe($created->id);
});

it('E-14 (create) keeps warning when the confirmation is explicitly false', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE]);

    $this->actingAs($actor)
        ->postJson('/customers', duplicatePhonePayload(['confirm_duplicate_phone' => false]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirm_duplicate_phone']);

    expect(Customer::query()->count())->toBe(1);
});

it('E-14 (create) writes no contact person or address when it warns', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE]);

    $this->actingAs($actor)
        ->postJson('/customers', duplicatePhonePayload([
            'type' => 'company',
            'contact' => ['name' => 'Persona de contacto', 'phone' => '0212-555.12.34'],
            'address' => ['line' => 'Calle 1', 'city' => 'Caracas', 'state' => 'miranda'],
        ]))
        ->assertUnprocessable();

    expect(Customer::query()->count())->toBe(1)
        ->and(CustomerContact::query()->count())->toBe(0)
        ->and(CustomerAddress::query()->count())->toBe(0);
});

it('E-14 (create) lets validation errors win over the warning', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE]);

    $this->actingAs($actor)
        ->postJson('/customers', duplicatePhonePayload(['name' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonMissingValidationErrors(['confirm_duplicate_phone'])
        ->assertJsonMissingPath('duplicate_phone_matches');
});

it('E-14 (create) flashes the matches and goes back with the error on an Inertia submission', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    $existing = Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE, 'name' => 'Cliente existente']);

    $this->actingAs($actor)
        ->from('/customers/create')
        ->post('/customers', duplicatePhonePayload())
        ->assertRedirect('/customers/create')
        ->assertSessionHasErrors(['confirm_duplicate_phone'])
        ->assertInertiaFlash('duplicatePhoneMatches', [[
            'id' => $existing->id,
            'name' => 'Cliente existente',
            'document' => null,
            'status' => 'active',
            'status_label' => 'Activo',
        ]]);

    expect(Customer::query()->count())->toBe(1);
});

it('DEC-CLI-27 does not warn when only a contact person has the submitted phone', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    $company = Customer::factory()->company()->create(['phone' => '+584169999999']);
    CustomerContact::factory()->for($company)->create(['phone' => DUPLICATE_STORED_PHONE]);

    $this->actingAs($actor)->postJson('/customers', duplicatePhonePayload())->assertRedirect();

    expect(Customer::query()->where('name', 'Cliente nuevo')->exists())->toBeTrue();
});

it('DEC-CLI-27 does not compare the contact phone of the new customer against main phones', function () {
    $actor = userWithPermissions(PermissionName::CustomersCreate);
    Customer::factory()->create(['phone' => '+582125551234']);

    $this->actingAs($actor)->postJson('/customers', duplicatePhonePayload([
        'type' => 'company',
        'phone' => '0416-765.43.21',
        'contact' => ['name' => 'Persona de contacto', 'phone' => '0212-555.12.34'],
    ]))->assertRedirect();

    expect(Customer::query()->where('name', 'Cliente nuevo')->sole()->contact?->phone)->toBe('+582125551234');
});

it('E-14 (edit) warns when the phone changes to one another customer already has, and writes nothing', function () {
    $actor = userWithPermissions(PermissionName::CustomersUpdate);
    $existing = Customer::factory()->withDocument()->create(['name' => 'Cliente existente', 'phone' => DUPLICATE_STORED_PHONE]);
    $customer = Customer::factory()->create(['phone' => '+584169999999', 'name' => 'Sin cambios']);

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['phone' => DUPLICATE_TYPED_PHONE, 'name' => 'Cambio pendiente']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirm_duplicate_phone'])
        ->assertJsonPath('errors.confirm_duplicate_phone.0', 'Este teléfono ya está registrado en otro cliente. Revise las coincidencias y confirme si desea guardar de todos modos.')
        ->assertJsonCount(1, 'duplicate_phone_matches')
        ->assertJsonPath('duplicate_phone_matches.0.id', $existing->id)
        ->assertJsonPath('duplicate_phone_matches.0.document', DocumentNumber::display($existing->document_type, $existing->document_number));

    expect($customer->fresh()->phone)->toBe('+584169999999')
        ->and($customer->fresh()->name)->toBe('Sin cambios')
        ->and(AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->count())->toBe(0);
});

it('E-14 (edit) saves the same change once confirm_duplicate_phone is true', function () {
    $actor = userWithPermissions(PermissionName::CustomersUpdate);
    Customer::factory()->inactive()->create(['phone' => DUPLICATE_STORED_PHONE]);
    $customer = Customer::factory()->create(['phone' => '+584169999999']);

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['phone' => DUPLICATE_TYPED_PHONE, 'confirm_duplicate_phone' => true]))
        ->assertRedirect();

    expect($customer->fresh()->phone)->toBe(DUPLICATE_STORED_PHONE)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole()->new_values)->toEqual(['phone' => DUPLICATE_STORED_PHONE]);
});

it('E-14 (edit) flashes the matches and goes back with the error on an Inertia submission', function () {
    $actor = userWithPermissions(PermissionName::CustomersUpdate);
    $existing = Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE, 'name' => 'Cliente existente']);
    $customer = Customer::factory()->create(['phone' => '+584169999999']);

    $this->actingAs($actor)
        ->from("/customers/{$customer->id}/edit")
        ->put("/customers/{$customer->id}", updateCustomerPayload($customer, ['phone' => DUPLICATE_TYPED_PHONE]))
        ->assertRedirect("/customers/{$customer->id}/edit")
        ->assertSessionHasErrors(['confirm_duplicate_phone'])
        ->assertInertiaFlash('duplicatePhoneMatches', [[
            'id' => $existing->id,
            'name' => 'Cliente existente',
            'document' => null,
            'status' => 'active',
            'status_label' => 'Activo',
        ]]);

    expect($customer->fresh()->phone)->toBe('+584169999999');
});

it('DEC-CLI-27 (edit) saves other data without a warning when the phone is not changed, even if another customer shares it', function () {
    $actor = userWithPermissions(PermissionName::CustomersUpdate);
    Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE]);
    $customer = Customer::factory()->create(['phone' => DUPLICATE_STORED_PHONE, 'notes' => null]);

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['notes' => 'Nota nueva', 'phone' => DUPLICATE_TYPED_PHONE]))
        ->assertRedirect();

    expect($customer->fresh()->notes)->toBe('Nota nueva');
});

it('DEC-CLI-27 (edit) never matches the customer being edited nor contact-person phones', function () {
    $actor = userWithPermissions(PermissionName::CustomersUpdate);
    $company = Customer::factory()->company()->create(['phone' => '+584169999999']);
    CustomerContact::factory()->for($company)->create(['phone' => DUPLICATE_STORED_PHONE]);
    $customer = Customer::factory()->create(['phone' => '+584168888888']);

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['phone' => DUPLICATE_TYPED_PHONE]))
        ->assertRedirect();

    expect($customer->fresh()->phone)->toBe(DUPLICATE_STORED_PHONE);
});
