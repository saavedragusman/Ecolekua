<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    // The role pages ship with PR 6b; the controllers already render them.
    config(['inertia.testing.ensure_pages_exist' => false]);
});

it('E-22 rejects deleting a role that has at least one user assigned', function () {
    $actor = administrator();
    $role = Role::factory()->create();
    $role->users()->attach(User::factory()->create());

    $this->actingAs($actor)->deleteJson("/roles/{$role->id}")->assertStatus(422);

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::RoleDeleted->value)->count())->toBe(0);
});

it('E-22 shows the rejection as an error flash on a regular request', function () {
    $actor = administrator();
    $role = Role::factory()->create();
    $role->users()->attach(User::factory()->create());

    $this->actingAs($actor)->from('/roles')->delete("/roles/{$role->id}")->assertRedirect('/roles');

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
});

it('FND-016 deletes a role without users and audits roles.deleted with its name, description and permissions', function () {
    $actor = administrator();
    $role = Role::factory()->create(['name' => 'Rol temporal', 'description' => 'Se elimina']);
    $role->permissions()->attach(Permission::query()->whereIn('name', ['users.view', 'audit.view'])->pluck('id'));

    $this->actingAs($actor)->delete("/roles/{$role->id}")->assertRedirect(route('roles.index'));

    $audit = AuditLog::query()->where('action', AuditAction::RoleDeleted->value)->sole();

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($role->id)
        ->and($audit->old_values['name'])->toBe('Rol temporal')
        ->and($audit->old_values['description'])->toBe('Se elimina')
        ->and($audit->old_values['permissions'])->toEqualCanonicalizing(['users.view', 'audit.view'])
        ->and($audit->ip_address)->not->toBeNull();
});

it('E-23 rejects deleting the protected Administrador role', function () {
    $actor = administrator();
    $protected = Role::query()->where('is_protected', true)->firstOrFail();

    $this->actingAs($actor)->deleteJson("/roles/{$protected->id}")->assertStatus(422);

    expect(Role::query()->whereKey($protected->id)->exists())->toBeTrue();
});

it('E-23 rejects renaming the protected Administrador role', function () {
    $actor = administrator();
    $protected = Role::query()->where('is_protected', true)->firstOrFail();

    $this->actingAs($actor)
        ->putJson("/roles/{$protected->id}", ['name' => 'Jefe', 'description' => $protected->description])
        ->assertStatus(422);

    expect($protected->fresh()->name)->toBe('Administrador');
});

it('E-23 lets the description of the protected role be edited and audits the change', function () {
    $actor = administrator();
    $protected = Role::query()->where('is_protected', true)->firstOrFail();

    $this->actingAs($actor)
        ->put("/roles/{$protected->id}", ['name' => 'Administrador', 'description' => 'Nueva descripción'])
        ->assertRedirect(route('roles.show', $protected));

    $audit = AuditLog::query()->where('action', AuditAction::RoleUpdated->value)->sole();

    expect($protected->fresh()->description)->toBe('Nueva descripción')
        ->and($protected->fresh()->name)->toBe('Administrador')
        ->and($audit->old_values)->toEqual(['description' => 'Administra usuarios, roles y auditoría.'])
        ->and($audit->new_values)->toEqual(['description' => 'Nueva descripción']);
});

it('FND-016 creates a role without permissions (DEC-013) and audits roles.created', function () {
    $actor = administrator();

    $this->actingAs($actor)
        ->post('/roles', ['name' => '  Almacén  ', 'description' => 'Control de almacén'])
        ->assertRedirect();

    $role = Role::query()->where('name', 'Almacén')->sole();
    $audit = AuditLog::query()->where('action', AuditAction::RoleCreated->value)->sole();

    expect($role->is_protected)->toBeFalse()
        ->and($role->permissions()->count())->toBe(0)
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($role->id)
        ->and($audit->new_values)->toEqual(['name' => 'Almacén', 'description' => 'Control de almacén']);
});

it('FND-016 rejects a duplicate role name on create and on rename', function () {
    $actor = administrator();
    $other = Role::factory()->create(['name' => 'Ventas de prueba']);

    $this->actingAs($actor)->post('/roles', ['name' => 'Ventas de prueba'])->assertSessionHasErrors('name');
    $this->actingAs($actor)->put("/roles/{$other->id}", ['name' => 'Gerente'])->assertSessionHasErrors('name');

    expect(Role::query()->where('name', 'Ventas de prueba')->count())->toBe(1)
        ->and($other->fresh()->name)->toBe('Ventas de prueba');
});

