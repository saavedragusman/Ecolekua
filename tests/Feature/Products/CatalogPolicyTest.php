<?php

use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\DetailLocation;
use App\Models\Permission;
use App\Models\ProductCategory;
use App\Models\User;
use App\Policies\CatalogPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One catalog model instance of each governed type (PRD-016, design Decision 8).
 *
 * @return array<string, class-string|Model>
 */
function catalogSubjects(): array
{
    return [
        'category' => ProductCategory::factory()->create(),
        'attribute' => CatalogAttribute::factory()->create(),
        'value' => AttributeValue::factory()->create(),
        'location' => DetailLocation::factory()->create(),
    ];
}

it('PRD-016 registers CatalogPolicy for the four catalog models', function () {
    foreach ([ProductCategory::class, CatalogAttribute::class, AttributeValue::class, DetailLocation::class] as $model) {
        expect(Gate::getPolicyFor($model))->toBeInstanceOf(CatalogPolicy::class);
    }
});

it('PRD-016 lets manage require products.catalog only, on the class and on each instance', function () {
    $holder = userWithPermissions(PermissionName::ProductsCatalog);

    foreach (catalogSubjects() as $subject) {
        expect(Gate::forUser($holder)->allows('manage', $subject))->toBeTrue()
            ->and(Gate::forUser($holder)->allows('manage', $subject::class))->toBeTrue();
    }
});

it('PRD-016 denies manage to a user holding every other permission', function () {
    $others = collect(PermissionName::cases())
        ->filter(fn (PermissionName $case) => $case !== PermissionName::ProductsCatalog)
        ->all();
    $user = userWithPermissions(...$others);

    foreach (catalogSubjects() as $subject) {
        expect(Gate::forUser($user)->denies('manage', $subject))->toBeTrue()
            ->and(Gate::forUser($user)->denies('manage', $subject::class))->toBeTrue();
    }
});

it('PRD-016 lets viewAsset require products.view only', function () {
    $viewer = userWithPermissions(PermissionName::ProductsView);
    $others = collect(PermissionName::cases())
        ->filter(fn (PermissionName $case) => $case !== PermissionName::ProductsView)
        ->all();
    $outsider = userWithPermissions(...$others);

    foreach (catalogSubjects() as $subject) {
        expect(Gate::forUser($viewer)->allows('viewAsset', $subject))->toBeTrue()
            ->and(Gate::forUser($outsider)->denies('viewAsset', $subject))->toBeTrue();
    }
});

it('PRD-016 denies every catalog ability to a user without roles', function () {
    $user = User::factory()->create();

    foreach (catalogSubjects() as $subject) {
        expect(Gate::forUser($user)->denies('manage', $subject))->toBeTrue()
            ->and(Gate::forUser($user)->denies('viewAsset', $subject))->toBeTrue();
    }
});

it('PRD-016 does not grant anything by role name: Administrador without products.catalog cannot manage', function () {
    $administrator = administrator();
    $subject = ProductCategory::factory()->create();

    expect(Gate::forUser($administrator)->allows('manage', $subject))->toBeTrue();

    $administrator->roles()->first()?->permissions()->detach(
        Permission::query()->where('name', PermissionName::ProductsCatalog->value)->value('id'),
    );

    expect(Gate::forUser($administrator->fresh())->denies('manage', $subject))->toBeTrue()
        ->and(Gate::forUser($administrator->fresh())->allows('viewAsset', $subject))->toBeTrue();
});

it('PRD-016 does not rely on a Gate::before superadmin hook', function () {
    $source = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($source)->not->toContain('Gate::before')
        ->and(file_get_contents(app_path('Policies/CatalogPolicy.php')))->not->toContain('hasRole');
});
