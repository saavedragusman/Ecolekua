<?php

use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;

function catalogManager(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCatalog);
}

/**
 * @return Collection<int, AuditLog>
 */
function catalogAuditRows(AuditAction ...$actions): Collection
{
    return AuditLog::query()
        ->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))
        ->orderBy('id')
        ->get();
}

it('PRD-001 creates a category at the end of the order and audits catalog.created', function () {
    $actor = catalogManager();
    ProductCategory::factory()->create(['name' => 'Camisas', 'sort_order' => 1]);
    ProductCategory::factory()->create(['name' => 'Pantalones', 'sort_order' => 7]);

    $this->actingAs($actor)
        ->postJson('/catalog/categories', ['name' => '  Chaquetas  '])
        ->assertRedirect();

    $category = ProductCategory::query()->where('name', 'Chaquetas')->sole();

    expect($category->sort_order)->toBe(8)
        ->and($category->status)->toBe(CatalogStatus::Active);

    $audit = catalogAuditRows(AuditAction::CatalogCreated)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($category->getMorphClass())
        ->and($audit->entity_id)->toBe($category->id)
        ->and($audit->new_values)->toEqual(['name' => 'Chaquetas', 'sort_order' => 8, 'status' => 'active']);
});

it('PRD-001 creates the first category with sort order 1', function () {
    $this->actingAs(catalogManager())->postJson('/catalog/categories', ['name' => 'Camisas'])->assertRedirect();

    expect(ProductCategory::query()->sole()->sort_order)->toBe(1);
});

