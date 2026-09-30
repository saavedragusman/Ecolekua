<?php

use App\Enums\PermissionName;

it('FND-017 defines exactly the permissions of the spec catalogs (001 and 002)', function () {
    $values = array_map(fn (PermissionName $case) => $case->value, PermissionName::cases());

    expect($values)->toEqualCanonicalizing([
        'users.view',
        'users.create',
        'users.update',
        'users.deactivate',
        'users.reset_password',
        'users.assign_roles',
        'roles.view',
        'roles.manage',
        'audit.view',
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.deactivate',
        'customers.delete',
        'customers.assign',
        'customers.portfolio',
    ]);
});

it('FND-017 gives every permission a non-empty Spanish description', function () {
    expect(PermissionName::cases())->toHaveCount(16);

    foreach (PermissionName::cases() as $case) {
        expect($case->description())->toBeString()->not->toBe('');
    }
});

it('FND-017 uses the description text of spec section 9', function () {
    expect(PermissionName::UsersView->description())->toBe('Consultar listado y detalle de usuarios')
        ->and(PermissionName::RolesManage->description())->toBe('Crear, modificar y eliminar roles y asignarles permisos');
});

it('CLI-015 uses the description text of spec 002 section 4', function () {
    expect(PermissionName::CustomersView->description())->toBe('Ver el listado y la ficha de todos los clientes')
        ->and(PermissionName::CustomersCreate->description())->toBe('Registrar clientes')
        ->and(PermissionName::CustomersUpdate->description())->toBe('Editar datos del cliente, su persona de contacto y su dirección')
        ->and(PermissionName::CustomersDeactivate->description())->toBe('Desactivar y reactivar clientes')
        ->and(PermissionName::CustomersDelete->description())->toBe('Eliminar clientes sin historial')
        ->and(PermissionName::CustomersAssign->description())->toBe('Asignar y reasignar la asesora de un cliente')
        ->and(PermissionName::CustomersPortfolio->description())->toBe('Poder tener cartera: ser asesora asignada de clientes');
});
