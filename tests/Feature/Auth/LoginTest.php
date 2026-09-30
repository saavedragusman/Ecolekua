<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function attemptLogin(string $email, string $password = 'wrong-password'): TestResponse
{
    return test()->post('/login', ['email' => $email, 'password' => $password]);
}

it('E-01 authenticates an active user with correct credentials and audits the login', function () {
    $user = User::factory()->create(['email' => 'ana@ecolekua.com']);

    attemptLogin('ana@ecolekua.com', 'password')->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect(AuditLog::query()->where('action', AuditAction::LoginSucceeded->value)->where('actor_id', $user->id)->count())->toBe(1);
});

it('E-02 rejects a wrong password with the generic message and audits the failure', function () {
    User::factory()->create(['email' => 'ana@ecolekua.com']);

    attemptLogin('ana@ecolekua.com')->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
    $row = AuditLog::query()->where('action', AuditAction::LoginFailed->value)->sole();
    expect($row->actor_email)->toBe('ana@ecolekua.com');
});

it('E-03 rejects an unknown email with the same generic message', function () {
    attemptLogin('nadie@ecolekua.com', 'password')->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('E-04 rejects an inactive user with the same generic message', function () {
    User::factory()->inactive()->create(['email' => 'off@ecolekua.com']);

    attemptLogin('off@ecolekua.com', 'password')->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('E-05 identifies the user when the email has spaces and mixed case', function () {
    $user = User::factory()->create(['email' => 'ana@ecolekua.com']);

    attemptLogin(' Ana@Ecolekua.com ', 'password')->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

it('E-06 locks after 5 failures, rejects the 6th even with the right password, audits the lockout and unlocks after 15 minutes', function () {
    $user = User::factory()->create(['email' => 'ana@ecolekua.com']);

    foreach (range(1, 5) as $i) {
        // Including the 5th, which imposes the lock: it still answers with the generic message.
        attemptLogin('ana@ecolekua.com')->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    expect(AuditLog::query()->where('action', AuditAction::Lockout->value)->count())->toBe(1);

    attemptLogin('ana@ecolekua.com', 'password')
        ->assertSessionHasErrors(['email' => trans_choice('auth.locked', 15, ['minutes' => 15])]);
    $this->assertGuest();

    $this->travel(16)->minutes();

    attemptLogin('ana@ecolekua.com', 'password')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

it('E-06 audits an attempt made while locked with reason locked', function () {
    User::factory()->create(['email' => 'ana@ecolekua.com']);

    foreach (range(1, 6) as $i) {
        attemptLogin('ana@ecolekua.com');
    }

    $locked = AuditLog::query()->where('action', AuditAction::LoginFailed->value)->orderByDesc('id')->first();
    expect($locked->context['reason'])->toBe('locked');
});
