<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

function changePassword(User $user, array $data): TestResponse
{
    return test()->actingAs($user)->put('/password', $data);
}

it('E-16 rejects a new password with fewer than 10 characters', function () {
    $user = User::factory()->create();

    changePassword($user, [
        'current_password' => 'password',
        'password' => 'corta1234',
        'password_confirmation' => 'corta1234',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('E-17 changes the password when the current one is correct and audits it', function () {
    $user = User::factory()->mustChangePassword()->create();

    changePassword($user, [
        'current_password' => 'password',
        'password' => 'nueva-clave-segura',
        'password_confirmation' => 'nueva-clave-segura',
    ])->assertRedirect(route('home'));

    $fresh = $user->fresh();
    expect(Hash::check('nueva-clave-segura', $fresh->password))->toBeTrue()
        ->and($fresh->must_change_password)->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->where('actor_id', $user->id)->count())->toBe(1);
});

it('E-17 rejects the change when the current password is incorrect', function () {
    $user = User::factory()->create();

    changePassword($user, [
        'current_password' => 'no-es-esta',
        'password' => 'nueva-clave-segura',
        'password_confirmation' => 'nueva-clave-segura',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('FND-013 rejects reusing the current password', function () {
    $user = User::factory()->create();

    changePassword($user, [
        'current_password' => 'password',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');
});

it('FND-013 rejects a confirmation that does not match', function () {
    $user = User::factory()->create();

    changePassword($user, [
        'current_password' => 'password',
        'password' => 'nueva-clave-segura',
        'password_confirmation' => 'otra-distinta-123',
    ])->assertSessionHasErrors('password');
});

it('E-28 the audit row of an own change contains neither the password nor its hash', function () {
    $user = User::factory()->create();

    changePassword($user, [
        'current_password' => 'password',
        'password' => 'nueva-clave-segura',
        'password_confirmation' => 'nueva-clave-segura',
    ]);

    $row = AuditLog::query()->where('action', AuditAction::PasswordChanged->value)->sole();
    $serialized = json_encode($row->getAttributes());

    expect($serialized)->not->toContain('nueva-clave-segura')
        ->and($serialized)->not->toContain($user->fresh()->password)
        ->and($serialized)->not->toContain($user->password)
        ->and($row->old_values)->toBeEmpty()
        ->and($row->new_values)->toBeEmpty();
});

it('FND-012 stores the password hashed, never in plain text', function () {
    $user = User::factory()->create();

    changePassword($user, [
        'current_password' => 'password',
        'password' => 'nueva-clave-segura',
        'password_confirmation' => 'nueva-clave-segura',
    ]);

    $stored = $user->fresh()->getRawOriginal('password');

    expect($stored)->not->toBe('nueva-clave-segura')
        ->and(Hash::check('nueva-clave-segura', $stored))->toBeTrue()
        ->and(Hash::info($stored)['algoName'])->not->toBe('unknown');
});

it('FND-015 requires authentication and no extra permission', function () {
    $this->put('/password', [])->assertRedirect(route('login'));

    // A user with no role at all can still change their own password.
    $user = User::factory()->create();

    changePassword($user, [
        'current_password' => 'password',
        'password' => 'nueva-clave-segura',
        'password_confirmation' => 'nueva-clave-segura',
    ])->assertRedirect();
});
