<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;

it('E-10 deletes a company with its contact and address and audits the full copy', function () {
    $actor = userWithPermissions(PermissionName::CustomersDelete);
    $customer = Customer::factory()->company()->withDocument()->withContact()->withAddress()->create();
    $customer->load(['contact', 'address']);

    $this->actingAs($actor)
        ->delete("/customers/{$customer->id}")
        ->assertRedirect('/customers')
        ->assertInertiaFlash('message', 'El cliente fue eliminado.');

    expect(Customer::query()->whereKey($customer->id)->exists())->toBeFalse()
        ->and(CustomerContact::query()->where('customer_id', $customer->id)->exists())->toBeFalse()
        ->and(CustomerAddress::query()->where('customer_id', $customer->id)->exists())->toBeFalse();

    $audit = AuditLog::query()->where('action', AuditAction::CustomerDeleted->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($customer->getMorphClass())
        ->and($audit->entity_id)->toBe($customer->id)
        ->and($audit->created_at)->not->toBeNull()
        ->and($audit->new_values)->toBeEmpty()
        ->and($audit->old_values)->toMatchArray([
            'type' => 'company',
            'name' => $customer->name,
            'document_type' => $customer->document_type->value,
            'document_number' => $customer->document_number,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'status' => 'active',
        ])
        ->and($audit->old_values['contact'])->toEqual($customer->contact->only(['name', 'position', 'phone', 'email']))
        ->and($audit->old_values['address'])->toEqual([
            'line' => $customer->address->line,
            'city' => $customer->address->city,
            'state' => $customer->address->state->value,
            'reference' => $customer->address->reference,
        ]);
});

it('E-10 deletes a bare customer without contact or address', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersDelete))
        ->delete("/customers/{$customer->id}")
        ->assertRedirect('/customers');

    $audit = AuditLog::query()->where('action', AuditAction::CustomerDeleted->value)->sole();

    expect(Customer::query()->whereKey($customer->id)->exists())->toBeFalse()
        ->and($audit->old_values['contact'])->toBeNull()
        ->and($audit->old_values['address'])->toBeNull();
});

it('E-18 forbids deleting without customers.delete even with update and deactivate', function () {
    $customer = Customer::factory()->create();
    $actor = userWithPermissions(
        PermissionName::CustomersView,
        PermissionName::CustomersUpdate,
        PermissionName::CustomersDeactivate,
    );

    $this->actingAs($actor)->delete("/customers/{$customer->id}")->assertForbidden();

    expect(Customer::query()->whereKey($customer->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::CustomerDeleted->value)->count())->toBe(0);
});

it('E-18 redirects a guest to the login page', function () {
    $customer = Customer::factory()->create();

    $this->delete("/customers/{$customer->id}")->assertRedirect('/login');

    expect(Customer::query()->whereKey($customer->id)->exists())->toBeTrue();
});

it('E-19 bloqueo por historial')->todo('se prueba en 004 y 006');
