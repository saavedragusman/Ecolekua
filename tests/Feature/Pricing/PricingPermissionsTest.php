<?php

use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\FoundationSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The initial quotations.* and pricing.* grants of spec 004 section 4 (DEC-COT-25), written out
 * independently of the seeder.
 *
 * @return array<string, list<string>> role name => quotations.* and pricing.* permission names
 */
function expectedPricingMatrix(): array
{
    $quotations = ['quotations.view', 'quotations.create', 'quotations.negotiate', 'quotations.cancel'];

    return [
        'Administrador' => [...$quotations, 'pricing.view', 'pricing.manage'],
        'Gerente' => [...$quotations, 'pricing.view', 'pricing.manage'],
        'Asesora de Ventas' => [...$quotations, 'pricing.view'],
        'Finanzas' => ['quotations.view', 'pricing.view'],
        'Supervisor de Producción' => [],
        'Responsable de Calidad' => [],
        'Operario' => [],
    ];
}

/**
 * @return list<string>
 */
function pricingPermissionNames(Role $role): array
{
    return $role->permissions()
        ->where(fn ($query) => $query->where('permissions.name', 'like', 'quotations.%')->orWhere('permissions.name', 'like', 'pricing.%'))
        ->pluck('permissions.name')
        ->all();
}

/**
 * Simulates a 003 database: the 6 quotations.* and pricing.* permissions do not exist yet.
 */
function removePricingPermissions(): void
{
    $ids = Permission::query()
        ->where(fn ($query) => $query->where('name', 'like', 'quotations.%')->orWhere('name', 'like', 'pricing.%'))
        ->pluck('id');

    DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
    Permission::query()->whereIn('id', $ids)->delete();
}

it('DEC-COT-25 seeds a fresh database with the section 4 matrix', function () {
    expect(Permission::query()->where('name', 'like', 'quotations.%')->count())->toBe(4)
        ->and(Permission::query()->where('name', 'like', 'pricing.%')->count())->toBe(2)
        ->and(Permission::query()->count())->toBe(count(PermissionName::cases()));

    foreach (expectedPricingMatrix() as $roleName => $expected) {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        expect(pricingPermissionNames($role))->toEqualCanonicalizing($expected, $roleName);
    }
});

it('DEC-COT-25 grants the matrix of the 6 new permissions to an existing 003 database without touching earlier grants', function () {
    removePricingPermissions();

    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $administrator = Role::query()->where('name', 'Administrador')->firstOrFail();
    $manager->permissions()->attach(Permission::query()->where('name', 'users.view')->value('id'));
    $administrator->permissions()->detach(Permission::query()->where('name', 'products.catalog')->value('id'));

    expect(pricingPermissionNames($manager))->toBe([]);

    $this->seed(FoundationSeeder::class);

    foreach (expectedPricingMatrix() as $roleName => $expected) {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        expect(pricingPermissionNames($role))->toEqualCanonicalizing($expected, $roleName);
    }

    // Earlier grants keep their post-deploy state: users.view stays on Gerente and products.catalog
    // stays revoked from Administrador (the seeder does not re-grant what the roles screen removed).
    expect($manager->permissions()->where('permissions.name', 'users.view')->exists())->toBeTrue()
        ->and($administrator->permissions()->where('permissions.name', 'products.catalog')->exists())->toBeFalse();
});

it('DEC-COT-25 does not re-grant a quotations.* or pricing.* permission revoked from a role after seeding', function () {
    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $finance = Role::query()->where('name', 'Finanzas')->firstOrFail();
    $manager->permissions()->detach(Permission::query()->where('name', 'pricing.manage')->value('id'));
    $finance->permissions()->detach(Permission::query()->where('name', 'pricing.view')->value('id'));

    $this->seed(FoundationSeeder::class);

    expect(pricingPermissionNames($manager))
        ->toEqualCanonicalizing(['quotations.view', 'quotations.create', 'quotations.negotiate', 'quotations.cancel', 'pricing.view'])
        ->not->toContain('pricing.manage')
        ->and(pricingPermissionNames($finance))->toBe(['quotations.view']);
});

it('DEC-COT-25 skips a matrix role renamed from the UI without failing the seeder', function () {
    removePricingPermissions();

    $manager = Role::query()->where('name', 'Gerente')->firstOrFail();
    $manager->update(['name' => 'Gerencia General']);

    $this->seed(FoundationSeeder::class);

    // The renamed role is left untouched (RoleSeeder recreates a missing "Gerente", which gets the
    // matrix by name); the other roles still get theirs.
    expect(pricingPermissionNames($manager->fresh()))->toBe([]);

    $recreated = Role::query()->where('name', 'Gerente')->firstOrFail();
    $advisor = Role::query()->where('name', 'Asesora de Ventas')->firstOrFail();

    expect($recreated->id)->not->toBe($manager->id)
        ->and(pricingPermissionNames($recreated))->toEqualCanonicalizing(expectedPricingMatrix()['Gerente'])
        ->and(pricingPermissionNames($advisor))->toEqualCanonicalizing(expectedPricingMatrix()['Asesora de Ventas']);
});