it('FND-016 lets a role keep its own name when only the description changes', function () {
    $actor = administrator();
    $role = Role::factory()->create(['name' => 'Ventas de prueba', 'description' => 'A']);

    $this->actingAs($actor)->put("/roles/{$role->id}", ['name' => 'Ventas de prueba', 'description' => 'B'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect($role->fresh()->description)->toBe('B');
});

it('FND-016 renames a non-protected role and audits only the changed fields', function () {
    $actor = administrator();
    $role = Role::factory()->create(['name' => 'Ventas de prueba', 'description' => 'Igual']);

    $this->actingAs($actor)->put("/roles/{$role->id}", ['name' => 'Ventas renombrado', 'description' => 'Igual'])->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::RoleUpdated->value)->sole();

    expect($role->fresh()->name)->toBe('Ventas renombrado')
        ->and($audit->old_values)->toEqual(['name' => 'Ventas de prueba'])
        ->and($audit->new_values)->toEqual(['name' => 'Ventas renombrado']);
});

it('FND-016 writes nothing when an update changes nothing', function () {
    $actor = administrator();
    $role = Role::factory()->create(['name' => 'Ventas de prueba', 'description' => 'Igual']);

    $this->actingAs($actor)->put("/roles/{$role->id}", ['name' => 'Ventas de prueba', 'description' => 'Igual'])->assertRedirect();

    expect(AuditLog::query()->where('action', AuditAction::RoleUpdated->value)->count())->toBe(0);
});

it('FND-016 lets roles.view list and show roles with their permissions but not manage them', function () {
    $viewer = userWithPermissions(PermissionName::RolesView);
    $role = Role::query()->where('is_protected', true)->firstOrFail();

    $this->actingAs($viewer)->get('/roles')->assertOk()->assertInertia(fn ($page) => $page->component('roles/Index'));
    $this->actingAs($viewer)->get("/roles/{$role->id}")->assertOk()->assertInertia(
        fn ($page) => $page->component('roles/Show')->where('role.name', 'Administrador')->has('role.permissions', 9),
    );
    $this->actingAs($viewer)->get('/roles/create')->assertForbidden();
});

it('FND-016 sends the permission catalog on the role detail only to who can manage roles', function () {
    $role = Role::factory()->create();

    $this->actingAs(userWithPermissions(PermissionName::RolesView))->get("/roles/{$role->id}")
        ->assertInertia(fn ($page) => $page->where('permissions', []));

    $this->actingAs(userWithPermissions(PermissionName::RolesView, PermissionName::RolesManage))->get("/roles/{$role->id}")
        ->assertInertia(fn ($page) => $page->has('permissions', 9));
});

it('FND-016 answers 403 to every role write without roles.manage and changes nothing', function () {
    $viewer = userWithPermissions(PermissionName::RolesView);
    $role = Role::factory()->create(['name' => 'Ventas de prueba']);

    $this->actingAs($viewer)->post('/roles', ['name' => 'Nuevo'])->assertForbidden();
    $this->actingAs($viewer)->get("/roles/{$role->id}/edit")->assertForbidden();
    $this->actingAs($viewer)->put("/roles/{$role->id}", ['name' => 'Otro'])->assertForbidden();
    $this->actingAs($viewer)->delete("/roles/{$role->id}")->assertForbidden();

    expect(Role::query()->where('name', 'Nuevo')->exists())->toBeFalse()
        ->and($role->fresh()->name)->toBe('Ventas de prueba');
});

it('FND-016 answers 403 to a user without roles.view listing roles', function () {
    $this->actingAs(userWithPermissions(PermissionName::UsersView))->get('/roles')->assertForbidden();
});

it('FND-016 grants access by permission, not by the role name', function () {
    $renamedAdmin = Role::query()->where('is_protected', true)->firstOrFail();
    $user = User::factory()->create();
    $user->roles()->attach(Role::factory()->create(['name' => 'Administrador de mentira']));

    $this->actingAs($user)->get('/roles')->assertForbidden();

    expect($renamedAdmin->is_protected)->toBeTrue();
});
