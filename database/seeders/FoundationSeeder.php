<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

use function Laravel\Prompts\warning;

/**
 * Baseline data of specs 001 and 002 for every environment: permission catalog, initial roles
 * and the initial grants (DEC-CLI-11). This is the supported seeding path (also used by the
 * deploy step). No users are seeded; the first administrator is created with
 * `users:create-administrator`.
 */
class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        $existingPermissions = Permission::query()->pluck('name')->all();
        $protectedRoleExisted = Role::query()->where('is_protected', true)->exists();

        $this->call([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
        ]);

        $createdPermissions = array_values(array_diff(
            Permission::query()->pluck('name')->all(),
            $existingPermissions,
        ));

        InitialRolePermissions::apply(
            $createdPermissions,
            ! $protectedRoleExisted,
            fn (string $message) => warning($message),
        );
    }
}
