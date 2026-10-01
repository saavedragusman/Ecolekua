<?php

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\InitialRolePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/**
 * The initial customers.* grants of spec 002 section 4, written out independently of the seeder.
 *
 * @return array<string, list<string>> role name => customers.* permission names
 */
function expectedCustomersMatrix(): array
{
    return [
        'Administrador' => ['customers.view', 'customers.create', 'customers.update', 'customers.deactivate', 'customers.delete', 'customers.assign'],
        'Gerente' => ['customers.view', 'customers.create', 'customers.update', 'customers.deactivate', 'customers.assign'],
        'Asesora de Ventas' => ['customers.view', 'customers.create', 'customers.update', 'customers.portfolio'],
        'Finanzas' => ['customers.view'],
        'Supervisor de Producción' => [],
        'Operario' => [],
        'Responsable de Calidad' => [],
    ];
}

/**
 * @return list<string>
 */
function customersPermissionNames(Role $role): array
{
    return $role->permissions()->where('permissions.name', 'like', 'customers.%')->pluck('permissions.name')->all();
}

/**
 * Simulates a 001 database: the 7 customers.* permissions do not exist yet.
 */
function removeCustomersPermissions(): void
{
    $ids = Permission::query()->where('name', 'like', 'customers.%')->pluck('id');

    DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
    Permission::query()->whereIn('id', $ids)->delete();
}

it('DEC-CLI-11 seeds a fresh database with the section 4 matrix and the 001 grants only on Administrador', function () {
    $foundation = array_values(array_filter(
        array_map(fn (PermissionName $p) => $p->value, PermissionName::cases()),
        fn (string $name) => ! str_starts_with($name, 'customers.'),
    ));

    foreach (expectedCustomersMatrix() as $roleName => $expected) {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        expect(customersPermissionNames($role))->toEqualCanonicalizing($expected, $roleName);
    }

    $administrator = Role::query()->where('name', 'Administrador')->firstOrFail();

    expect($administrator->permissions()->whereIn('permissions.name', $foundation)->count())->toBe(9)
        ->and(customersPermissionNames($administrator))->not->toContain('customers.portfolio');

    Role::query()->where('is_protected', false)->get()->each(
        fn (Role $role) => expect($role->permissions()->whereIn('permissions.name', $foundation)->count())->toBe(0, $role->name),
    );
});

it('DEC-CLI-11 grants the matrix of the 7 new permissions to an existing 001 database without touching 001 grants', function () {
    removeCustomersPermissions();

    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $administrator = Role::query()->where('name', 'Administrador')->firstOrFail();
    $manager->permissions()->attach(Permission::query()->where('name', 'users.view')->value('id'));
    $administrator->permissions()->detach(Permission::query()->where('name', 'audit.view')->value('id'));

    expect(customersPermissionNames($manager))->toBe([]);

    $this->seed(FoundationSeeder::class);

    foreach (expectedCustomersMatrix() as $roleName => $expected) {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        expect(customersPermissionNames($role))->toEqualCanonicalizing($expected, $roleName);
    }

    // The 001 grants keep their post-deploy state: users.view stays on Gerente and audit.view
    // stays revoked from Administrador (the seeder does not re-grant what the roles screen removed).
    expect($manager->permissions()->where('permissions.name', 'users.view')->exists())->toBeTrue()
        ->and($administrator->permissions()->where('permissions.name', 'audit.view')->exists())->toBeFalse();
});

it('DEC-CLI-11 does not re-grant a customers.* permission revoked from a role after seeding', function () {
    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $manager->permissions()->detach(Permission::query()->where('name', 'customers.assign')->value('id'));

    $this->seed(FoundationSeeder::class);

    expect(customersPermissionNames($manager))
        ->toEqualCanonicalizing(['customers.view', 'customers.create', 'customers.update', 'customers.deactivate'])
        ->not->toContain('customers.assign');
});

