<?php

namespace App\Enums;

/**
 * Permission catalog of spec 001 (section 9). Copied into the `permissions` table by
 * PermissionCatalogSeeder; code asks for permissions, never for role names.
 */
enum PermissionName: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDeactivate = 'users.deactivate';
    case UsersResetPassword = 'users.reset_password';
    case UsersAssignRoles = 'users.assign_roles';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';

    /**
     * Spanish description stored in `permissions.description`.
     */
    public function description(): string
    {
        return match ($this) {
            self::UsersView => 'Consultar listado y detalle de usuarios',
            self::UsersCreate => 'Crear usuarios',
            self::UsersUpdate => 'Modificar nombre, apellido y correo electrónico',
            self::UsersDeactivate => 'Activar y desactivar usuarios',
            self::UsersResetPassword => 'Restablecer la contraseña de un usuario',
            self::UsersAssignRoles => 'Asignar y retirar roles a usuarios',
            self::RolesView => 'Consultar roles y sus permisos',
            self::RolesManage => 'Crear, modificar y eliminar roles y asignarles permisos',
            self::AuditView => 'Consultar registros de auditoría',
        };
    }
}
