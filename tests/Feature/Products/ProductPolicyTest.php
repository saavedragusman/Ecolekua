<?php

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Illuminate\Support\Facades\Gate;

/**
 * Ability => the one permission that governs it (design Decision 8).
 *
 * @return array<string, PermissionName>
 */
function productAbilityPermissions(): array
{
    return [
        'viewAny' => PermissionName::ProductsView,
        'view' => PermissionName::ProductsView,
        'create' => PermissionName::ProductsCreate,
        'update' => PermissionName::ProductsUpdate,
        'deactivate' => PermissionName::ProductsDeactivate,
        'delete' => PermissionName::ProductsDelete,
    ];
}

it('PRD-016 discovers ProductPolicy for the Product model', function () {
    expect(Gate::getPolicyFor(Product::class))->toBeInstanceOf(ProductPolicy::class);
});

it('PRD-016 lets each product ability require exactly its permission', function (string $ability, PermissionName $permission) {
    $product = Product::factory()->create();
    $subject = in_array($ability, ['viewAny', 'create'], true) ? Product::class : $product;
    $holder = userWithPermissions($permission);
    $others = collect(PermissionName::cases())->filter(fn (PermissionName $case) => $case !== $permission)->all();
    $outsider = userWithPermissions(...$others);

    expect(Gate::forUser($holder)->allows($ability, $subject))->toBeTrue()
        ->and(Gate::forUser($outsider)->denies($ability, $subject))->toBeTrue();
})->with(fn () => collect(productAbilityPermissions())->map(fn (PermissionName $permission, string $ability) => [$ability, $permission])->all());

it('PRD-016 denies every product ability to a user without roles', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    foreach (array_keys(productAbilityPermissions()) as $ability) {
        $subject = in_array($ability, ['viewAny', 'create'], true) ? Product::class : $product;

        expect(Gate::forUser($user)->denies($ability, $subject))->toBeTrue();
    }
});

it('PRD-016 does not grant anything by role name: Administrador without products.create cannot create', function () {
    $administrator = administrator();

    expect(Gate::forUser($administrator)->allows('create', Product::class))->toBeTrue();

    $administrator->roles()->first()?->permissions()->detach(
        Permission::query()->where('name', PermissionName::ProductsCreate->value)->value('id'),
    );

    expect(Gate::forUser($administrator->fresh())->denies('create', Product::class))->toBeTrue()
        ->and(Gate::forUser($administrator->fresh())->allows('view', Product::factory()->create()))->toBeTrue();
});

it('PRD-016 does not rely on role names in ProductPolicy', function () {
    expect(file_get_contents(app_path('Policies/ProductPolicy.php')))->not->toContain('hasRole');
});
