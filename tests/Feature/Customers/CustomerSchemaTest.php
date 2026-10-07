<?php

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\VenezuelanState;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Inserts a customer row straight into the table, so the schema is tested without the models.
 *
 * @param  array<string, mixed>  $overrides
 */
function schemaCustomerRow(User $creator, array $overrides = []): int
{
    return DB::table('customers')->insertGetId(array_merge([
        'type' => 'natural',
        'name' => 'Cliente de prueba',
        'phone' => '+584141234567',
        'status' => 'active',
        'created_by' => $creator->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('CLI-012 enforces a unique document type and number pair in the database', function () {
    $creator = User::factory()->create();
    schemaCustomerRow($creator, ['document_type' => 'rif_j', 'document_number' => 'J123456784']);

    expect(fn () => schemaCustomerRow($creator, ['document_type' => 'rif_j', 'document_number' => 'J123456784']))
        ->toThrow(UniqueConstraintViolationException::class);

    // The same number under another type is a different document (cédula V is not RIF V).
    $id = schemaCustomerRow($creator, ['document_type' => 'rif_g', 'document_number' => 'J123456784']);

    expect($id)->toBeInt()->toBeGreaterThan(0);
});

it('CLI-012 allows many customers without a document', function () {
    $creator = User::factory()->create();

    schemaCustomerRow($creator);
    schemaCustomerRow($creator);
    schemaCustomerRow($creator);

    expect(DB::table('customers')->whereNull('document_type')->whereNull('document_number')->count())->toBe(3);
});

it('CLI-005 and CLI-006 allow one contact and one address per customer', function () {
    $creator = User::factory()->create();
    $customerId = schemaCustomerRow($creator, ['type' => 'company']);

    DB::table('customer_contacts')->insert([
        'customer_id' => $customerId, 'name' => 'Contacto', 'phone' => '+584141234567',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_addresses')->insert([
        'customer_id' => $customerId, 'line' => 'Calle 1', 'city' => 'Ciudad', 'state' => 'zulia',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('customer_contacts')->insert([
        'customer_id' => $customerId, 'name' => 'Otro', 'phone' => '+584141234568',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('customer_addresses')->insert([
            'customer_id' => $customerId, 'line' => 'Calle 2', 'city' => 'Ciudad', 'state' => 'lara',
            'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
});

it('CLI-010 cascades the deletion of a customer to its contact and address', function () {
    $creator = User::factory()->create();
    $customerId = schemaCustomerRow($creator, ['type' => 'company']);
    $otherId = schemaCustomerRow($creator, ['type' => 'company']);

    foreach ([$customerId, $otherId] as $id) {
        DB::table('customer_contacts')->insert([
            'customer_id' => $id, 'name' => 'Contacto', 'phone' => '+584141234567',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customer_addresses')->insert([
            'customer_id' => $id, 'line' => 'Calle 1', 'city' => 'Ciudad', 'state' => 'zulia',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    DB::table('customers')->where('id', $customerId)->delete();

    expect(DB::table('customer_contacts')->where('customer_id', $customerId)->count())->toBe(0)
        ->and(DB::table('customer_addresses')->where('customer_id', $customerId)->count())->toBe(0)
        ->and(DB::table('customer_contacts')->where('customer_id', $otherId)->count())->toBe(1)
        ->and(DB::table('customer_addresses')->where('customer_id', $otherId)->count())->toBe(1);
});

it('CLI-014 restricts deleting the advisor or the creator of a customer', function () {
    $creator = User::factory()->create();
    $advisor = User::factory()->create();
    schemaCustomerRow($creator, ['advisor_id' => $advisor->id]);

    expect(fn () => DB::table('users')->where('id', $advisor->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('users')->where('id', $creator->id)->delete())->toThrow(QueryException::class)
        ->and(User::query()->whereKey([$advisor->id, $creator->id])->count())->toBe(2);
});

it('CLI-001 casts the customer columns to the enums and loads its relations', function () {
    $creator = User::factory()->create();
    $advisor = User::factory()->create();
    $customerId = schemaCustomerRow($creator, [
        'type' => 'company',
        'document_type' => 'rif_j',
        'document_number' => 'J123456784',
        'advisor_id' => $advisor->id,
    ]);
    DB::table('customer_contacts')->insert([
        'customer_id' => $customerId, 'name' => 'Contacto', 'phone' => '+584141234567',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_addresses')->insert([
        'customer_id' => $customerId, 'line' => 'Calle 1', 'city' => 'Ciudad', 'state' => 'distrito_capital',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $customer = Customer::query()->findOrFail($customerId);

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->document_type)->toBe(DocumentType::RifJ)
        ->and($customer->advisor?->is($advisor))->toBeTrue()
        ->and($customer->creator->is($creator))->toBeTrue()
        ->and($customer->contact)->toBeInstanceOf(CustomerContact::class)
        ->and($customer->contact?->name)->toBe('Contacto')
        ->and($customer->address)->toBeInstanceOf(CustomerAddress::class)
        ->and($customer->address?->state)->toBe(VenezuelanState::DistritoCapital);
});

it('CLI-011 scopes customers by status and by assigned advisor', function () {
    $creator = User::factory()->create();
    $advisor = User::factory()->create();
    $activeMine = schemaCustomerRow($creator, ['advisor_id' => $advisor->id]);
    schemaCustomerRow($creator, ['advisor_id' => $advisor->id, 'status' => 'inactive']);
    schemaCustomerRow($creator);

    expect(Customer::query()->withStatus(CustomerStatus::Active)->count())->toBe(2)
        ->and(Customer::query()->withStatus(CustomerStatus::Inactive)->count())->toBe(1)
        ->and(Customer::query()->assignedTo($advisor)->count())->toBe(2)
        ->and(Customer::query()->withStatus(CustomerStatus::Active)->assignedTo($advisor)->pluck('id')->all())->toBe([$activeMine]);
});
