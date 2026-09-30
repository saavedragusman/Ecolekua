<?php

use App\Actions\Users\ActivateUser;
use App\Actions\Users\DeactivateUser;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/protected', fn () => 'ok');
});

it('E-09 rejects the next request of a user an administrator deactivates while their session is open', function () {
    $admin = userWithPermissions(PermissionName::UsersDeactivate);
    $target = User::factory()->create();
    $cookie = loginWithRealSession($target);

    requestWithSession($cookie, 'GET', '/_test/protected')->assertOk();

    $this->actingAs($admin)->post("/users/{$target->id}/deactivate")->assertRedirect();

    requestWithSession($cookie, 'GET', '/_test/protected')->assertRedirect(route('login'));

    expect($target->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});

it('E-09 leaves the sessions of other users untouched when one user is deactivated', function () {
    $admin = userWithPermissions(PermissionName::UsersDeactivate);
    $target = User::factory()->create();
    $bystander = User::factory()->create();
    loginWithRealSession($target);

    // A second real login needs a fresh guard and session store, as a second browser would have.
    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app()->forgetInstance('auth.driver');

    $bystanderCookie = loginWithRealSession($bystander);

    $this->actingAs($admin)->post("/users/{$target->id}/deactivate")->assertRedirect();

    requestWithSession($bystanderCookie, 'GET', '/_test/protected')->assertOk();
});

it('E-18 keeps the identity reference in the audit records of a deactivated user', function () {
    $admin = userWithPermissions(PermissionName::UsersDeactivate);
    $target = User::factory()->create(['email' => 'ana@ecolekua.com']);
    loginWithRealSession($target);
    $prior = AuditLog::query()->where('action', AuditAction::LoginSucceeded->value)->where('actor_id', $target->id)->sole();

    $this->actingAs($admin)->post("/users/{$target->id}/deactivate")->assertRedirect();

    $prior->refresh();

    expect($prior->actor_id)->toBe($target->id)
        ->and($prior->actor_email)->toBe('ana@ecolekua.com')
        ->and(User::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and($target->fresh()->is_active)->toBeFalse();
});

it('FND-011 audits users.deactivated with the is_active value before and after', function () {
    $admin = userWithPermissions(PermissionName::UsersDeactivate);
    $target = User::factory()->create();

    $this->actingAs($admin)->post("/users/{$target->id}/deactivate")->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserDeactivated->value)->sole();

    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->entity_id)->toBe($target->id)
        ->and($audit->old_values)->toEqual(['is_active' => true])
        ->and($audit->new_values)->toEqual(['is_active' => false])
        ->and($audit->ip_address)->not->toBeNull();
});

it('FND-011 does not write or audit when the user is already inactive', function () {
    $admin = userWithPermissions(PermissionName::UsersDeactivate);
    $target = User::factory()->inactive()->create();

    app(DeactivateUser::class)->handle($target, $admin);

    expect(AuditLog::query()->where('action', AuditAction::UserDeactivated->value)->count())->toBe(0);
});

it('FND-011 activates an inactive user with roles and audits users.activated', function () {
    $admin = userWithPermissions(PermissionName::UsersDeactivate);
    $target = User::factory()->inactive()->create();
    $target->roles()->attach(Role::factory()->create());

    $this->actingAs($admin)->post("/users/{$target->id}/activate")->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserActivated->value)->sole();

    expect($target->fresh()->is_active)->toBeTrue()
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->entity_id)->toBe($target->id)
        ->and($audit->old_values)->toEqual(['is_active' => false])
        ->and($audit->new_values)->toEqual(['is_active' => true]);
});

it('FND-018 refuses to activate a user without roles and shows the reason', function () {
    $admin = userWithPermissions(PermissionName::UsersDeactivate);
    $target = User::factory()->inactive()->create();

    $this->actingAs($admin)
        ->from('/users/'.$target->id)
        ->post("/users/{$target->id}/activate")
        ->assertRedirect('/users/'.$target->id)
        ->assertInertiaFlash('type', 'error')
        ->assertInertiaFlash('message', 'Un usuario activo debe tener al menos un rol.');

    expect($target->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::UserActivated->value)->count())->toBe(0);
});

it('FND-018 answers 422 as JSON when a business rule rejects the operation', function () {
    $admin = userWithPermissions(PermissionName::UsersDeactivate);
    $target = User::factory()->inactive()->create();

    $this->actingAs($admin)
        ->postJson("/users/{$target->id}/activate")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Un usuario activo debe tener al menos un rol.');
});

it('FND-018 throws BusinessRuleViolation from ActivateUser for a user without roles', function () {
    $target = User::factory()->inactive()->create();

    expect(fn () => app(ActivateUser::class)->handle($target, null))->toThrow(BusinessRuleViolation::class);
});

it('FND-010 forbids activating and deactivating without users.deactivate and has no effect', function () {
    $actor = userWithPermissions(PermissionName::UsersView, PermissionName::UsersUpdate);
    $active = User::factory()->create();
    $inactive = User::factory()->inactive()->create();
    $inactive->roles()->attach(Role::factory()->create());

    $this->actingAs($actor)->post("/users/{$active->id}/deactivate")->assertForbidden();
    $this->actingAs($actor)->post("/users/{$inactive->id}/activate")->assertForbidden();

    expect($active->fresh()->is_active)->toBeTrue()
        ->and($inactive->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->count())->toBe(2);
});
