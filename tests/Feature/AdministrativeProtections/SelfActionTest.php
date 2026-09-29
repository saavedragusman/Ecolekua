<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// FND-021 / E-26 (self-deactivation and own-role changes) ship with tasks 6.7-6.8.
// Until then these pending tests keep the temporary gap visible on every run.

it('E-26 rejects an administrator deactivating themselves')
    ->todo(note: 'Tasks 6.7-6.8 (FND-021)');

it('E-26 rejects an administrator changing their own roles')
    ->todo(note: 'Tasks 6.7-6.8 (FND-021)');

it('DEC-020 lets an administrator edit their own first name and email', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);

    $this->actingAs($actor)
        ->put("/users/{$actor->id}", ['first_name' => 'Propio', 'last_name' => $actor->last_name, 'email' => 'propio@ecolekua.com'])
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserUpdated->value)->sole();

    expect($actor->fresh()->first_name)->toBe('Propio')
        ->and($actor->fresh()->email)->toBe('propio@ecolekua.com')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($actor->id);
});

it('DEC-020 lets an administrator reset their own password, which closes their session and forces a change', function () {
    $actor = userWithPermissions(PermissionName::UsersResetPassword);
    $cookie = loginWithRealSession($actor);

    requestWithSession($cookie, 'PUT', "/users/{$actor->id}/password", ['password' => 'temporal-propia-1'])
        ->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $actor->id)->count())->toBe(0)
        ->and($actor->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('temporal-propia-1', $actor->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::UserPasswordReset->value)->sole()->entity_id)->toBe($actor->id);

    $this->assertGuest();

    requestWithSession($cookie, 'GET', '/')->assertRedirect(route('login'));
});

it('DEC-020 lands the next login of a self-reset on the forced password change', function () {
    $actor = userWithPermissions(PermissionName::UsersResetPassword);
    $cookie = loginWithRealSession($actor);

    requestWithSession($cookie, 'PUT', "/users/{$actor->id}/password", ['password' => 'temporal-propia-1'])
        ->assertRedirect(route('login'));

    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app()->forgetInstance('auth.driver');

    $this->post('/login', ['email' => $actor->email, 'password' => 'temporal-propia-1'])
        ->assertRedirect(route('password.edit'));
});

it('DEC-020 does not log out an administrator who resets the password of another user', function () {
    $actor = userWithPermissions(PermissionName::UsersResetPassword);
    $other = User::factory()->create();

    $this->actingAs($actor)
        ->put("/users/{$other->id}/password", ['password' => 'temporal-ajena-1'])
        ->assertRedirect()
        ->assertRedirectContains('/users/'.$other->id);

    $this->assertAuthenticatedAs($actor);
});
