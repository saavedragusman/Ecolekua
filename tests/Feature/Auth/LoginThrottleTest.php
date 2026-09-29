<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

function fail(string $email): TestResponse
{
    return test()->post('/login', ['email' => $email, 'password' => 'wrong-password']);
}

function lockedMessage(int $minutes): string
{
    return trans_choice('auth.locked', $minutes, ['minutes' => $minutes]);
}

beforeEach(fn () => User::factory()->create(['email' => 'ana@ecolekua.com']));

it('DEC-017 failures never expire by elapsed time', function () {
    foreach (range(1, 4) as $i) {
        fail('ana@ecolekua.com');
    }

    $this->travel(30)->days();

    fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => __('auth.failed')]);

    // The 5th failure locked the email, so the next attempt gets the lock message.
    fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => lockedMessage(15)]);
});

it('DEC-017 a successful login resets the counter', function () {
    foreach (range(1, 4) as $i) {
        fail('ana@ecolekua.com');
    }

    $this->post('/login', ['email' => 'ana@ecolekua.com', 'password' => 'password'])->assertRedirect();
    $this->post('/logout');

    foreach (range(1, 4) as $i) {
        fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    // 4 failures after the reset: not locked yet.
    expect(DB::table('login_throttles')->where('email', 'ana@ecolekua.com')->value('locked_until'))->toBeNull();
});

it('DEC-017 the counter returns to 0 when the 15-minute lock ends', function () {
    foreach (range(1, 5) as $i) {
        fail('ana@ecolekua.com');
    }

    $this->travel(15)->minutes();

    foreach (range(1, 4) as $i) {
        fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    expect(DB::table('login_throttles')->where('email', 'ana@ecolekua.com')->value('locked_until'))->toBeNull();

    // The 5th failure of the new cycle locks again.
    fail('ana@ecolekua.com');
    fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => lockedMessage(15)]);
});

it('DEC-017 attempts made while locked do not extend the lock', function () {
    foreach (range(1, 5) as $i) {
        fail('ana@ecolekua.com');
    }

    $lockedUntil = DB::table('login_throttles')->where('email', 'ana@ecolekua.com')->value('locked_until');

    $this->travel(5)->minutes();
    fail('ana@ecolekua.com');
    fail('ana@ecolekua.com');

    expect(DB::table('login_throttles')->where('email', 'ana@ecolekua.com')->value('locked_until'))->toBe($lockedUntil);
});

it('DEC-018 the 5th failure keeps the generic message and the 6th returns the pluralized lock message', function () {
    foreach (range(1, 4) as $i) {
        fail('ana@ecolekua.com');
    }

    fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => __('auth.failed')]);
    fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => lockedMessage(15)]);

    // 14 minutes 30 seconds later: 30 seconds remain and the message uses the singular.
    $this->travel(14)->minutes();
    $this->travel(30)->seconds();

    fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => lockedMessage(1)]);
    expect(lockedMessage(1))->toContain('1 minuto')->not->toContain('1 minutos');
});

it('DEC-018 the lock message for an unknown email equals the one for an existing email', function () {
    foreach (range(1, 5) as $i) {
        fail('ana@ecolekua.com');
        fail('fantasma@ecolekua.com');
    }

    // Same remaining time, same text: the message never reveals whether the account exists.
    fail('ana@ecolekua.com')->assertSessionHasErrors(['email' => lockedMessage(15)]);
    fail('fantasma@ecolekua.com')->assertSessionHasErrors(['email' => lockedMessage(15)]);
});
