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

it('FND-017 seeds the 7 roles of spec section 7 and the 9 permissions of section 9', function () {
    $this->seed(FoundationSeeder::class);

    expect(Role::query()->orderBy('name')->pluck('name')->all())
        ->toEqualCanonicalizing(SEEDED_ROLES)
        ->and(Permission::query()->pluck('name')->all())
        ->toEqualCanonicalizing(array_map(fn (PermissionName $p) => $p->value, PermissionName::cases()))
        ->and(Permission::query()->whereNull('description')->orWhere('description', '')->count())->toBe(0);
});

it('FND-017 attaches all 9 permissions only to the protected Administrador role', function () {
    $this->seed(FoundationSeeder::class);

    $protected = Role::query()->where('is_protected', true)->get();

    expect($protected)->toHaveCount(1)
        ->and($protected->first()->name)->toBe('Administrador')
        ->and($protected->first()->permissions()->count())->toBe(9);

    Role::query()->where('is_protected', false)->get()->each(
        fn (Role $role) => expect($role->permissions()->count())->toBe(0),
    );
});

it('FND-017 running the seeder twice does not duplicate rows or reassign permissions', function () {
    $this->seed(FoundationSeeder::class);

    $admin = Role::query()->where('is_protected', true)->firstOrFail();
    $admin->permissions()->detach(Permission::query()->where('name', 'audit.view')->value('id'));
    Role::query()->where('name', 'Gerente')->firstOrFail()
        ->permissions()->attach(Permission::query()->where('name', 'users.view')->value('id'));

    $this->seed(FoundationSeeder::class);

    expect(Role::count())->toBe(7)
        ->and(Permission::count())->toBe(9)
        ->and($admin->permissions()->count())->toBe(8)
        ->and(Role::query()->where('name', 'Gerente')->firstOrFail()->permissions()->count())->toBe(1);
});
