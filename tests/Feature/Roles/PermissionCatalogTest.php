<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function permissionIds(array $names): array
{
    return Permission::query()->whereIn('name', $names)->pluck('id')->all();
}

it('E-24 rejects an operation protected by a new permission until it is assigned to a role of the administrator', function () {
    Permission::query()->create(['name' => 'testing.e24', 'description' => 'Permiso de prueba']);
    Route::middleware('web')->get('/_test/e24', function () {
        Gate::authorize('testing.e24');

        return 'ok';
    });
    Gate::define('testing.e24', fn (User $user) => $user->hasPermission('testing.e24'));

    $admin = administrator();
    $protected = Role::query()->where('is_protected', true)->firstOrFail();

    $this->actingAs($admin)->get('/_test/e24')->assertForbidden();

    $all = Permission::query()->pluck('name')->all();
    $this->actingAs($admin)
        ->put("/roles/{$protected->id}/permissions", ['permissions' => permissionIds($all)])
        ->assertRedirect();

    $this->actingAs($admin)->get('/_test/e24')->assertOk();
});

it('FND-017 seeds 7 roles and the catalog; 001 permissions only on the protected role, customers.* and products.* per their matrices', function () {
    $protected = Role::query()->where('is_protected', true)->sole();
    $all = array_map(fn (PermissionName $p) => $p->value, PermissionName::cases());
    $foundation = array_values(array_filter(
        $all,
        fn (string $name) => ! str_starts_with($name, 'customers.') && ! str_starts_with($name, 'products.'),
    ));
    $productsFull = ['products.view', 'products.create', 'products.update', 'products.deactivate', 'products.catalog'];

    $administratorSet = array_merge($foundation, [
        'customers.view', 'customers.create', 'customers.update',
        'customers.deactivate', 'customers.delete', 'customers.assign',
    ], $productsFull, ['products.delete']);
    $expectedOthers = [
        'Gerente' => array_merge(['customers.view', 'customers.create', 'customers.update', 'customers.deactivate', 'customers.assign'], $productsFull),
        'Asesora de Ventas' => array_merge(['customers.view', 'customers.create', 'customers.update', 'customers.portfolio'], $productsFull),
        'Finanzas' => ['customers.view', 'products.view'],
        'Supervisor de Producción' => ['products.view'],
        'Responsable de Calidad' => ['products.view'],
    ];

    expect(Role::count())->toBe(7)
        ->and(Permission::count())->toBe(count(PermissionName::cases()))
        ->and($protected->name)->toBe('Administrador')
        ->and($protected->permissions()->pluck('name')->all())->toEqualCanonicalizing($administratorSet);

    Role::query()->where('is_protected', false)->get()->each(
        fn (Role $role) => expect($role->permissions()->pluck('name')->all())
            ->toEqualCanonicalizing($expectedOthers[$role->name] ?? []),
    );
});

it('FND-017 offers no route to create, edit or delete permissions', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->values();

    expect($names->filter(fn (string $name) => str_starts_with($name, 'permissions.'))->values()->all())->toBe([])
        ->and($names->filter(fn (string $name) => str_contains($name, 'permissions'))->values()->all())->toBe(['roles.permissions.update']);
});

it('FND-016 grants permissions to a role and audits roles.permissions_granted with their names', function () {
    $actor = administrator();
    $role = Role::factory()->create();

    $this->actingAs($actor)
        ->put("/roles/{$role->id}/permissions", ['permissions' => permissionIds(['users.view', 'audit.view'])])
        ->assertRedirect(route('roles.show', $role));

    $audit = AuditLog::query()->where('action', AuditAction::RolePermissionsGranted->value)->sole();

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing(['users.view', 'audit.view'])
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($role->id)
        ->and($audit->new_values['permissions'])->toEqualCanonicalizing(['users.view', 'audit.view'])
        ->and($audit->ip_address)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::RolePermissionsRevoked->value)->count())->toBe(0);
});

it('FND-016 revokes permissions from a role and audits roles.permissions_revoked with their names', function () {
    $actor = administrator();
    $role = Role::factory()->create();
    $role->permissions()->attach(permissionIds(['users.view', 'audit.view']));

    $this->actingAs($actor)->put("/roles/{$role->id}/permissions", ['permissions' => permissionIds(['users.view'])])->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::RolePermissionsRevoked->value)->sole();

    expect($role->permissions()->pluck('name')->all())->toBe(['users.view'])
        ->and($audit->old_values['permissions'])->toBe(['audit.view'])
        ->and(AuditLog::query()->where('action', AuditAction::RolePermissionsGranted->value)->count())->toBe(0);
});

it('FND-016 records both events when permissions are swapped and none when nothing changes', function () {
    $actor = administrator();
    $role = Role::factory()->create();
    $role->permissions()->attach(permissionIds(['users.view']));

    $this->actingAs($actor)->put("/roles/{$role->id}/permissions", ['permissions' => permissionIds(['users.view'])])->assertRedirect();
    expect(AuditLog::query()->where('action', 'like', 'roles.permissions_%')->count())->toBe(0);

    $this->actingAs($actor)->put("/roles/{$role->id}/permissions", ['permissions' => permissionIds(['audit.view'])])->assertRedirect();
    expect(AuditLog::query()->where('action', 'like', 'roles.permissions_%')->count())->toBe(2);
});

it('FND-016 lets a role end up with no permissions', function () {
    $actor = administrator();
    $role = Role::factory()->create();
    $role->permissions()->attach(permissionIds(['users.view']));

    $this->actingAs($actor)->put("/roles/{$role->id}/permissions", ['permissions' => []])->assertRedirect();

    expect($role->permissions()->count())->toBe(0);
});

it('FND-017 rejects a permission id that is not in the catalog', function () {
    $actor = administrator();
    $role = Role::factory()->create();

    $this->actingAs($actor)->put("/roles/{$role->id}/permissions", ['permissions' => [999999]])->assertSessionHasErrors('permissions.0');

    expect($role->permissions()->count())->toBe(0);
});

it('FND-016 answers 403 to a permission change without roles.manage and changes nothing', function () {
    $viewer = userWithPermissions(PermissionName::RolesView);
    $role = Role::factory()->create();

    $this->actingAs($viewer)->put("/roles/{$role->id}/permissions", ['permissions' => permissionIds(['users.view'])])->assertForbidden();

    expect($role->permissions()->count())->toBe(0);
});
