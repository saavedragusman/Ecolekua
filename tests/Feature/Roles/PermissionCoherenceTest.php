<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function coherenceIds(array $names): array
{
    return Permission::query()->whereIn('name', $names)->pluck('id')->all();
}

function coherenceAuditCount(): int
{
    return AuditLog::query()->where('action', 'like', 'roles.permissions_%')->count();
}

it('DEC-022 rejects roles.manage without roles.view with a validation error on permissions', function () {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => coherenceIds(['roles.manage'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($role->permissions()->count())->toBe(0)
        ->and(coherenceAuditCount())->toBe(0);
});

it('DEC-022 shows the rejection as a validation error on a regular request', function () {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->from("/roles/{$role->id}")
        ->put("/roles/{$role->id}/permissions", ['permissions' => coherenceIds(['roles.manage'])])
        ->assertRedirect("/roles/{$role->id}")
        ->assertSessionHasErrors('permissions');

    expect($role->permissions()->count())->toBe(0);
});

it('DEC-022 rejects each users.* permission other than users.view without users.view', function (string $permission) {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => coherenceIds([$permission])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($role->permissions()->count())->toBe(0)
        ->and(coherenceAuditCount())->toBe(0);
})->with([
    'users.create',
    'users.update',
    'users.deactivate',
    'users.reset_password',
    'users.assign_roles',
]);

it('DEC-022 leaves the role unchanged when the incoherent set replaces an existing one', function () {
    $role = Role::factory()->create();
    $role->permissions()->attach(coherenceIds(['users.view', 'users.create']));

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => coherenceIds(['users.create', 'audit.view'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing(['users.view', 'users.create'])
        ->and(coherenceAuditCount())->toBe(0);
});

it('DEC-022 accepts roles.manage together with roles.view', function () {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => coherenceIds(['roles.view', 'roles.manage'])])
        ->assertRedirect();

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing(['roles.view', 'roles.manage']);
});

it('DEC-022 accepts users.* permissions together with users.view', function () {
    $role = Role::factory()->create();
    $all = ['users.view', 'users.create', 'users.update', 'users.deactivate', 'users.reset_password', 'users.assign_roles'];

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => coherenceIds($all)])
        ->assertRedirect();

    expect($role->permissions()->pluck('name')->all())->toEqualCanonicalizing($all);
});

it('DEC-022 does not require anything from users.view, roles.view or audit.view on their own', function () {
    $role = Role::factory()->create();

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => coherenceIds(['users.view', 'roles.view', 'audit.view'])])
        ->assertRedirect();

    expect($role->permissions()->count())->toBe(3);
});

it('DEC-022 rejects removing roles.view from the Administrador role while it keeps roles.manage', function () {
    $protected = Role::query()->where('is_protected', true)->firstOrFail();
    $remaining = Permission::query()->where('name', '!=', 'roles.view')->pluck('id')->all();

    $this->actingAs(administrator())
        ->putJson("/roles/{$protected->id}/permissions", ['permissions' => $remaining])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    expect($protected->permissions()->count())->toBe(9)
        ->and(coherenceAuditCount())->toBe(0);
});

it('DEC-022 still allows the empty permission set', function () {
    $role = Role::factory()->create();
    $role->permissions()->attach(coherenceIds(['users.view', 'users.create']));

    $this->actingAs(administrator())
        ->putJson("/roles/{$role->id}/permissions", ['permissions' => []])
        ->assertRedirect();

    expect($role->permissions()->count())->toBe(0);
});
