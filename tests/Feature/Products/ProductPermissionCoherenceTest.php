<?php

use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;

const PRODUCTS_VIEW_REQUIRED_MESSAGE = 'Los permisos de productos requieren también «Ver categorías, atributos, productos, combinaciones y combos».';

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function productCoherenceIds(array $names): array
{
    return Permission::query()->whereIn('name', $names)->pluck('id')->all();
}

/**
 * Every `products.*` permission other than `products.view`, derived from the catalog.
 *
 * @return list<string>
 */
function productDependentPermissions(): array
{
    return array_values(array_filter(
        array_map(fn (PermissionName $permission): string => $permission->value, PermissionName::cases()),
        fn (string $name): bool => str_starts_with($name, 'products.') && $name !== PermissionName::ProductsView->value,
    ));
}

function productCoherenceAuditCount(): int
{
    return AuditLog::query()->where('action', 'like', 'roles.permissions_%')->count();
}

it('E-33 rejects each products.* permission other than products.view without products.view', function (string $permission) {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => productCoherenceIds([$permission])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions')
        ->assertJsonFragment(['permissions' => [PRODUCTS_VIEW_REQUIRED_MESSAGE]]);

    expect($role->permissions()->count())->toBe(0)
        ->and(productCoherenceAuditCount())->toBe(0);
})->with(fn (): array => productDependentPermissions());

it('DEC-PRD-23 covers exactly the five known dependents, so a new products.* permission must be reviewed here', function () {
    expect(productDependentPermissions())->toEqualCanonicalizing([
        'products.create',
        'products.update',
        'products.deactivate',
        'products.delete',
        'products.catalog',
    ]);
});

it('E-33 leaves the role unchanged when the incoherent set replaces an existing one', function () {
    $role = Role::factory()->create();
    $role->permissions()->attach(productCoherenceIds(['products.view', 'products.create']));

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => productCoherenceIds(['products.create', 'audit.view'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing(['products.view', 'products.create'])
        ->and(productCoherenceAuditCount())->toBe(0);
});

it('E-33 rejects an already-granted dependent permission when the same request only revokes products.view', function () {
    $role = Role::factory()->create();
    $role->permissions()->attach(productCoherenceIds(['products.view', 'products.catalog']));

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => productCoherenceIds(['products.catalog'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing(['products.view', 'products.catalog'])
        ->and(productCoherenceAuditCount())->toBe(0);
});

it('DEC-PRD-23 valid assignment: products.view plus each dependent permission is accepted and audited as in 001', function () {
    $role = Role::factory()->create();
    $all = ['products.view', 'products.create', 'products.update', 'products.deactivate', 'products.delete', 'products.catalog'];

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => productCoherenceIds($all)])
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', 'roles.permissions_granted')->sole();

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing($all)
        ->and($audit->new_values['permissions'])->toEqualCanonicalizing($all);
});

it('DEC-PRD-23 accepts products.view on its own', function () {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => productCoherenceIds(['products.view'])])
        ->assertRedirect();

    expect($role->permissions()->pluck('name')->all())->toBe(['products.view']);
});

it('DEC-PRD-23 violates several rules: DEC-022, DEC-CLI-32 and DEC-PRD-23 are reported together on permissions', function () {
    $role = Role::factory()->create();

    $response = $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => productCoherenceIds(['roles.manage', 'customers.update', 'products.update'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($response->json('errors.permissions'))->toEqualCanonicalizing([
        'El permiso «Crear, modificar y eliminar roles y asignarles permisos» requiere también «Consultar roles y sus permisos».',
        'Los permisos de clientes requieren también «Ver el listado y la ficha de todos los clientes».',
        PRODUCTS_VIEW_REQUIRED_MESSAGE,
    ])
        ->and($role->permissions()->count())->toBe(0)
        ->and(productCoherenceAuditCount())->toBe(0);
});
