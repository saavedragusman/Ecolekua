<?php

use App\Enums\AttributeRole;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Products\CatalogUsage;
use Illuminate\Database\Eloquent\Collection;

function xrefEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

function xrefCreator(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate);
}

/**
 * A product with one axis (sleeve, two values) and no order attributes, enough to create
 * combinations over HTTP. Fictitious data only.
 *
 * @return array{product: Product, attribute: CatalogAttribute, short: AttributeValue, long: AttributeValue}
 */
function xrefProduct(string $name = 'Mono escolar'): array
{
    $attribute = CatalogAttribute::factory()->create(['name' => "Manga {$name}"]);
    $short = AttributeValue::factory()->for($attribute, 'catalogAttribute')->create(['name' => 'Manga corta', 'sort_order' => 1]);
    $long = AttributeValue::factory()->for($attribute, 'catalogAttribute')->create(['name' => 'Manga larga', 'sort_order' => 2]);

    $product = Product::factory()->create(['name' => $name]);
    $row = ProductAttribute::factory()->create([
        'product_id' => $product->id,
        'catalog_attribute_id' => $attribute->id,
        'role' => AttributeRole::Axis,
        'sort_order' => 1,
    ]);
    $row->allowedValues()->attach([$short->id, $long->id]);

    return ['product' => $product, 'attribute' => $attribute, 'short' => $short, 'long' => $long];
}

/**
 * `POST /products/{id}/combinations` payload over the sleeve axis of `xrefProduct()`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function xrefCombinationPayload(array $fixture, string $code, array $overrides = []): array
{
    return array_merge([
        'code' => $code,
        'axes' => [$fixture['attribute']->id => [$fixture['short']->id]],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function xrefProductPayload(Product $product, array $overrides = []): array
{
    return array_merge([
        'name' => $product->name,
        'product_category_id' => $product->product_category_id,
        'business_line' => $product->business_line->value,
        'supply_mode' => $product->supply_mode->value,
    ], $overrides);
}

/**
 * @return Collection<int, AuditLog>
 */
function xrefAudit(AuditAction ...$actions): Collection
{
    return AuditLog::query()
        ->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))
        ->orderBy('id')
        ->get();
}

// --- Included customizations of a combination (PRD-008, DEC-PRD-47, E-67) ---------------------

it('E-67 (save) accepts combination 139-1 with vinyl included although the mono escolar admits no vinyl as an extra', function () {
    $fixture = xrefProduct('Mono escolar');
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertRedirect();

    $combination = Combination::query()->sole();
    $audit = xrefAudit(AuditAction::CombinationCreated)->sole();

    expect($combination->customizations()->pluck('products.id')->all())->toBe([$vinyl->id])
        ->and($fixture['product']->customizations()->count())->toBe(0)
        ->and($audit->new_values['included_customizations'])->toBe(['Vinil']);
});

it('PRD-008 stores no included customization when none is sent and audits an empty list', function () {
    $fixture = xrefProduct();

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1'))
        ->assertRedirect();

    expect(Combination::query()->sole()->customizations()->count())->toBe(0)
        ->and(xrefAudit(AuditAction::CombinationCreated)->sole()->new_values['included_customizations'])->toBe([]);
});

it('PRD-008 restricts included customizations to service products', function (string $case) {
    $fixture = xrefProduct();
    $id = match ($case) {
        'not a service' => Product::factory()->create()->id,
        'unknown' => 999999,
    };

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['included_customization_ids.0']);

    expect(Combination::query()->count())->toBe(0)
        ->and(CatalogCode::query()->count())->toBe(0);
})->with(['not a service', 'unknown']);

it('PRD-008 rejects malformed included customization payloads', function () {
    $fixture = xrefProduct();

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => 'vinyl']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['included_customization_ids']);
});

it('PRD-008 edits the included customizations of a combination and audits the previous and new name lists', function () {
    $fixture = xrefProduct();
    [$vinyl, $embroidery] = [Product::factory()->service()->create(['name' => 'Vinil']), Product::factory()->service()->create(['name' => 'Bordado pequeño'])];

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertRedirect();

    $combination = Combination::query()->sole();

    $this->actingAs(xrefEditor())
        ->putJson("/products/{$fixture['product']->id}/combinations/{$combination->id}", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$embroidery->id]]))
        ->assertRedirect();

    $audit = xrefAudit(AuditAction::CombinationUpdated)->sole();

    expect($combination->customizations()->pluck('products.name')->all())->toBe(['Bordado pequeño'])
        ->and($audit->old_values)->toBe(['included_customizations' => ['Vinil']])
        ->and($audit->new_values)->toBe(['included_customizations' => ['Bordado pequeño']]);
});

