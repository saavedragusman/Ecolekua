<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Creates the initial roles of spec 001, section 7, only when missing. The protected
 * Administrador role receives every permission at creation time; re-running never changes
 * the permissions of an existing role (they are managed from the application afterwards).
 */
class RoleSeeder extends Seeder
{
    private const ADMINISTRATOR = 'Administrador';

    /**
     * @var array<string, string>
     */
    private const ROLES = [
        self::ADMINISTRATOR => 'Administra usuarios, roles y auditoría.',
        'Gerente' => 'Supervisión general del negocio.',
        'Asesora de Ventas' => 'Funciones comerciales.',
        'Supervisor de Producción' => 'Supervisión de producción.',
        'Operario' => 'Ejecución de producción.',
        'Responsable de Calidad' => 'Control de calidad.',
        'Finanzas' => 'Funciones financieras y administrativas.',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $name => $description) {
            if (Role::query()->where('name', $name)->exists()) {
                continue;
            }

            $role = new Role(['name' => $name, 'description' => $description]);
            $role->is_protected = $name === self::ADMINISTRATOR;
            $role->save();

            if ($role->is_protected) {
                $role->permissions()->attach(Permission::query()->pluck('id')->all());
            }
        }
    }
}
