<?php

use App\Actions\Authorization\EnsureAdministrationIsPreserved;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// FND-020 / E-25: at least one active user must keep users.assign_roles AND roles.manage.
// In these tests `administrator()` is the single such user and the actor holds only the one
// permission needed for the operation, so the actor is never an administrator itself.

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function e25PermissionIds(array $names): array
{
    return Permission::query()->whereIn('name', $names)->pluck('id')->all();
}

function e25Message(): string
{
    return 'La operación dejaría al sistema sin ningún usuario activo con los permisos «Asignar y retirar roles a usuarios» y «Crear, modificar y eliminar roles y asignarles permisos».';
}

it('E-25 explains the rejection with the permission descriptions, not their technical names', function () {
    administrator();
    $actor = userWithPermissions(PermissionName::UsersDeactivate);
    $admin = User::query()->whereHas('roles', fn ($query) => $query->where('is_protected', true))->firstOrFail();

    $message = $this->actingAs($actor)->postJson("/users/{$admin->id}/deactivate")->assertStatus(422)->json('message');

    expect($message)->not->toContain('users.assign_roles')
        ->and($message)->not->toContain('roles.manage')
        ->and($message)->toContain(PermissionName::UsersAssignRoles->description())
        ->and($message)->toContain(PermissionName::RolesManage->description());
});

it('E-25 rejects deactivating the last active administrator', function () {
    $admin = administrator();
    $actor = userWithPermissions(PermissionName::UsersDeactivate);
    $cookie = loginWithRealSession($admin);

    $this->actingAs($actor)->postJson("/users/{$admin->id}/deactivate")
        ->assertStatus(422)->assertJson(['message' => e25Message()]);

    expect($admin->fresh()->is_active)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::UserDeactivated->value)->count())->toBe(0)
        ->and($cookie)->not->toBeEmpty();
});

it('E-25 rejects removing the roles of the last active administrator', function () {
    $admin = administrator();
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);
    $other = Role::factory()->create();
    $protected = Role::query()->where('is_protected', true)->firstOrFail();

    $this->actingAs($actor)->putJson("/users/{$admin->id}/roles", ['roles' => [$other->id]])
        ->assertStatus(422)->assertJson(['message' => e25Message()]);

    expect($admin->roles()->pluck('roles.id')->all())->toBe([$protected->id])
        ->and(AuditLog::query()->where('action', 'like', 'users.roles_%')->count())->toBe(0);
});

it('E-25 rejects removing users.assign_roles or roles.manage from the roles of the last active administrator', function (string $permission) {
    $admin = administrator();
    $actor = userWithPermissions(PermissionName::RolesManage);
    $protected = Role::query()->where('is_protected', true)->firstOrFail();
    $remaining = Permission::query()->where('name', '!=', $permission)->pluck('id')->all();
    $before = $protected->permissions()->count();

    $this->actingAs($actor)->putJson("/roles/{$protected->id}/permissions", ['permissions' => $remaining])
        ->assertStatus(422)->assertJson(['message' => e25Message()]);

    expect($protected->permissions()->count())->toBe($before)
        ->and(AuditLog::query()->where('action', 'like', 'roles.permissions_%')->count())->toBe(0)
        ->and($admin->fresh()->hasPermission($permission))->toBeTrue();
})->with(['users.assign_roles', 'roles.manage']);

it('E-25 allows revoking permissions of the administrator role that do not affect administration', function () {
    administrator();
    $actor = userWithPermissions(PermissionName::RolesManage);
    $protected = Role::query()->where('is_protected', true)->firstOrFail();
    $remaining = Permission::query()->where('name', '!=', 'audit.view')->pluck('id')->all();

    $this->actingAs($actor)->put("/roles/{$protected->id}/permissions", ['permissions' => $remaining])->assertRedirect();

    expect($protected->permissions()->count())->toBe(count($remaining));
});

it('E-25 allows deactivating an administrator while another active administrator remains', function () {
    $first = administrator();
    administrator();
    $actor = userWithPermissions(PermissionName::UsersDeactivate);

    $this->actingAs($actor)->post("/users/{$first->id}/deactivate")->assertRedirect();

    expect($first->fresh()->is_active)->toBeFalse();
});

it('E-25 rejects deactivating an administrator when the only other holder is inactive', function () {
    $active = administrator();
    $inactive = administrator();
    $inactive->forceFill(['is_active' => false])->save();
    $actor = userWithPermissions(PermissionName::UsersDeactivate);

    $this->actingAs($actor)->postJson("/users/{$active->id}/deactivate")->assertStatus(422);

    expect($active->fresh()->is_active)->toBeTrue();
});

it('E-25 allows removing a role of the last administrator while another role still gives both permissions', function () {
    $admin = administrator();
    $backup = Role::factory()->create();
    $backup->permissions()->attach(e25PermissionIds(['users.assign_roles', 'roles.manage']));
    $admin->roles()->attach($backup);
    $actor = userWithPermissions(PermissionName::UsersAssignRoles);

    $this->actingAs($actor)->put("/users/{$admin->id}/roles", ['roles' => [$backup->id]])->assertRedirect();

    expect($admin->roles()->pluck('roles.id')->all())->toBe([$backup->id]);
});

it('E-25 counts an administrator whose two permissions come from different roles', function () {
    $split = User::factory()->create();
    $assign = Role::factory()->create();
    $assign->permissions()->attach(e25PermissionIds(['users.assign_roles']));
    $manage = Role::factory()->create();
    $manage->permissions()->attach(e25PermissionIds(['roles.manage']));
    $split->roles()->attach([$assign->id, $manage->id]);

    app(EnsureAdministrationIsPreserved::class)->assert();

    expect(true)->toBeTrue();
});

it('E-25 does not count a user who holds only one of the two permissions or who is inactive', function () {
    userWithPermissions(PermissionName::UsersAssignRoles);
    userWithPermissions(PermissionName::RolesManage);
    $inactive = administrator();
    $inactive->forceFill(['is_active' => false])->save();

    expect(fn () => app(EnsureAdministrationIsPreserved::class)->assert())
        ->toThrow(BusinessRuleViolation::class, e25Message());
});

it('E-25 locks the two administration permission rows inside the transaction', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    DB::transaction(fn () => app(EnsureAdministrationIsPreserved::class)->lock());

    $locking = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'for update'));

    expect($locking)->toHaveCount(1)
        ->and($locking->first())->toContain('`permissions`');
});
