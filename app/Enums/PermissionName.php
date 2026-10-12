<?php

namespace App\Enums;

/**
 * Permission catalog of spec 001 (section 9) and spec 002 (section 4) and spec 003 (section 4). Copied into the `permissions` table by
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
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDeactivate = 'customers.deactivate';
    case CustomersDelete = 'customers.delete';
    case CustomersAssign = 'customers.assign';
    case CustomersPortfolio = 'customers.portfolio';
    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDeactivate = 'products.deactivate';
    case ProductsDelete = 'products.delete';
    case ProductsCatalog = 'products.catalog';

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
            self::CustomersView => 'Ver el listado y la ficha de todos los clientes',
            self::CustomersCreate => 'Registrar clientes',
            self::CustomersUpdate => 'Editar datos del cliente, su persona de contacto y su dirección',
            self::CustomersDeactivate => 'Desactivar y reactivar clientes',
            self::CustomersDelete => 'Eliminar clientes sin historial',
            self::CustomersAssign => 'Asignar y reasignar la asesora de un cliente',
            self::CustomersPortfolio => 'Poder tener cartera: ser asesora asignada de clientes',
            self::ProductsView => 'Ver categorías, atributos, productos, combinaciones y combos',
            self::ProductsCreate => 'Registrar productos, combinaciones y combos',
            self::ProductsUpdate => 'Editar productos, combinaciones, combos, detalles, personalizaciones, parámetros de stock, imagen y plantillas del producto',
            self::ProductsDeactivate => 'Desactivar y reactivar productos, combinaciones y combos',
            self::ProductsDelete => 'Eliminar productos, combinaciones y combos sin historial',
            self::ProductsCatalog => 'Gestionar categorías, atributos y sus valores, y ubicaciones de detalle, incluidos sus tonos, imágenes y capas',
        };
    }
}
