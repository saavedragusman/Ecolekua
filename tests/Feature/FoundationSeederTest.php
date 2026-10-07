<?php

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\FoundationSeeder;
use Illuminate\Support\Facades\DB;

const SEEDED_ROLES = [
    'Administrador',
    'Gerente',
    'Asesora de Ventas',
    'Supervisor de Producción',
    'Operario',
    'Responsable de Calidad',
    'Finanzas',
];

beforeEach(function () {
    // Start from an empty catalog so the test does not depend on TestCase seeding.
    DB::table('permission_role')->delete();
    DB::table('role_user')->delete();
    Role::query()->delete();
    Permission::query()->delete();
});

/**
 * @return array<int, string>
 */
function foundationPermissionNames(): array
{
    return array_values(array_filter(
        array_map(fn (PermissionName $p) => $p->value, PermissionName::cases()),
        fn (string $name) => ! str_starts_with($name, 'customers.') && ! str_starts_with($name, 'products.'),
    ));
}

it('FND-017 seeds the 7 roles of spec section 7 and the permission catalog', function () {
    $this->seed(FoundationSeeder::class);

    expect(Role::query()->orderBy('name')->pluck('name')->all())
        ->toEqualCanonicalizing(SEEDED_ROLES)
        ->and(Permission::query()->pluck('name')->all())
        ->toEqualCanonicalizing(array_map(fn (PermissionName $p) => $p->value, PermissionName::cases()))
        ->and(Permission::query()->whereNull('description')->orWhere('description', '')->count())->toBe(0);
});

it('FND-017 attaches the 001 permissions only to the protected Administrador role', function () {
    $this->seed(FoundationSeeder::class);

    $protected = Role::query()->where('is_protected', true)->get();
    $foundation = foundationPermissionNames();

    expect($foundation)->toHaveCount(9)
        ->and($protected)->toHaveCount(1)
        ->and($protected->first()->name)->toBe('Administrador')
        ->and($protected->first()->permissions()->whereIn('permissions.name', $foundation)->count())->toBe(9);

    // The customers.* and products.* grants of the other roles are covered by the DEC-CLI-11 and DEC-PRD-22 tests.
    Role::query()->where('is_protected', false)->get()->each(
        fn (Role $role) => expect($role->permissions()->whereIn('permissions.name', $foundation)->count())->toBe(0),
    );
});

it('FND-017 running the seeder twice does not duplicate rows or reassign permissions', function () {
    $this->seed(FoundationSeeder::class);

    $admin = Role::query()->where('is_protected', true)->firstOrFail();
    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $adminBefore = $admin->permissions()->count();
    $managerBefore = $manager->permissions()->count();

    $admin->permissions()->detach(Permission::query()->where('name', 'audit.view')->value('id'));
    $manager->permissions()->attach(Permission::query()->where('name', 'users.view')->value('id'));

    $this->seed(FoundationSeeder::class);

    expect(Role::count())->toBe(7)
        ->and(Permission::count())->toBe(count(PermissionName::cases()))
        ->and($admin->permissions()->count())->toBe($adminBefore - 1)
        ->and($manager->permissions()->count())->toBe($managerBefore + 1);
});
