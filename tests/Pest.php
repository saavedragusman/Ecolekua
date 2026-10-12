<?php

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the MySQL `testing` database, refreshed per test, with the
| roles and permission catalog seeded by TestCase (FoundationSeeder). Unit tests stay plain.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Shared helpers for the foundation tests (design.md, Testing Strategy).
|
*/

/**
 * A user whose only authority is one dedicated role holding exactly the given permissions.
 */
function userWithPermissions(PermissionName|string ...$permissions): User
{
    return User::factory()->withPermissions(...$permissions)->create();
}

/**
 * A user holding the protected Administrador role, which the seeder gives the Administrador
 * grants of the initial matrices of specs 001, 002 and 003 (every permission except `customers.portfolio`).
 */
function administrator(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('is_protected', true)->firstOrFail());

    return $user;
}

/**
 * Valid minimum payload for `POST /customers` (spec 002). Phones are fictitious; tests that create
 * several customers pass distinct phones so they never trip the duplicate-phone warning.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createCustomerPayload(array $overrides = []): array
{
    return [
        'type' => 'natural',
        'name' => 'Cliente de prueba',
        'phone' => '0414-123.45.67',
        ...$overrides,
    ];
}

/**
 * Complete state of a customer as the edit form sends it for `PUT /customers/{customer}`
 * (the contact person and the address are replaced, so the whole state travels).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function updateCustomerPayload(Customer $customer, array $overrides = []): array
{
    $customer->loadMissing(['contact', 'address']);

    return [
        'type' => $customer->type->value,
        'name' => $customer->name,
        'document_type' => $customer->document_type?->value,
        'document_number' => $customer->document_number,
        'phone' => $customer->phone,
        'email' => $customer->email,
        'birthday_day' => $customer->birthday_day,
        'birthday_month' => $customer->birthday_month,
        'anniversary_day' => $customer->anniversary_day,
        'anniversary_month' => $customer->anniversary_month,
        'notes' => $customer->notes,
        'contact' => $customer->contact?->only(['name', 'position', 'phone', 'email']),
        'address' => $customer->address === null ? null : [
            ...$customer->address->only(['line', 'city', 'reference']),
            'state' => $customer->address->state->value,
        ],
        ...$overrides,
    ];
}

/**
 * Performs a real `POST /login` and returns the decrypted session id from the response cookie,
 * so the database session mechanism is exercised instead of the guard's in-memory user.
 */
function loginWithRealSession(User $user, string $password = 'password'): string
{
    $response = test()->post('/login', ['email' => $user->email, 'password' => $password]);

    $cookie = $response->getCookie(config('session.cookie'));

    expect($cookie)->not->toBeNull('The login did not start a session.');

    return $cookie->getValue();
}

/**
 * Sends a request carrying only the given session id (encrypted like a browser cookie): the guard's cached user and the
 * in-memory session store are forgotten first, so the user is re-resolved from the persisted session.
 *
 * @param  array<string, mixed>  $data
 */
function requestWithSession(string $sessionCookie, string $method, string $uri, array $data = []): TestResponse
{
    // Drop the in-memory session store and guard state kept by the shared test application,
    // so the request is resolved only from the cookie and the persisted `sessions` row.
    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app()->forgetInstance('auth.driver');

    $name = config('session.cookie');

    // `call()` ignores the client's default cookies, so the encrypted cookie is passed explicitly.
    $cookie = encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$sessionCookie, false);

    return test()->call($method, $uri, $data, [$name => $cookie]);
}
