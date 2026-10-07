<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Copies the permission catalog (specs 001 and 002) into the database. Idempotent upsert by
 * name: it never deletes permissions and never assigns them to roles.
 *
 * Not a deploy path on its own: running it alone creates permissions without their initial
 * grants. Use FoundationSeeder, which applies InitialRolePermissions right after (DEC-CLI-11).
 */
class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission->value],
                ['description' => $permission->description()],
            );
        }
    }
}
