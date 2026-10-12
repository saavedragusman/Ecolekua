<?php

use App\Actions\Products\CreateCategory;
use App\Actions\Products\MoveCatalogItem;
use App\Actions\Products\UpdateCategory;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\DetailLocation;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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

it('R3-004 requires a name of at most 100 characters when renaming', function (mixed $name) {
    $category = ProductCategory::factory()->create(['name' => 'Camisas']);

    $this->actingAs(catalogManager())
        ->putJson("/catalog/categories/{$category->id}", ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($category->fresh()->name)->toBe('Camisas')
        ->and(catalogAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
})->with(['missing' => [null], 'blank' => ['   '], 'too long' => [str_repeat('a', 101)]]);

it('R3-003 CreateCategory turns a unique-index violation into a name validation error', function () {
    ProductCategory::factory()->create(['name' => 'Camisas']);

    try {
        app(CreateCategory::class)->handle(['name' => 'camisas'], catalogManager());
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name');
    }

    expect(ProductCategory::query()->count())->toBe(1)
        ->and(catalogAuditRows(AuditAction::CatalogCreated))->toHaveCount(0);
});

it('R3-003 UpdateCategory turns a unique-index violation into a name validation error', function () {
    ProductCategory::factory()->create(['name' => 'Camisas']);
    $other = ProductCategory::factory()->create(['name' => 'Pantalones']);

    try {
        app(UpdateCategory::class)->handle($other, ['name' => 'CAMISAS'], catalogManager());
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name');
    }

    expect($other->fresh()->name)->toBe('Pantalones')
        ->and(catalogAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('scopeSearch treats % and _ literally in every catalog model', function (string $model) {
    $model::factory()->create(['name' => '100% algodón']);
    $model::factory()->create(['name' => '100 X algodón']);
    $model::factory()->create(['name' => 'tela_azul']);
    $model::factory()->create(['name' => 'tela azul']);

    expect($model::query()->search('100%')->pluck('name')->all())->toBe(['100% algodón'])
        ->and($model::query()->search('tela_a')->pluck('name')->all())->toBe(['tela_azul']);
})->with([
    'categories' => ProductCategory::class,
    'attributes' => CatalogAttribute::class,
    'values' => AttributeValue::class,
    'detail locations' => DetailLocation::class,
]);

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

/**
 * @return list<int>
 */
function categoryIdsInOrder(): array
{
    return ProductCategory::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
}

it('R3-001 moves a category up past a neighbor that shares its sort order', function () {
    $first = ProductCategory::factory()->create(['sort_order' => 5]);
    $second = ProductCategory::factory()->create(['sort_order' => 5]);
    $third = ProductCategory::factory()->create(['sort_order' => 9]);

    $this->actingAs(catalogManager())
        ->postJson("/catalog/categories/{$second->id}/move", ['direction' => 'up'])
        ->assertRedirect();

    expect(categoryIdsInOrder())->toBe([$second->id, $first->id, $third->id]);

    // The tie renumbers the whole scope 1..n: one audit row per row whose sort_order changed,
    // including the third row that was renumbered without being moved.
    $audits = catalogAuditRows(AuditAction::CatalogUpdated);

    expect($audits)->toHaveCount(3)
        ->and($audits->pluck('entity_id')->all())->toEqualCanonicalizing([$first->id, $second->id, $third->id]);

    foreach ([[$second, 5, 1], [$first, 5, 2], [$third, 9, 3]] as [$category, $old, $new]) {
        $audit = $audits->firstWhere('entity_id', $category->id);

        expect($audit->old_values)->toEqual(['sort_order' => $old])
            ->and($audit->new_values)->toEqual(['sort_order' => $new]);
    }
});

it('R3-001 audits no row whose sort order does not change when renumbering a tie', function () {
    $first = ProductCategory::factory()->create(['sort_order' => 1]);
    $second = ProductCategory::factory()->create(['sort_order' => 2]);
    $third = ProductCategory::factory()->create(['sort_order' => 2]);

    $this->actingAs(catalogManager())
        ->postJson("/catalog/categories/{$third->id}/move", ['direction' => 'up'])
        ->assertRedirect();

    expect(categoryIdsInOrder())->toBe([$first->id, $third->id, $second->id]);

    $audits = catalogAuditRows(AuditAction::CatalogUpdated);

    // Only the second row changes (2 -> 3); the first (1) and the third (2) keep their value.
    expect($audits->pluck('entity_id')->all())->toBe([$second->id]);
});

it('R3-001 moves a category down past a neighbor that shares its sort order', function () {
    $first = ProductCategory::factory()->create(['sort_order' => 2]);
    $second = ProductCategory::factory()->create(['sort_order' => 5]);
    $third = ProductCategory::factory()->create(['sort_order' => 5]);

    $this->actingAs(catalogManager())
        ->postJson("/catalog/categories/{$second->id}/move", ['direction' => 'down'])
        ->assertRedirect();

    expect(categoryIdsInOrder())->toBe([$first->id, $third->id, $second->id]);
});

it('R3-001 keeps the first and last rows as no-ops when every row ties', function () {
    $first = ProductCategory::factory()->create(['sort_order' => 3]);
    $last = ProductCategory::factory()->create(['sort_order' => 3]);
    $actor = catalogManager();

    $this->actingAs($actor)->postJson("/catalog/categories/{$first->id}/move", ['direction' => 'up'])->assertRedirect();
    $this->actingAs($actor)->postJson("/catalog/categories/{$last->id}/move", ['direction' => 'down'])->assertRedirect();

    expect(categoryIdsInOrder())->toBe([$first->id, $last->id])
        ->and(catalogAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('R3-002 locks every row of the scope in one query ordered by id', function () {
    $first = ProductCategory::factory()->create(['sort_order' => 1]);
    ProductCategory::factory()->create(['sort_order' => 2]);

    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = strtolower($query->sql);
        }
    });

    app(MoveCatalogItem::class)->handle($first, MoveCatalogItem::DOWN, catalogManager());

    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toContain('order by `id`');
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
