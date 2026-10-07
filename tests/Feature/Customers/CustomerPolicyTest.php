<?php

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// CLI-015 / design Decision 8: one permission per ability, permissions and never role names.
dataset('customerAbilities', [
    'viewAny' => ['viewAny', PermissionName::CustomersView],
    'view' => ['view', PermissionName::CustomersView],
    'create' => ['create', PermissionName::CustomersCreate],
    'update' => ['update', PermissionName::CustomersUpdate],
    'deactivate' => ['deactivate', PermissionName::CustomersDeactivate],
    'delete' => ['delete', PermissionName::CustomersDelete],
    'assign' => ['assign', PermissionName::CustomersAssign],
]);

/**
 * @return list<mixed>
 */
function customerAbilityArguments(string $ability, Customer $customer): array
{
    return in_array($ability, ['viewAny', 'create'], true) ? [Customer::class] : [$customer];
}

it('CLI-015 allows each ability only with its own permission', function (string $ability, PermissionName $permission) {
    $customer = Customer::factory()->create();
    $holder = userWithPermissions($permission);

    expect(Gate::forUser($holder)->allows($ability, customerAbilityArguments($ability, $customer)))->toBeTrue();
})->with('customerAbilities');

it('CLI-015 denies each ability to a user holding every other customers permission', function (string $ability, PermissionName $permission) {
    $customer = Customer::factory()->create();
    $others = collect(PermissionName::cases())
        ->filter(fn (PermissionName $case) => $case !== $permission)
        ->all();
    $user = userWithPermissions(...$others);

    expect(Gate::forUser($user)->denies($ability, customerAbilityArguments($ability, $customer)))->toBeTrue();
})->with('customerAbilities');

it('CLI-015 denies every ability to a user without roles', function (string $ability) {
    $customer = Customer::factory()->create();

    expect(Gate::forUser(User::factory()->create())->denies($ability, customerAbilityArguments($ability, $customer)))->toBeTrue();
})->with(['viewAny', 'view', 'create', 'update', 'deactivate', 'delete', 'assign']);

it('CLI-015 does not grant anything by role name: Administrador without customers.delete cannot delete', function () {
    $administrator = administrator();
    $customer = Customer::factory()->create();

    $administrator->roles()->first()?->permissions()->detach(
        Permission::query()->where('name', PermissionName::CustomersDelete->value)->value('id'),
    );

    expect(Gate::forUser($administrator->fresh())->denies('delete', $customer))->toBeTrue()
        ->and(Gate::forUser($administrator->fresh())->allows('update', $customer))->toBeTrue();
});
