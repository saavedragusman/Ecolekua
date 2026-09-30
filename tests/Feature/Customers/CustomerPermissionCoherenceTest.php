<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;

const CUSTOMERS_VIEW_REQUIRED_MESSAGE = 'Los permisos de clientes requieren también «Ver el listado y la ficha de todos los clientes».';

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function customerCoherenceIds(array $names): array
{
    return Permission::query()->whereIn('name', $names)->pluck('id')->all();
}

function customerCoherenceAuditCount(): int
{
    return AuditLog::query()->where('action', 'like', 'roles.permissions_%')->count();
}

it('E-41 rejects each customers.* permission other than customers.view without customers.view', function (string $permission) {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => customerCoherenceIds([$permission])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions')
        ->assertJsonFragment(['permissions' => [CUSTOMERS_VIEW_REQUIRED_MESSAGE]]);

    expect($role->permissions()->count())->toBe(0)
        ->and(customerCoherenceAuditCount())->toBe(0);
})->with([
    'customers.create',
    'customers.update',
    'customers.deactivate',
    'customers.delete',
    'customers.assign',
    'customers.portfolio',
]);

it('E-41 leaves the role unchanged when the incoherent set replaces an existing one', function () {
    $role = Role::factory()->create();
    $role->permissions()->attach(customerCoherenceIds(['customers.view', 'customers.create']));

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => customerCoherenceIds(['customers.create', 'audit.view'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing(['customers.view', 'customers.create'])
        ->and(customerCoherenceAuditCount())->toBe(0);
});

it('E-41 rejects an already-granted dependent permission when the same request only revokes customers.view', function () {
    $role = Role::factory()->create();
    $role->permissions()->attach(customerCoherenceIds(['customers.view', 'customers.portfolio']));

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => customerCoherenceIds(['customers.portfolio'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing(['customers.view', 'customers.portfolio'])
        ->and(customerCoherenceAuditCount())->toBe(0);
});

it('DEC-CLI-32 valid assignment: customers.view plus each dependent permission is accepted and audited as in 001', function () {
    $role = Role::factory()->create();
    $all = ['customers.view', 'customers.create', 'customers.update', 'customers.deactivate', 'customers.delete', 'customers.assign', 'customers.portfolio'];

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => customerCoherenceIds($all)])
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', 'roles.permissions_granted')->sole();

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing($all)
        ->and($audit->new_values['permissions'])->toEqualCanonicalizing($all);
});

it('DEC-CLI-32 accepts customers.view on its own', function () {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => customerCoherenceIds(['customers.view'])])
        ->assertRedirect();

    expect($role->permissions()->pluck('name')->all())->toBe(['customers.view']);
});

it('DEC-CLI-32 violates DEC-022 and DEC-CLI-32 in one request and reports both messages on permissions', function () {
    $role = Role::factory()->create();

    $response = $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => customerCoherenceIds(['roles.manage', 'customers.update'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($response->json('errors.permissions'))->toEqualCanonicalizing([
        'El permiso «Crear, modificar y eliminar roles y asignarles permisos» requiere también «Consultar roles y sus permisos».',
        CUSTOMERS_VIEW_REQUIRED_MESSAGE,
    ])
        ->and($role->permissions()->count())->toBe(0)
        ->and(customerCoherenceAuditCount())->toBe(0);
});