it('DEC-COT-25 gives pricing.manage only to Administrador and Gerente, and nothing to production, quality or operators', function () {
    $holders = Role::query()
        ->whereHas('permissions', fn ($query) => $query->where('permissions.name', 'pricing.manage'))
        ->pluck('name')
        ->all();

    expect($holders)->toEqualCanonicalizing(['Administrador', 'Gerente']);

    foreach (['Supervisor de Producción', 'Responsable de Calidad', 'Operario'] as $roleName) {
        expect(pricingPermissionNames(Role::query()->where('name', $roleName)->firstOrFail()))->toBe([], $roleName);
    }
});

const QUOTATIONS_VIEW_REQUIRED_MESSAGE = 'Los permisos de cotizaciones requieren también «Ver cotizaciones, su detalle, su vista imprimible y el historial de cotizaciones en la ficha del cliente».';
const PRICING_VIEW_REQUIRED_MESSAGE = 'El permiso «Gestionar esquemas, asignarlos a productos, editar precios y parámetros comerciales, registrar la tasa manualmente, importar y aplicar actualizaciones masivas» requiere también «Ver esquemas, precios, precios de combos, parámetros comerciales y tasas; exportar los precios a CSV y ver el historial de importaciones».';

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function pricingCoherenceIds(array $names): array
{
    return Permission::query()->whereIn('name', $names)->pluck('id')->all();
}

function pricingCoherenceAuditCount(): int
{
    return AuditLog::query()->where('action', 'like', 'roles.permissions_%')->count();
}

it('E-47 rejects each quotations.* permission other than quotations.view without quotations.view', function (string $permission) {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => pricingCoherenceIds([$permission])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions')
        ->assertJsonFragment(['permissions' => [QUOTATIONS_VIEW_REQUIRED_MESSAGE]]);

    expect($role->permissions()->count())->toBe(0)
        ->and(pricingCoherenceAuditCount())->toBe(0);
})->with(['quotations.create', 'quotations.negotiate', 'quotations.cancel']);

it('E-47 rejects pricing.manage without pricing.view and leaves the role unchanged', function () {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => pricingCoherenceIds(['pricing.manage'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions')
        ->assertJsonFragment(['permissions' => [PRICING_VIEW_REQUIRED_MESSAGE]]);

    expect($role->permissions()->count())->toBe(0)
        ->and(pricingCoherenceAuditCount())->toBe(0);
});

it('E-47 leaves the role unchanged when the incoherent set replaces an existing one', function () {
    $role = Role::factory()->create();
    $role->permissions()->attach(pricingCoherenceIds(['quotations.view', 'quotations.negotiate', 'pricing.view']));

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => pricingCoherenceIds(['quotations.negotiate', 'pricing.manage'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing(['quotations.view', 'quotations.negotiate', 'pricing.view'])
        ->and(pricingCoherenceAuditCount())->toBe(0);
});

it('E-47 accepts quotations.view plus its dependents and pricing.view plus pricing.manage, and audits the grant', function () {
    $role = Role::factory()->create();
    $all = ['quotations.view', 'quotations.create', 'quotations.negotiate', 'quotations.cancel', 'pricing.view', 'pricing.manage'];

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => pricingCoherenceIds($all)])
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', 'roles.permissions_granted')->sole();

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing($all)
        ->and($audit->new_values['permissions'])->toEqualCanonicalizing($all);
});

it('E-47 reports every broken coherence rule of one request together on permissions', function () {
    $role = Role::factory()->create();

    $response = $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => pricingCoherenceIds(['roles.manage', 'products.update', 'quotations.cancel', 'pricing.manage'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($response->json('errors.permissions'))->toEqualCanonicalizing([
        'El permiso «Crear, modificar y eliminar roles y asignarles permisos» requiere también «Consultar roles y sus permisos».',
        'Los permisos de productos requieren también «Ver categorías, atributos, productos, combinaciones y combos».',
        QUOTATIONS_VIEW_REQUIRED_MESSAGE,
        PRICING_VIEW_REQUIRED_MESSAGE,
    ])
        ->and($role->permissions()->count())->toBe(0)
        ->and(pricingCoherenceAuditCount())->toBe(0);
});

it('DEC-COT-52 accepts pricing.view and pricing.manage without products.view', function () {
    $role = Role::factory()->create();
    $permissions = ['pricing.view', 'pricing.manage'];

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => pricingCoherenceIds($permissions)])
        ->assertRedirect();

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing($permissions);
});

it('DEC-COT-52 accepts pricing.view alone', function () {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => pricingCoherenceIds(['pricing.view'])])
        ->assertRedirect();

    expect($role->permissions()->pluck('name')->all())->toBe(['pricing.view']);
});
