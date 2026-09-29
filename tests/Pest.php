<?php

use App\Enums\PermissionName;
use App\Models\Role;
use App\Models\User;
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
 * A user holding the protected Administrador role, which the seeder gives all 9 permissions.
 */
function administrator(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('is_protected', true)->firstOrFail());

    return $user;
}

/**
 * Performs a real `POST /login` and returns the raw (still encrypted) session cookie value,
 * so the database session mechanism is exercised instead of the guard's in-memory user.
 */
function loginWithRealSession(User $user, string $password = 'password'): string
{
    $response = test()->post('/login', ['email' => $user->email, 'password' => $password]);

    $cookie = $response->getCookie(config('session.cookie'), false);

    expect($cookie)->not->toBeNull('The login did not start a session.');

    return $cookie->getValue();
}

/**
 * Sends a request carrying only the given session cookie: the guard's cached user is
 * forgotten first, so the user is re-resolved from the persisted session.
 *
 * @param  array<string, mixed>  $data
 */
function requestWithSession(string $sessionCookie, string $method, string $uri, array $data = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()
        ->withUnencryptedCookie(config('session.cookie'), $sessionCookie)
        ->call($method, $uri, $data);
}
