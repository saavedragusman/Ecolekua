<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/protected', fn () => 'ok');
});

/**
 * Drops the in-memory guard and session store, as a second browser would have.
 */
function forgetInMemoryLogin(): void
{
    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app()->forgetInstance('auth.driver');
}

it('E-15 invalidates the sessions of the target and forces a password change at the next login', function () {
    $admin = userWithPermissions(PermissionName::UsersResetPassword);
    $target = User::factory()->create();
    $cookie = loginWithRealSession($target);

    requestWithSession($cookie, 'GET', '/_test/protected')->assertOk();

    $this->actingAs($admin)
        ->put("/users/{$target->id}/password", ['password' => 'temporal-nueva-1'])
        ->assertRedirect();

    requestWithSession($cookie, 'GET', '/_test/protected')->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and($target->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('temporal-nueva-1', $target->fresh()->password))->toBeTrue()
        ->and(Hash::check('password', $target->fresh()->password))->toBeFalse();

    forgetInMemoryLogin();

    $this->post('/login', ['email' => $target->email, 'password' => 'temporal-nueva-1'])
        ->assertRedirect(route('password.edit'));
});

it('E-15 also forces the change on a target that had no open session', function () {
    $admin = userWithPermissions(PermissionName::UsersResetPassword);
    $target = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($admin)->put("/users/{$target->id}/password", ['password' => 'temporal-nueva-1'])->assertRedirect();

    expect($target->fresh()->must_change_password)->toBeTrue();
});

it('E-16 rejects a reset password with fewer than 10 characters and changes nothing', function () {
    $admin = userWithPermissions(PermissionName::UsersResetPassword);
    $target = User::factory()->create();
    $hash = $target->password;

    $this->actingAs($admin)
        ->put("/users/{$target->id}/password", ['password' => 'corta-123'])
        ->assertSessionHasErrors('password');

    expect($target->fresh()->password)->toBe($hash)
        ->and($target->fresh()->must_change_password)->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::UserPasswordReset->value)->count())->toBe(0);
});

it('E-16 accepts a reset password of exactly 10 characters', function () {
    $admin = userWithPermissions(PermissionName::UsersResetPassword);
    $target = User::factory()->create();

    $this->actingAs($admin)->put("/users/{$target->id}/password", ['password' => 'exactos-10'])->assertSessionDoesntHaveErrors();

    expect(Hash::check('exactos-10', $target->fresh()->password))->toBeTrue();
});

it('E-28 records the reset without the password or its hash', function () {
    Log::spy();
    $admin = userWithPermissions(PermissionName::UsersResetPassword);
    $target = User::factory()->create();

    $this->actingAs($admin)->put("/users/{$target->id}/password", ['password' => 'temporal-nueva-1'])->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserPasswordReset->value)->sole();
    $stored = json_encode(AuditLog::query()->get()->map->getAttributes()->all());

    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->entity_id)->toBe($target->id)
        ->and($audit->old_values)->toBeEmpty()
        ->and($audit->new_values)->toBeEmpty()
        ->and($stored)->not->toContain('temporal-nueva-1')
        ->and($stored)->not->toContain($target->fresh()->password);

    Log::shouldNotHaveReceived('info', fn ($message) => str_contains((string) $message, 'temporal-nueva-1'));
});

it('FND-014 forbids resetting a password without users.reset_password and has no effect', function () {
    $actor = userWithPermissions(PermissionName::UsersView, PermissionName::UsersUpdate);
    $target = User::factory()->create();
    $hash = $target->password;

    $this->actingAs($actor)->put("/users/{$target->id}/password", ['password' => 'temporal-nueva-1'])->assertForbidden();

    expect($target->fresh()->password)->toBe($hash)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->context['route'])->toBe('users.password.reset');
});
