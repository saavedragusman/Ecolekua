<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // A protected route that renders no Inertia page, so these tests do not depend on Home.vue.
    Route::middleware('web')->get('/_test/protected', fn () => 'ok');
});

it('E-07 logout ends the session and the old session cookie is rejected afterwards', function () {
    $user = User::factory()->create();
    $cookie = loginWithRealSession($user);

    requestWithSession($cookie, 'GET', '/_test/protected')->assertOk();

    requestWithSession($cookie, 'POST', '/logout')->assertRedirect(route('login'));
    expect(AuditLog::query()->where('action', AuditAction::Logout->value)->where('actor_id', $user->id)->count())->toBe(1);

    requestWithSession($cookie, 'GET', '/_test/protected')->assertRedirect(route('login'));
});

it('E-07 a guest cannot log out', function () {
    $this->post('/logout')->assertRedirect(route('login'));
});