it('PRD-008 keeps the included customizations of a combination when an edit does not send them and clears them with an empty list', function () {
    $fixture = xrefProduct();
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertRedirect();

    $combination = Combination::query()->sole();
    $url = "/products/{$fixture['product']->id}/combinations/{$combination->id}";

    $this->actingAs(xrefEditor())
        ->putJson($url, xrefCombinationPayload($fixture, '139-1', ['description' => 'Con vinil']))
        ->assertRedirect();

    expect($combination->customizations()->count())->toBe(1)
        ->and(xrefAudit(AuditAction::CombinationUpdated)->sole()->new_values)->toBe(['description' => 'Con vinil']);

    $this->actingAs(xrefEditor())
        ->putJson($url, xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => []]))
        ->assertRedirect();

    expect($combination->customizations()->count())->toBe(0)
        ->and(xrefAudit(AuditAction::CombinationUpdated)->last()->old_values)->toBe(['included_customizations' => ['Vinil']]);
});

it('PRD-008 rejects a non-service included customization on edit and changes nothing', function () {
    $fixture = xrefProduct();
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertRedirect();

    $combination = Combination::query()->sole();
    $plain = Product::factory()->create();

    $this->actingAs(xrefEditor())
        ->putJson("/products/{$fixture['product']->id}/combinations/{$combination->id}", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$plain->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['included_customization_ids.0']);

    expect($combination->customizations()->pluck('products.id')->all())->toBe([$vinyl->id])
        ->and(xrefAudit(AuditAction::CombinationUpdated))->toHaveCount(0);
});

it('DEC-PRD-56 rejects adding an inactive service as included customization on create and saves nothing', function () {
    $fixture = xrefProduct();
    $inactive = Product::factory()->service()->inactive()->create(['name' => 'Vinil']);

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$inactive->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['included_customization_ids.0']);

    expect(Combination::query()->count())->toBe(0)
        ->and(CatalogCode::query()->count())->toBe(0);
});

it('DEC-PRD-56 keeps an included customization whose service was deactivated later when the edit resubmits it', function () {
    $fixture = xrefProduct();
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertRedirect();

    $vinyl->forceFill(['status' => 'inactive'])->save();
    $combination = Combination::query()->sole();

    $this->actingAs(xrefEditor())
        ->putJson("/products/{$fixture['product']->id}/combinations/{$combination->id}", xrefCombinationPayload($fixture, '139-1', ['description' => 'Con vinil', 'included_customization_ids' => [$vinyl->id]]))
        ->assertRedirect();

    expect($combination->customizations()->pluck('products.id')->all())->toBe([$vinyl->id]);
});

it('DEC-PRD-56 rejects adding a newly inactive service as included customization on edit and changes nothing', function () {
    $fixture = xrefProduct();
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);
    $inactive = Product::factory()->service()->inactive()->create(['name' => 'Bordado grande']);

    $this->actingAs(xrefCreator())
        ->postJson("/products/{$fixture['product']->id}/combinations", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertRedirect();

    $combination = Combination::query()->sole();

    $this->actingAs(xrefEditor())
        ->putJson("/products/{$fixture['product']->id}/combinations/{$combination->id}", xrefCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$vinyl->id, $inactive->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['included_customization_ids.1']);

    expect($combination->customizations()->pluck('products.id')->all())->toBe([$vinyl->id])
        ->and(xrefAudit(AuditAction::CombinationUpdated))->toHaveCount(0);
});

// --- Leaving mode service (DEC-PRD-52, E-70) ---------------------------------------------------

it('E-70 (leaving service) rejects taking "Bordado pequeño" out of mode service while a product admits it and a combination includes it', function () {
    $service = Product::factory()->service()->create(['name' => 'Bordado pequeño']);
    $shirt = Product::factory()->create(['name' => 'Camisa corporativa']);
    $shirt->customizations()->attach($service->id);

    $fixture = xrefProduct('Mono escolar');
    $combination = Combination::factory()->for($fixture['product'])->create();
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => '158-4']);
    $combination->customizations()->attach($service->id);

    $response = $this->actingAs(xrefEditor())
        ->putJson("/products/{$service->id}", xrefProductPayload($service, ['supply_mode' => 'on_demand']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['supply_mode']);

    $message = $response->json('errors.supply_mode.0');

    expect($message)->toContain('«Camisa corporativa»')
        ->and($message)->toContain('«158-4 (Mono escolar)»')
        ->and($service->fresh()->supply_mode->value)->toBe('service')
        ->and($shirt->customizations()->count())->toBe(1)
        ->and($combination->customizations()->count())->toBe(1)
        ->and(xrefAudit(AuditAction::ProductUpdated))->toHaveCount(0);
});

