<?php

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\User;
use App\Support\Customers\DocumentNumber;

it('CLI-001 builds an active natural customer with a fictitious mobile phone by default', function () {
    $customer = Customer::factory()->create();

    expect($customer->type)->toBe(CustomerType::Natural)
        ->and($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->phone)->toMatch('/^\+58414\d{7}$/')
        ->and($customer->creator)->toBeInstanceOf(User::class)
        ->and($customer->advisor_id)->toBeNull()
        ->and($customer->contact)->toBeNull()
        ->and($customer->address)->toBeNull();
});

it('CLI-002 builds company and inactive customers through states', function () {
    expect(Customer::factory()->company()->create()->type)->toBe(CustomerType::Company)
        ->and(Customer::factory()->inactive()->create()->status)->toBe(CustomerStatus::Inactive);
});

it('CLI-005 and CLI-006 attach a contact person and an address through states', function () {
    $customer = Customer::factory()->company()->withContact()->withAddress()->create();

    expect($customer->contact)->toBeInstanceOf(CustomerContact::class)
        ->and($customer->contact?->phone)->toMatch('/^\+58\d{10}$/')
        ->and($customer->address)->toBeInstanceOf(CustomerAddress::class)
        ->and($customer->address?->state->value)->toBeString()
        ->and(Customer::query()->count())->toBe(1)
        ->and(CustomerContact::query()->count())->toBe(1)
        ->and(CustomerAddress::query()->count())->toBe(1);
});

it('CLI-003 gives natural customers a valid synthetic cédula and companies a valid synthetic RIF', function () {
    $natural = Customer::factory()->withDocument()->create();
    $company = Customer::factory()->company()->withDocument()->create();

    expect($natural->document_type)->toBe(DocumentType::CedulaV)
        ->and(DocumentNumber::isValid(DocumentType::CedulaV, (string) $natural->document_number))->toBeTrue()
        ->and($company->document_type)->toBe(DocumentType::RifJ)
        ->and(DocumentNumber::isValid(DocumentType::RifJ, (string) $company->document_number))->toBeTrue();
});

it('CLI-003 generates a valid canonical document for every document type', function (DocumentType $type) {
    $customer = Customer::factory()->withDocument($type)->create();

    expect($customer->document_type)->toBe($type)
        ->and(DocumentNumber::isValid($type, (string) $customer->document_number))->toBeTrue()
        ->and(DocumentNumber::normalize($type, (string) $customer->document_number))->toBe($customer->document_number);
})->with(DocumentType::cases());

it('CLI-012 generates different documents for different customers', function () {
    $numbers = Customer::factory()->count(12)->company()->withDocument()->create()->pluck('document_number');

    expect($numbers->unique())->toHaveCount(12);
});

it('CLI-014 assigns an advisor through a state', function () {
    $advisor = User::factory()->create();

    $customer = Customer::factory()->assignedTo($advisor)->create();

    expect($customer->advisor_id)->toBe($advisor->id);
});
