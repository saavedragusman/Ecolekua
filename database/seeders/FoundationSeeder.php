<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Baseline data of spec 001 for every environment: permission catalog and initial roles.
 * No users are seeded; the first administrator is created with `users:create-administrator`.
 */
class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
        ]);
    }
}