it('E-70 (leaving service) names only the admitting products when no combination includes it and only the combinations in the opposite case', function () {
    $service = Product::factory()->service()->create(['name' => 'Bordado pequeño']);
    $shirt = Product::factory()->create(['name' => 'Camisa corporativa']);
    $shirt->customizations()->attach($service->id);

    $message = $this->actingAs(xrefEditor())
        ->putJson("/products/{$service->id}", xrefProductPayload($service, ['supply_mode' => 'stock_depletable']))
        ->assertUnprocessable()
        ->json('errors.supply_mode.0');

    expect($message)->toContain('«Camisa corporativa»')
        ->and($message)->not->toContain('combinaciones');

    $shirt->customizations()->detach($service->id);

    $fixture = xrefProduct('Mono escolar');
    $combination = Combination::factory()->for($fixture['product'])->create();
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => '139-1']);
    $combination->customizations()->attach($service->id);

    $message = $this->actingAs(xrefEditor())
        ->putJson("/products/{$service->id}", xrefProductPayload($service, ['supply_mode' => 'stock_depletable']))
        ->assertUnprocessable()
        ->json('errors.supply_mode.0');

    expect($message)->toContain('«139-1 (Mono escolar)»')
        ->and($message)->not->toContain('«Camisa corporativa»');
});

it('E-70 (leaving service) lets an unreferenced service change mode and a referenced one keep mode service while editing other fields', function () {
    $service = Product::factory()->service()->create(['name' => 'Bordado pequeño']);

    $this->actingAs(xrefEditor())
        ->putJson("/products/{$service->id}", xrefProductPayload($service, ['description' => 'Se bordan hasta 8 cm', 'supply_mode' => 'service']))
        ->assertRedirect();

    expect($service->fresh()->description)->toBe('Se bordan hasta 8 cm');

    $shirt = Product::factory()->create(['name' => 'Camisa corporativa']);
    $shirt->customizations()->attach($service->id);

    $this->actingAs(xrefEditor())
        ->putJson("/products/{$service->id}", xrefProductPayload($service, ['description' => 'Nueva nota', 'supply_mode' => 'service']))
        ->assertRedirect();

    expect($service->fresh()->description)->toBe('Nueva nota');

    $shirt->customizations()->detach($service->id);

    $this->actingAs(xrefEditor())
        ->putJson("/products/{$service->id}", xrefProductPayload($service, ['supply_mode' => 'on_demand']))
        ->assertRedirect();

    expect($service->fresh()->supply_mode->value)->toBe('on_demand');
});

it('E-70 (leaving service) ignores products that are not services when the lookup runs', function () {
    $product = Product::factory()->create(['name' => 'Camisa corporativa']);
    $other = Product::factory()->service()->create(['name' => 'Vinil']);
    $product->customizations()->attach($other->id);

    // The product is not a service and nothing references it: changing its mode is unaffected.
    $this->actingAs(xrefEditor())
        ->putJson("/products/{$product->id}", xrefProductPayload($product, ['supply_mode' => 'stock_depletable']))
        ->assertRedirect();

    expect($product->fresh()->supply_mode->value)->toBe('stock_depletable');
});

it('DEC-PRD-52 productsUsingService lists admitting products and including combination codes sorted', function () {
    $service = Product::factory()->service()->create(['name' => 'Vinil']);
    $other = Product::factory()->service()->create(['name' => 'Bordado grande']);

    foreach (['Pantalón escolar', 'Camisa corporativa'] as $name) {
        Product::factory()->create(['name' => $name])->customizations()->attach($service->id);
    }

    $fixture = xrefProduct('Mono escolar');

    foreach (['139-1', '119'] as $code) {
        $combination = Combination::factory()->for($fixture['product'])->create();
        CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => $code]);
        $combination->customizations()->attach($service->id);
    }

    $usage = CatalogUsage::productsUsingService($service->id);

    expect($usage['products'])->toBe(['Camisa corporativa', 'Pantalón escolar'])
        ->and($usage['combinations'])->toBe(['119 (Mono escolar)', '139-1 (Mono escolar)'])
        ->and(CatalogUsage::productsUsingService($other->id))->toBe(['products' => [], 'combinations' => []]);
});

it('PRD-007 denies the cross-reference edits to a user without products.update', function () {
    $category = ProductCategory::factory()->create();
    $service = Product::factory()->service()->for($category, 'category')->create(['name' => 'Bordado pequeño']);

    $this->actingAs(userWithPermissions(PermissionName::ProductsView))
        ->putJson("/products/{$service->id}", xrefProductPayload($service, ['supply_mode' => 'on_demand']))
        ->assertForbidden();

    expect($service->fresh()->supply_mode->value)->toBe('service');
});

it('PRD-008 keeps a deactivated product visible in the lookup as long as it is referenced', function () {
    $service = Product::factory()->service()->create(['name' => 'Vinil']);
    $shirt = Product::factory()->create(['name' => 'Camisa corporativa', 'status' => CatalogStatus::Inactive]);
    $shirt->customizations()->attach($service->id);

    expect(CatalogUsage::productsUsingService($service->id)['products'])->toBe(['Camisa corporativa']);
});
