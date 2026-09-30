<?php

use App\Actions\Users\SyncUserRoles;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    // FND-020: the world always keeps one administrator, so these tests never trip the last-administrator rule.
    administrator();
});

it('FND-010 audits users.roles_assigned with the ids and names of the added roles only', function () {
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);
    $kept = Role::factory()->create(['name' => 'Ventas de prueba']);
    $added = Role::factory()->create(['name' => 'Almacén de prueba']);
    $target = User::factory()->create();
    $target->roles()->attach($kept);

    $this->actingAs($actor)
        ->putJson("/users/{$target->id}/roles", ['roles' => [$kept->id, $added->id]])
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserRolesAssigned->value)->sole();

    expect($target->roles()->pluck('roles.id')->sort()->values()->all())->toBe(collect([$kept->id, $added->id])->sort()->values()->all())
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($target->id)
        ->and($audit->new_values)->toEqual(['roles' => [['id' => $added->id, 'name' => 'Almacén de prueba']]])
        ->and($audit->ip_address)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::UserRolesRemoved->value)->count())->toBe(0);
});

it('FND-010 audits users.roles_removed with the ids and names of the removed roles only', function () {
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);
    $kept = Role::factory()->create(['name' => 'Ventas de prueba']);
    $removed = Role::factory()->create(['name' => 'Almacén de prueba']);
    $target = User::factory()->create();
    $target->roles()->attach([$kept->id, $removed->id]);

    $this->actingAs($actor)
        ->put("/users/{$target->id}/roles", ['roles' => [$kept->id]])
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserRolesRemoved->value)->sole();

    expect($target->roles()->pluck('roles.id')->all())->toBe([$kept->id])
        ->and($audit->old_values)->toEqual(['roles' => [['id' => $removed->id, 'name' => 'Almacén de prueba']]])
        ->and(AuditLog::query()->where('action', AuditAction::UserRolesAssigned->value)->count())->toBe(0);
});

it('FND-010 records both events when roles are replaced', function () {
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);
    $old = Role::factory()->create();
    $new = Role::factory()->create();
    $target = User::factory()->create();
    $target->roles()->attach($old);

    $this->actingAs($actor)->put("/users/{$target->id}/roles", ['roles' => [$new->id]])->assertRedirect();

    expect($target->roles()->pluck('roles.id')->all())->toBe([$new->id])
        ->and(AuditLog::query()->where('action', AuditAction::UserRolesAssigned->value)->sole()->new_values['roles'][0]['id'])->toBe($new->id)
        ->and(AuditLog::query()->where('action', AuditAction::UserRolesRemoved->value)->sole()->old_values['roles'][0]['id'])->toBe($old->id);
});

it('FND-010 writes no audit row when the set of roles does not change', function () {
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);
    $role = Role::factory()->create();
    $target = User::factory()->create();
    $target->roles()->attach($role);

    app(SyncUserRoles::class)->handle($target, [$role->id], $actor);

    expect(AuditLog::query()->whereIn('action', [AuditAction::UserRolesAssigned->value, AuditAction::UserRolesRemoved->value])->count())->toBe(0);
});

it('FND-018 refuses to remove the last role of an active user and shows the reason', function () {
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);
    $role = Role::factory()->create();
    $target = User::factory()->create();
    $target->roles()->attach($role);

    $this->actingAs($actor)
        ->from('/users/'.$target->id)
        ->put("/users/{$target->id}/roles", ['roles' => []])
        ->assertRedirect('/users/'.$target->id)
        ->assertInertiaFlash('type', 'error')
        ->assertInertiaFlash('message', 'Un usuario activo debe tener al menos un rol.');

    expect($target->roles()->pluck('roles.id')->all())->toBe([$role->id])
        ->and(AuditLog::query()->whereIn('action', [AuditAction::UserRolesAssigned->value, AuditAction::UserRolesRemoved->value])->count())->toBe(0);
});

it('FND-018 allows an inactive user to be left without roles', function () {
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);
    $target = User::factory()->inactive()->create();
    $target->roles()->attach(Role::factory()->create());

    $this->actingAs($actor)->put("/users/{$target->id}/roles", ['roles' => []])->assertRedirect();

    expect($target->roles()->count())->toBe(0);
});

it('FND-018 throws BusinessRuleViolation from SyncUserRoles when an active user would lose every role', function () {
    $target = User::factory()->create();
    $target->roles()->attach(Role::factory()->create());

    expect(fn () => app(SyncUserRoles::class)->handle($target, [], null))->toThrow(BusinessRuleViolation::class);
});

it('FND-010 rejects role ids that do not exist', function () {
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->put("/users/{$target->id}/roles", ['roles' => [999999]])
        ->assertSessionHasErrors('roles.0');
});

it('FND-010 forbids assigning roles without users.assign_roles and has no effect', function () {
    $actor = userWithPermissions(PermissionName::UsersView, PermissionName::UsersUpdate);
    $role = Role::factory()->create();
    $other = Role::factory()->create();
    $target = User::factory()->create();
    $target->roles()->attach($role);

    $this->actingAs($actor)->put("/users/{$target->id}/roles", ['roles' => [$other->id]])->assertForbidden();

    expect($target->roles()->pluck('roles.id')->all())->toBe([$role->id])
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->context['route'])->toBe('users.roles.update');
});