it('E-64 (category) rejects a name that only differs in letter case', function () {
    ProductCategory::factory()->create(['name' => 'Camisas']);

    $this->actingAs(catalogManager())
        ->postJson('/catalog/categories', ['name' => 'camisas'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(ProductCategory::query()->count())->toBe(1)
        ->and(catalogAuditRows(AuditAction::CatalogCreated))->toHaveCount(0);
});

it('PRD-001 requires a name of at most 100 characters', function (mixed $name) {
    $this->actingAs(catalogManager())
        ->postJson('/catalog/categories', ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(ProductCategory::query()->count())->toBe(0);
})->with(['missing' => [null], 'blank' => ['   '], 'too long' => [str_repeat('a', 101)]]);

it('PRD-001 renames a category and audits only the changed fields', function () {
    $actor = catalogManager();
    $category = ProductCategory::factory()->create(['name' => 'Camisas', 'sort_order' => 3]);

    $this->actingAs($actor)
        ->putJson("/catalog/categories/{$category->id}", ['name' => 'Camisas y blusas'])
        ->assertRedirect();

    expect($category->fresh()->name)->toBe('Camisas y blusas')
        ->and($category->fresh()->sort_order)->toBe(3);

    $audit = catalogAuditRows(AuditAction::CatalogUpdated)->sole();

    expect($audit->entity_id)->toBe($category->id)
        ->and($audit->old_values)->toEqual(['name' => 'Camisas'])
        ->and($audit->new_values)->toEqual(['name' => 'Camisas y blusas']);
});

it('PRD-001 writes no audit when an edit changes nothing', function () {
    $category = ProductCategory::factory()->create(['name' => 'Camisas']);

    $this->actingAs(catalogManager())
        ->putJson("/catalog/categories/{$category->id}", ['name' => 'Camisas'])
        ->assertRedirect();

    expect(catalogAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('E-64 (category) allows renaming a category to a different letter case of its own name', function () {
    $category = ProductCategory::factory()->create(['name' => 'camisas']);

    $this->actingAs(catalogManager())
        ->putJson("/catalog/categories/{$category->id}", ['name' => 'Camisas'])
        ->assertRedirect();

    expect($category->fresh()->name)->toBe('Camisas');
});

it('E-64 (category) rejects renaming to the name of another category', function () {
    ProductCategory::factory()->create(['name' => 'Camisas']);
    $other = ProductCategory::factory()->create(['name' => 'Pantalones']);

    $this->actingAs(catalogManager())
        ->putJson("/catalog/categories/{$other->id}", ['name' => 'CAMISAS'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($other->fresh()->name)->toBe('Pantalones')
        ->and(catalogAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('PRD-001 moves a category down swapping the order with its neighbor and audits both rows', function () {
    $actor = catalogManager();
    $first = ProductCategory::factory()->create(['sort_order' => 1]);
    $second = ProductCategory::factory()->create(['sort_order' => 5]);
    $third = ProductCategory::factory()->create(['sort_order' => 9]);

    $this->actingAs($actor)
        ->postJson("/catalog/categories/{$first->id}/move", ['direction' => 'down'])
        ->assertRedirect();

    expect($first->fresh()->sort_order)->toBe(5)
        ->and($second->fresh()->sort_order)->toBe(1)
        ->and($third->fresh()->sort_order)->toBe(9);

    $audits = catalogAuditRows(AuditAction::CatalogUpdated);

    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('entity_id')->all())->toEqualCanonicalizing([$first->id, $second->id]);

    $movedItem = $audits->firstWhere('entity_id', $first->id);

    expect($movedItem->old_values)->toEqual(['sort_order' => 1])
        ->and($movedItem->new_values)->toEqual(['sort_order' => 5]);
});

it('PRD-001 moves a category up swapping the order with its neighbor', function () {
    $first = ProductCategory::factory()->create(['sort_order' => 2]);
    $second = ProductCategory::factory()->create(['sort_order' => 4]);

    $this->actingAs(catalogManager())
        ->postJson("/catalog/categories/{$second->id}/move", ['direction' => 'up'])
        ->assertRedirect();

    expect($second->fresh()->sort_order)->toBe(2)
        ->and($first->fresh()->sort_order)->toBe(4);
});

it('PRD-001 treats moving the first row up or the last row down as a no-op without audit', function () {
    $first = ProductCategory::factory()->create(['sort_order' => 1]);
    $last = ProductCategory::factory()->create(['sort_order' => 2]);
    $actor = catalogManager();

    $this->actingAs($actor)->postJson("/catalog/categories/{$first->id}/move", ['direction' => 'up'])->assertRedirect();
    $this->actingAs($actor)->postJson("/catalog/categories/{$last->id}/move", ['direction' => 'down'])->assertRedirect();

    expect($first->fresh()->sort_order)->toBe(1)
        ->and($last->fresh()->sort_order)->toBe(2)
        ->and(catalogAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('PRD-001 rejects a move direction other than up or down', function () {
    $category = ProductCategory::factory()->create();

    $this->actingAs(catalogManager())
        ->postJson("/catalog/categories/{$category->id}/move", ['direction' => 'sideways'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['direction']);
});

it('PRD-001 deactivates and reactivates a category auditing the status change', function () {
    $actor = catalogManager();
    $category = ProductCategory::factory()->create();

    $this->actingAs($actor)->postJson("/catalog/categories/{$category->id}/deactivate")->assertRedirect();

    expect($category->fresh()->status)->toBe(CatalogStatus::Inactive);

    $deactivated = catalogAuditRows(AuditAction::CatalogDeactivated)->sole();

    expect($deactivated->entity_id)->toBe($category->id)
        ->and($deactivated->old_values)->toEqual(['status' => 'active'])
        ->and($deactivated->new_values)->toEqual(['status' => 'inactive']);

    $this->actingAs($actor)->postJson("/catalog/categories/{$category->id}/activate")->assertRedirect();

    expect($category->fresh()->status)->toBe(CatalogStatus::Active);

    $activated = catalogAuditRows(AuditAction::CatalogActivated)->sole();

    expect($activated->old_values)->toEqual(['status' => 'inactive'])
        ->and($activated->new_values)->toEqual(['status' => 'active']);
});

it('PRD-013 repeating the same category state writes nothing', function () {
    $actor = catalogManager();
    $active = ProductCategory::factory()->create();
    $inactive = ProductCategory::factory()->inactive()->create();

    $this->actingAs($actor)->postJson("/catalog/categories/{$active->id}/activate")->assertRedirect();
    $this->actingAs($actor)->postJson("/catalog/categories/{$inactive->id}/deactivate")->assertRedirect();

    expect($active->fresh()->status)->toBe(CatalogStatus::Active)
        ->and($inactive->fresh()->status)->toBe(CatalogStatus::Inactive)
        ->and(catalogAuditRows(AuditAction::CatalogActivated, AuditAction::CatalogDeactivated))->toHaveCount(0);
});

it('PRD-001 never deletes a category: there is no delete route', function () {
    $category = ProductCategory::factory()->create();

    $this->actingAs(catalogManager())->deleteJson("/catalog/categories/{$category->id}")->assertStatus(405);

    $deleteRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'catalog/categories') && in_array('DELETE', $route->methods(), true));

    expect($deleteRoutes)->toHaveCount(0)
        ->and(ProductCategory::query()->count())->toBe(1);
});

it('PRD-016 denies each category write to a user without products.catalog and audits authorization.denied', function (string $method, Closure $path, array $payload) {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate);
    $category = ProductCategory::factory()->create(['name' => 'Camisas', 'sort_order' => 1]);
    ProductCategory::factory()->create(['sort_order' => 2]);

    $this->actingAs($actor)->json($method, $path($category), $payload)->assertForbidden();

    expect(ProductCategory::query()->count())->toBe(2)
        ->and($category->fresh()->name)->toBe('Camisas')
        ->and($category->fresh()->sort_order)->toBe(1)
        ->and($category->fresh()->status)->toBe(CatalogStatus::Active)
        ->and(catalogAuditRows(AuditAction::CatalogCreated, AuditAction::CatalogUpdated, AuditAction::CatalogActivated, AuditAction::CatalogDeactivated))->toHaveCount(0);

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toStartWith('catalog.categories.');
})->with([
    'store' => ['POST', fn () => '/catalog/categories', ['name' => 'Nueva']],
    'update' => ['PUT', fn (ProductCategory $category) => "/catalog/categories/{$category->id}", ['name' => 'Otra']],
    'move' => ['POST', fn (ProductCategory $category) => "/catalog/categories/{$category->id}/move", ['direction' => 'down']],
    'deactivate' => ['POST', fn (ProductCategory $category) => "/catalog/categories/{$category->id}/deactivate", []],
    'activate' => ['POST', fn (ProductCategory $category) => "/catalog/categories/{$category->id}/activate", []],
]);
