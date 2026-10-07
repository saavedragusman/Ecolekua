<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;

/**
 * Birthday and anniversary are stored as day and month only (CLI-017, DEC-CLI-31). The rules live
 * in CustomerRules and are exercised here through `PUT /customers/{customer}`.
 */
function datesUpdater(): User
{
    return userWithPermissions(PermissionName::CustomersUpdate);
}

it('E-20 saves a birthday as day and month only, for natural and company customers', function (bool $isCompany) {
    $customer = $isCompany ? Customer::factory()->company()->create() : Customer::factory()->create();

    $this->actingAs(datesUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['birthday_day' => 15, 'birthday_month' => 8]))
        ->assertRedirect();

    $customer = $customer->fresh();

    expect($customer->birthday_day)->toBe(15)
        ->and($customer->birthday_month)->toBe(8)
        ->and(array_key_exists('birthday', $customer->getAttributes()))->toBeFalse()
        ->and(array_key_exists('birthday_year', $customer->getAttributes()))->toBeFalse();

    $audit = AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->sole();

    expect($audit->new_values)->toEqual(['birthday_day' => 15, 'birthday_month' => 8]);
})->with(['natural' => false, 'company' => true]);

it('E-20 clears a birthday when both day and month are sent empty', function () {
    $customer = Customer::factory()->create(['birthday_day' => 15, 'birthday_month' => 8]);

    $this->actingAs(datesUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ['birthday_day' => null, 'birthday_month' => null]))
        ->assertRedirect();

    expect($customer->fresh()->birthday_day)->toBeNull()
        ->and($customer->fresh()->birthday_month)->toBeNull();
});

it('E-21 rejects an impossible date on its day field and keeps the stored one', function (string $field, int $day, int $month) {
    $customer = Customer::factory()->company()->create();
    $prefix = str_replace('_day', '', $field);

    $this->actingAs(datesUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, ["{$prefix}_day" => $day, "{$prefix}_month" => $month]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($customer->fresh()->{"{$prefix}_day"})->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::CustomerUpdated->value)->count())->toBe(0);
})->with([
    'birthday 31 April' => ['birthday_day', 31, 4],
    'birthday 30 February' => ['birthday_day', 30, 2],
    'anniversary 31 April' => ['anniversary_day', 31, 4],
    'anniversary 30 February' => ['anniversary_day', 30, 2],
]);

it('E-21 accepts 29 February for both dates (DEC-CLI-31)', function () {
    $customer = Customer::factory()->company()->create();

    $this->actingAs(datesUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [
            'birthday_day' => 29,
            'birthday_month' => 2,
            'anniversary_day' => 29,
            'anniversary_month' => 2,
        ]))
        ->assertRedirect();

    $customer = $customer->fresh();

    expect([$customer->birthday_day, $customer->birthday_month, $customer->anniversary_day, $customer->anniversary_month])->toBe([29, 2, 29, 2]);
});

it('E-22 saves the anniversary of a company and rejects it for a natural customer on anniversary_day', function () {
    $company = Customer::factory()->company()->create();
    $natural = Customer::factory()->create();

    $this->actingAs(datesUpdater())
        ->putJson("/customers/{$company->id}", updateCustomerPayload($company, ['anniversary_day' => 12, 'anniversary_month' => 10]))
        ->assertRedirect();

    expect($company->fresh()->anniversary_day)->toBe(12)
        ->and($company->fresh()->anniversary_month)->toBe(10);

    $this->actingAs(datesUpdater())
        ->putJson("/customers/{$natural->id}", updateCustomerPayload($natural, ['anniversary_day' => 12, 'anniversary_month' => 10]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['anniversary_day']);

    expect($natural->fresh()->anniversary_day)->toBeNull();
});

it('E-22 rejects half a date on the missing field', function (string $present, string $missing) {
    $customer = Customer::factory()->company()->create();

    $this->actingAs(datesUpdater())
        ->putJson("/customers/{$customer->id}", updateCustomerPayload($customer, [$present => 5, $missing => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$missing]);
})->with([
    'birthday day only' => ['birthday_day', 'birthday_month'],
    'birthday month only' => ['birthday_month', 'birthday_day'],
    'anniversary day only' => ['anniversary_day', 'anniversary_month'],
    'anniversary month only' => ['anniversary_month', 'anniversary_day'],
]);