it('DEC-CLI-11 skips a matrix role renamed from the UI without failing the seeder', function () {
    removeCustomersPermissions();

    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $manager->update(['name' => 'Gerencia General']);

    $this->seed(FoundationSeeder::class);

    // The renamed role is left untouched (RoleSeeder recreates a missing "Gerente" as in 001, and
    // that new role gets the matrix by name); the other roles still get theirs.
    expect(customersPermissionNames($manager->fresh()))->toBe([]);

    $recreated = Role::query()->where('name', 'Gerente')->firstOrFail();
    $advisor = Role::query()->where('name', 'Asesora de Ventas')->firstOrFail();
    $administrator = Role::query()->where('name', 'Administrador')->firstOrFail();

    expect($recreated->id)->not->toBe($manager->id)
        ->and(customersPermissionNames($recreated))->toEqualCanonicalizing(expectedCustomersMatrix()['Gerente'])
        ->and(customersPermissionNames($advisor))->toEqualCanonicalizing(expectedCustomersMatrix()['Asesora de Ventas'])
        ->and(customersPermissionNames($administrator))->toEqualCanonicalizing(expectedCustomersMatrix()['Administrador']);
});

it('DEC-CLI-11 R3-001 rolls back the whole seeding when the initial grants fail and grants the matrix on retry', function () {
    // Empty database: no permissions, no roles, no grants (a truly first run).
    DB::table('permission_role')->delete();
    DB::table('role_user')->delete();
    Role::query()->delete();
    Permission::query()->delete();

    $fail = true;
    Role::retrieved(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('simulated failure while granting');
        }
    });

    try {
        expect(fn () => $this->seed(FoundationSeeder::class))->toThrow(RuntimeException::class);

        // Catalog and roles were inserted before the failure: they must have been rolled back.
        expect(Permission::query()->count())->toBe(0)
            ->and(Role::query()->count())->toBe(0)
            ->and(DB::table('permission_role')->count())->toBe(0);

        $fail = false;
        $this->seed(FoundationSeeder::class);
    } finally {
        $fail = false;
        Event::forget('eloquent.retrieved: '.Role::class);
    }

    foreach (expectedCustomersMatrix() as $roleName => $expected) {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        expect(customersPermissionNames($role))->toEqualCanonicalizing($expected, $roleName);
    }

    expect(Role::query()->where('name', 'Administrador')->firstOrFail()->permissions()->count())->toBe(15);
});

it('DEC-CLI-11 R3-002 only FoundationSeeder invokes RoleSeeder, so the initial grants are never skipped', function () {
    // RoleSeeder grants no permissions; standalone use would leave Administrador empty. A source
    // scan is the simplest robust check: any other seeder referencing the class is a violation.
    $offenders = collect(File::files(database_path('seeders')))
        ->reject(fn (SplFileInfo $file) => in_array($file->getFilename(), ['FoundationSeeder.php', 'RoleSeeder.php'], true))
        ->filter(fn (SplFileInfo $file) => str_contains(file_get_contents($file->getPathname()), 'RoleSeeder'))
        ->map(fn (SplFileInfo $file) => $file->getFilename())
        ->values()
        ->all();

    expect($offenders)->toBe([])
        ->and(file_get_contents(database_path('seeders/FoundationSeeder.php')))->toContain('RoleSeeder::class');
});

it('DEC-CLI-11 warns and continues when a non-protected matrix role does not exist', function () {
    removeCustomersPermissions();
    Permission::query()->create(['name' => 'customers.view', 'description' => 'Ver']);
    DB::table('permission_role')->delete();
    Role::query()->where('name', 'Finanzas')->delete();

    $warnings = [];

    InitialRolePermissions::apply(['customers.view'], false, function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    });

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('Finanzas')
        ->and(customersPermissionNames(Role::query()->where('name', 'Gerente')->firstOrFail()))->toBe(['customers.view'])
        ->and(customersPermissionNames(Role::query()->where('name', 'Administrador')->firstOrFail()))->toBe(['customers.view']);
});
