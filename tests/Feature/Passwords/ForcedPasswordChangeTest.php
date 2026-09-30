<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Route::middleware('web')->get('/_test/protected', fn () => 'ok');
});

it('E-14 rejects any route outside the allowlist while a forced change is pending', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->get('/')->assertRedirect(route('password.edit'));
    $this->actingAs($user)->get('/_test/protected')->assertRedirect(route('password.edit'));
});

it('E-14 explains the redirect with a native Inertia flash message', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->get('/')
        ->assertRedirect(route('password.edit'))
        ->assertInertiaFlash('type', 'error')
        ->assertInertiaFlash('message');
});

it('E-14 allows the user to open the change form and to log out', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)->get('/password')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/ChangePassword'));

    $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
});

it('E-14 sends a user with a pending change to the change form right after login', function () {
    User::factory()->mustChangePassword()->create(['email' => 'nuevo@ecolekua.com']);

    $this->post('/login', ['email' => 'nuevo@ecolekua.com', 'password' => 'password'])
        ->assertRedirect(route('password.edit'));
});

it('E-14 does not restrict a user without a pending change', function () {
    $this->actingAs(User::factory()->create())->get('/_test/protected')->assertOk();
});
