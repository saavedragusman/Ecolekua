<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use Closure;

/**
 * Initial grants of permissions to roles, declared once as seed data (spec 001 section 9,
 * spec 002 section 4 DEC-CLI-11, spec 003 section 4 DEC-PRD-22). Role names appear here and only here; runtime code asks for
 * permissions, never for role names.
 *
 * A (permission, role) pair is attached only when the permission was created in the current
 * seeding run, or when the role is the protected role and it was created in the current run.
 * Later runs never re-grant what was revoked from the roles screen.
 */
final class InitialRolePermissions
{
    private const ADMINISTRATOR = 'Administrador';

    /**
     * Permission name => names of the roles that receive it initially.
     *
     * @var array<string, list<string>>
     */
    public const MATRIX = [
        PermissionName::UsersView->value => [self::ADMINISTRATOR],
        PermissionName::UsersCreate->value => [self::ADMINISTRATOR],
        PermissionName::UsersUpdate->value => [self::ADMINISTRATOR],
        PermissionName::UsersDeactivate->value => [self::ADMINISTRATOR],
        PermissionName::UsersResetPassword->value => [self::ADMINISTRATOR],
        PermissionName::UsersAssignRoles->value => [self::ADMINISTRATOR],
        PermissionName::RolesView->value => [self::ADMINISTRATOR],
        PermissionName::RolesManage->value => [self::ADMINISTRATOR],
        PermissionName::AuditView->value => [self::ADMINISTRATOR],
        PermissionName::CustomersView->value => [self::ADMINISTRATOR, 'Gerente', 'Asesora de Ventas', 'Finanzas'],
        PermissionName::CustomersCreate->value => [self::ADMINISTRATOR, 'Gerente', 'Asesora de Ventas'],
        PermissionName::CustomersUpdate->value => [self::ADMINISTRATOR, 'Gerente', 'Asesora de Ventas'],
        PermissionName::CustomersDeactivate->value => [self::ADMINISTRATOR, 'Gerente'],
        PermissionName::CustomersAssign->value => [self::ADMINISTRATOR, 'Gerente'],
        PermissionName::CustomersDelete->value => [self::ADMINISTRATOR],
        PermissionName::CustomersPortfolio->value => ['Asesora de Ventas'],
        PermissionName::ProductsView->value => [self::ADMINISTRATOR, 'Gerente', 'Asesora de Ventas', 'Finanzas', 'Supervisor de Producción', 'Responsable de Calidad'],
        PermissionName::ProductsCreate->value => [self::ADMINISTRATOR, 'Gerente', 'Asesora de Ventas'],
        PermissionName::ProductsUpdate->value => [self::ADMINISTRATOR, 'Gerente', 'Asesora de Ventas'],
        PermissionName::ProductsDeactivate->value => [self::ADMINISTRATOR, 'Gerente', 'Asesora de Ventas'],
        PermissionName::ProductsCatalog->value => [self::ADMINISTRATOR, 'Gerente', 'Asesora de Ventas'],
        PermissionName::ProductsDelete->value => [self::ADMINISTRATOR],
    ];

    /**
     * @param  list<string>  $createdPermissionNames  Permissions created in this seeding run.
     * @param  bool  $protectedRoleCreated  Whether the protected role was created in this run.
     * @param  (Closure(string): void)|null  $warn  Receives the warning for a missing non-protected role.
     */
    public static function apply(array $createdPermissionNames, bool $protectedRoleCreated, ?Closure $warn = null): void
    {
        $roles = Role::query()->get()->keyBy('name');
        $permissions = Permission::query()->get()->keyBy('name');
        $warned = [];

        foreach (self::MATRIX as $permissionName => $roleNames) {
            $permission = $permissions->get($permissionName);

            if ($permission === null) {
                continue;
            }

            foreach ($roleNames as $roleName) {
                $role = $roles->get($roleName);

                if ($role === null) {
                    if (in_array($permissionName, $createdPermissionNames, true) && ! isset($warned[$roleName])) {
                        $warned[$roleName] = true;
                        $warn?->__invoke("El rol «{$roleName}» no existe; no se le asignaron permisos iniciales.");
                    }

                    continue;
                }

                $isNew = in_array($permissionName, $createdPermissionNames, true)
                    || ($role->is_protected && $protectedRoleCreated);

                if ($isNew) {
                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        }
    }
}
