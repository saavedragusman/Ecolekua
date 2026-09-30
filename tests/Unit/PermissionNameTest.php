<?php

use App\Enums\PermissionName;

it('FND-017 defines exactly the 9 permissions of the spec catalog', function () {
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
    ]);
});

it('FND-017 gives every permission a non-empty Spanish description', function () {
    expect(PermissionName::cases())->toHaveCount(9);

    foreach (PermissionName::cases() as $case) {
        expect($case->description())->toBeString()->not->toBe('');
    }
});

it('FND-017 uses the description text of spec section 9', function () {
    expect(PermissionName::UsersView->description())->toBe('Consultar listado y detalle de usuarios')
        ->and(PermissionName::RolesManage->description())->toBe('Crear, modificar y eliminar roles y asignarles permisos');
});
