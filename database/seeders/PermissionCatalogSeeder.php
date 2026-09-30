<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Copies the permission catalog (spec 001, section 9) into the database. Idempotent upsert by
 * name: it never deletes permissions and never assigns them to roles.
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
