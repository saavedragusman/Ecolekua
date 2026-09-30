<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, string>
 */
function identityPayload(User $user, string $firstName): array
{
    return ['first_name' => $firstName, 'last_name' => $user->last_name, 'email' => $user->email];
}

it('E-10 rejects an operation whose permission was revoked from the role, without logging in again', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create(['first_name' => 'Ana']);
    $cookie = loginWithRealSession($actor);

    requestWithSession($cookie, 'PUT', "/users/{$target->id}", identityPayload($target, 'Uno'))->assertRedirect();

    $actor->roles->first()->permissions()->detach();

    requestWithSession($cookie, 'PUT', "/users/{$target->id}", identityPayload($target, 'Dos'))->assertForbidden();

    expect($target->fresh()->first_name)->toBe('Uno');
});

it('E-10 rejects the operation when the user loses the role holding the permission', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create(['first_name' => 'Ana']);
    $cookie = loginWithRealSession($actor);

    $actor->roles()->detach();

    requestWithSession($cookie, 'PUT', "/users/{$target->id}", identityPayload($target, 'Intruso'))->assertForbidden();

    expect($target->fresh()->first_name)->toBe('Ana');
});

it('E-19 denies a user without the permission, leaves no effect and audits authorization.denied with actor and route', function () {
    $actor = userWithPermissions(PermissionName::UsersView);
    $target = User::factory()->create(['first_name' => 'Ana']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", identityPayload($target, 'Intruso'))
        ->assertForbidden();

    expect($target->fresh()->first_name)->toBe('Ana');

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->actor_email)->toBe($actor->email)
        ->and($audit->ip_address)->not->toBeNull()
        ->and($audit->context['route'])->toBe('users.update')
        ->and($audit->context['method'])->toBe('PUT')
        ->and($audit->context['parameters'])->toEqual(['user' => $target->id])
        ->and(AuditLog::query()->where('action', AuditAction::UserUpdated->value)->count())->toBe(0);
});

it('E-19 audits a denial raised by a controller Gate check, not only by a FormRequest', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);

    $this->actingAs($actor)->get('/users')->assertForbidden();

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toBe('users.index')
        ->and($audit->context['method'])->toBe('GET')
        ->and($audit->context['parameters'])->toEqual([]);
});

it('E-19 renders the errors/Forbidden page with status 403 on a full page visit', function () {
    $actor = userWithPermissions(PermissionName::UsersView);
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->put("/users/{$target->id}", identityPayload($target, 'Intruso'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('errors/Forbidden'));
});

it('E-19 answers an Inertia visit with the errors/Forbidden component and status 403', function () {
    $actor = userWithPermissions(PermissionName::UsersView);
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => inertia()->getVersion()])
        ->put("/users/{$target->id}", identityPayload($target, 'Intruso'))
        ->assertForbidden()
        ->assertJsonPath('component', 'errors/Forbidden');
});

it('E-19 answers a JSON 403 and audits the denial for JSON requests', function () {
    $actor = userWithPermissions(PermissionName::UsersView);
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->putJson("/users/{$target->id}", identityPayload($target, 'Intruso'))
        ->assertForbidden()
        ->assertJsonStructure(['message']);

    expect(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->count())->toBe(1);
});

it('E-20 lets a user with the permission execute the operation and audits no denial', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create(['first_name' => 'Ana']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", identityPayload($target, 'Luisa'))
        ->assertRedirect();

    expect($target->fresh()->first_name)->toBe('Luisa')
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->count())->toBe(0);
});

it('E-21 authorizes with the permissions of either of the two roles of a user', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    $second = Role::factory()->create();
    $second->permissions()->attach(Permission::query()->where('name', PermissionName::UsersUpdate->value)->firstOrFail());
    $actor->roles()->attach($second);

    $target = User::factory()->create(['first_name' => 'Ana']);

    $this->actingAs($actor)->put("/users/{$target->id}", identityPayload($target, 'Luisa'))->assertRedirect();

    $this->actingAs($actor)->post('/users', [
        'first_name' => 'Nuevo',
        'last_name' => 'Usuario',
        'email' => 'nuevo@ecolekua.com',
        'password' => 'clave-temporal-1',
        'roles' => [Role::factory()->create()->id],
    ])->assertRedirect();

    expect($target->fresh()->first_name)->toBe('Luisa')
        ->and(User::query()->where('email', 'nuevo@ecolekua.com')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->count())->toBe(0);
});

it('E-21 still denies an operation none of the roles of the user grants', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    $actor->roles()->attach(Role::factory()->create());
    $target = User::factory()->create(['first_name' => 'Ana']);

    $this->actingAs($actor)->put("/users/{$target->id}", identityPayload($target, 'Intruso'))->assertForbidden();

    expect($target->fresh()->first_name)->toBe('Ana');
});
