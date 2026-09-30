<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/protected', fn () => 'ok');
});

it('E-08 a session inactive for more than 120 minutes is rejected on the next protected operation', function () {
    $user = User::factory()->create();
    $cookie = loginWithRealSession($user);

    requestWithSession($cookie, 'GET', '/_test/protected')->assertOk();

    $this->travel(121)->minutes();

    requestWithSession($cookie, 'GET', '/_test/protected')->assertRedirect(route('login'));
});

it('E-08 a session inactive for less than 120 minutes is still valid', function () {
    $cookie = loginWithRealSession(User::factory()->create());

    $this->travel(119)->minutes();

    requestWithSession($cookie, 'GET', '/_test/protected')->assertOk();
});

it('E-09 an open session is rejected on the next request after the user is deactivated', function () {
    $user = User::factory()->create();
    $cookie = loginWithRealSession($user);

    requestWithSession($cookie, 'GET', '/_test/protected')->assertOk();

    // Direct row manipulation: the HTTP-driven path through DeactivateUser is verified in Phase 4.
    $user->forceFill(['is_active' => false])->save();

    requestWithSession($cookie, 'GET', '/_test/protected')->assertRedirect(route('login'));
});

it('E-09 deleting the user sessions rows invalidates the open session at once', function () {
    $user = User::factory()->create();
    $cookie = loginWithRealSession($user);

    DB::table('sessions')->where('user_id', $user->id)->delete();

    requestWithSession($cookie, 'GET', '/_test/protected')->assertRedirect(route('login'));
});
