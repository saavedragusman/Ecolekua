<?php

use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

function customerStatusAuditRows(): Collection
{
    return AuditLog::query()
        ->whereIn('action', [AuditAction::CustomerDeactivated->value, AuditAction::CustomerActivated->value])
        ->get();
}

it('E-07 deactivates a customer keeping its data and relations and audits the status change', function () {
    $actor = userWithPermissions(PermissionName::CustomersDeactivate);
    $advisor = User::factory()->create();
    $customer = Customer::factory()->company()->withContact()->withAddress()->assignedTo($advisor)->create();

    $this->actingAs($actor)
        ->post("/customers/{$customer->id}/deactivate")
        ->assertRedirect("/customers/{$customer->id}");

    $fresh = $customer->fresh();

    expect($fresh->status)->toBe(CustomerStatus::Inactive)
        ->and($fresh->name)->toBe($customer->name)
        ->and($fresh->phone)->toBe($customer->phone)
        ->and($fresh->advisor_id)->toBe($advisor->id)
        ->and(CustomerContact::query()->where('customer_id', $customer->id)->count())->toBe(1)
        ->and(CustomerAddress::query()->where('customer_id', $customer->id)->count())->toBe(1);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerDeactivated->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($customer->getMorphClass())
        ->and($audit->entity_id)->toBe($customer->id)
        ->and($audit->old_values)->toEqual(['status' => 'active'])
        ->and($audit->new_values)->toEqual(['status' => 'inactive']);
});

it('E-08 reactivates an inactive customer and audits the status change', function () {
    $actor = userWithPermissions(PermissionName::CustomersDeactivate);
    $customer = Customer::factory()->inactive()->create();

    $this->actingAs($actor)
        ->post("/customers/{$customer->id}/activate")
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->status)->toBe(CustomerStatus::Active);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerActivated->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($customer->id)
        ->and($audit->old_values)->toEqual(['status' => 'inactive'])
        ->and($audit->new_values)->toEqual(['status' => 'active']);
});

it('E-09 forbids deactivating without customers.deactivate even with customers.update', function () {
    $customer = Customer::factory()->create();
    $actor = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersUpdate);

    $this->actingAs($actor)->post("/customers/{$customer->id}/deactivate")->assertForbidden();

    expect($customer->fresh()->status)->toBe(CustomerStatus::Active)
        ->and(customerStatusAuditRows())->toHaveCount(0);
});

it('E-09 forbids activating without customers.deactivate even with customers.update', function () {
    $customer = Customer::factory()->inactive()->create();
    $actor = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersUpdate);

    $this->actingAs($actor)->post("/customers/{$customer->id}/activate")->assertForbidden();

    expect($customer->fresh()->status)->toBe(CustomerStatus::Inactive)
        ->and(customerStatusAuditRows())->toHaveCount(0);
});

it('E-09 redirects a guest to the login page', function () {
    $customer = Customer::factory()->create();

    $this->post("/customers/{$customer->id}/deactivate")->assertRedirect('/login');
});

it('E-40 leaves an already inactive customer untouched when it is deactivated again', function () {
    $customer = Customer::factory()->inactive()->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersDeactivate))
        ->post("/customers/{$customer->id}/deactivate")
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->status)->toBe(CustomerStatus::Inactive)
        ->and(customerStatusAuditRows())->toHaveCount(0);
});

it('E-40 leaves an already active customer untouched when it is activated again', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersDeactivate))
        ->post("/customers/{$customer->id}/activate")
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->status)->toBe(CustomerStatus::Active)
        ->and(customerStatusAuditRows())->toHaveCount(0);
});

it('DEC-CLI-28 keeps an inactive customer editable', function () {
    $customer = Customer::factory()->inactive()->create(['phone' => '+584141234567']);

    $this->actingAs(userWithPermissions(PermissionName::CustomersUpdate))
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['name' => 'Nombre corregido']))
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->name)->toBe('Nombre corregido')
        ->and($customer->fresh()->status)->toBe(CustomerStatus::Inactive);
});
