<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
        // Atomic: the "created in this run" detection relies on a before/after snapshot, so a
        // partial run must roll back entirely; otherwise a retry would see the half-inserted
        // permissions/roles as pre-existing and never grant the initial matrix.
        DB::transaction(function (): void {
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
        });
    }
}
