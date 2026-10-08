<?php

use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Collection;

/**
 * @return Collection<int, AuditLog>
 */
function productStatusAuditRows(): Collection
{
    return AuditLog::query()
        ->whereIn('action', [AuditAction::ProductDeactivated->value, AuditAction::ProductActivated->value])
        ->orderBy('id')
        ->get();
}

it('E-25 (status) deactivates and then reactivates a product, auditing the status before and after', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsDeactivate);
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create();

    $this->actingAs($actor)->postJson("/products/{$product->id}/deactivate")->assertRedirect();

    expect($product->fresh()->status)->toBe(CatalogStatus::Inactive);

    $this->actingAs($actor)->postJson("/products/{$product->id}/activate")->assertRedirect();

    expect($product->fresh()->status)->toBe(CatalogStatus::Active);

    [$deactivated, $activated] = productStatusAuditRows()->all();

    expect(productStatusAuditRows())->toHaveCount(2)
        ->and($deactivated->action)->toBe(AuditAction::ProductDeactivated->value)
        ->and($deactivated->actor_id)->toBe($actor->id)
        ->and($deactivated->entity_id)->toBe($product->id)
        ->and($deactivated->old_values)->toBe(['status' => 'active'])
        ->and($deactivated->new_values)->toBe(['status' => 'inactive'])
        ->and($activated->action)->toBe(AuditAction::ProductActivated->value)
        ->and($activated->old_values)->toBe(['status' => 'inactive'])
        ->and($activated->new_values)->toBe(['status' => 'active']);
});

it('E-25 (status) leaves the other products untouched', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsDeactivate);
    $category = ProductCategory::factory()->create();
    $target = Product::factory()->for($category, 'category')->create();
    $other = Product::factory()->for($category, 'category')->create();

    $this->actingAs($actor)->postJson("/products/{$target->id}/deactivate")->assertRedirect();

    expect($target->fresh()->status)->toBe(CatalogStatus::Inactive)
        ->and($other->fresh()->status)->toBe(CatalogStatus::Active);
});

it('E-26 denies deactivating and reactivating without products.deactivate, leaving the status unchanged and auditing the denial', function (string $action, CatalogStatus $initial) {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDelete, PermissionName::ProductsCatalog);
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create(['status' => $initial]);

    $this->actingAs($actor)->postJson("/products/{$product->id}/{$action}")->assertForbidden();

    expect($product->fresh()->status)->toBe($initial)
        ->and(productStatusAuditRows())->toHaveCount(0);

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toBe("products.{$action}");
})->with([
    'deactivate' => ['deactivate', CatalogStatus::Active],
    'activate' => ['activate', CatalogStatus::Inactive],
]);

it('PRD-013 no-op: repeating the state writes no change and no audit', function (string $action, CatalogStatus $state) {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsDeactivate);
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create(['status' => $state]);
    $updatedAt = $product->fresh()->updated_at;

    $this->travel(5)->minutes();
    $this->actingAs($actor)->postJson("/products/{$product->id}/{$action}")->assertRedirect();

    expect($product->fresh()->status)->toBe($state)
        ->and($product->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(productStatusAuditRows())->toHaveCount(0);
})->with([
    'activate an active product' => ['activate', CatalogStatus::Active],
    'deactivate an inactive product' => ['deactivate', CatalogStatus::Inactive],
]);

it('PRD-013 returns 404 for an unknown product', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsDeactivate);

    $this->actingAs($actor)->postJson('/products/999999/deactivate')->assertNotFound();
});
