<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\FoundationSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The initial products.* grants of spec 003 section 4, written out independently of the seeder.
 *
 * @return array<string, list<string>> role name => products.* permission names
 */
function expectedProductsMatrix(): array
{
    $withoutDelete = ['products.view', 'products.create', 'products.update', 'products.deactivate', 'products.catalog'];

    return [
        'Administrador' => [...$withoutDelete, 'products.delete'],
        'Gerente' => $withoutDelete,
        'Asesora de Ventas' => $withoutDelete,
        'Finanzas' => ['products.view'],
        'Supervisor de Producción' => ['products.view'],
        'Responsable de Calidad' => ['products.view'],
        'Operario' => [],
    ];
}

/**
 * @return list<string>
 */
function productsPermissionNames(Role $role): array
{
    return $role->permissions()->where('permissions.name', 'like', 'products.%')->pluck('permissions.name')->all();
}

/**
 * Simulates a 002 database: the 6 products.* permissions do not exist yet.
 */
function removeProductsPermissions(): void
{
    $ids = Permission::query()->where('name', 'like', 'products.%')->pluck('id');

    DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
    Permission::query()->whereIn('id', $ids)->delete();
}

it('DEC-PRD-22 seeds a fresh database with the section 4 matrix', function () {
    expect(Permission::query()->where('name', 'like', 'products.%')->count())->toBe(6);

    foreach (expectedProductsMatrix() as $roleName => $expected) {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        expect(productsPermissionNames($role))->toEqualCanonicalizing($expected, $roleName);
    }
});

it('DEC-PRD-22 grants the matrix of the 6 new permissions to an existing 002 database without touching earlier grants', function () {
    removeProductsPermissions();

    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $administrator = Role::query()->where('name', 'Administrador')->firstOrFail();
    $manager->permissions()->attach(Permission::query()->where('name', 'users.view')->value('id'));
    $administrator->permissions()->detach(Permission::query()->where('name', 'customers.assign')->value('id'));

    expect(productsPermissionNames($manager))->toBe([]);

    $this->seed(FoundationSeeder::class);

    foreach (expectedProductsMatrix() as $roleName => $expected) {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        expect(productsPermissionNames($role))->toEqualCanonicalizing($expected, $roleName);
    }

    // Earlier grants keep their post-deploy state: users.view stays on Gerente and customers.assign
    // stays revoked from Administrador (the seeder does not re-grant what the roles screen removed).
    expect($manager->permissions()->where('permissions.name', 'users.view')->exists())->toBeTrue()
        ->and($administrator->permissions()->where('permissions.name', 'customers.assign')->exists())->toBeFalse();
});

it('DEC-PRD-22 does not re-grant a products.* permission revoked from a role after seeding', function () {
    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $manager->permissions()->detach(Permission::query()->where('name', 'products.catalog')->value('id'));

    $this->seed(FoundationSeeder::class);

    expect(productsPermissionNames($manager))
        ->toEqualCanonicalizing(['products.view', 'products.create', 'products.update', 'products.deactivate'])
        ->not->toContain('products.catalog');
});

it('DEC-PRD-22 skips a matrix role renamed from the UI without failing the seeder', function () {
    removeProductsPermissions();

    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $manager->update(['name' => 'Gerencia General']);

    $this->seed(FoundationSeeder::class);

    // The renamed role is left untouched (RoleSeeder recreates a missing "Gerente" as in 001/002, and
    // that new role gets the matrix by name); the other roles still get theirs.
    expect(productsPermissionNames($manager->fresh()))->toBe([]);

    $recreated = Role::query()->where('name', 'Gerente')->firstOrFail();
    $advisor = Role::query()->where('name', 'Asesora de Ventas')->firstOrFail();
    $administrator = Role::query()->where('name', 'Administrador')->firstOrFail();

    expect($recreated->id)->not->toBe($manager->id)
        ->and(productsPermissionNames($recreated))->toEqualCanonicalizing(expectedProductsMatrix()['Gerente'])
        ->and(productsPermissionNames($advisor))->toEqualCanonicalizing(expectedProductsMatrix()['Asesora de Ventas'])
        ->and(productsPermissionNames($administrator))->toEqualCanonicalizing(expectedProductsMatrix()['Administrador']);
});

it('DEC-PRD-22 gives the Operario role no products.* permission and only Administrador products.delete', function () {
    $operator = Role::query()->where('name', 'Operario')->firstOrFail();
    $holders = Role::query()
        ->whereHas('permissions', fn ($query) => $query->where('permissions.name', 'products.delete'))
        ->pluck('name')
        ->all();

    expect(productsPermissionNames($operator))->toBe([])
        ->and($holders)->toBe(['Administrador']);
});
