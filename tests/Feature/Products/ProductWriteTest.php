<?php

use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProduct;
use App\Enums\AttributeRole;
use App\Enums\AuditAction;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Enums\SupplyMode;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\DetailLocation;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\StockMinimumOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function productCreator(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate);
}

function productEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

/**
 * Valid `POST /products` payload (an `on_demand` uniform); fictitious data only.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPayload(ProductCategory $category, array $overrides = []): array
{
    return array_merge([
        'name' => 'Camisa corporativa',
        'product_category_id' => $category->id,
        'business_line' => 'uniforms',
        'supply_mode' => 'on_demand',
    ], $overrides);
}

/**
 * @return Collection<int, AuditLog>
 */
function productAuditRows(AuditAction ...$actions): Collection
{
    return AuditLog::query()
        ->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))
        ->orderBy('id')
        ->get();
}

it('E-01 registers a product with valid data as active and audits the created values with the category name', function () {
    $actor = productCreator();
    $category = ProductCategory::factory()->create(['name' => 'Camisas']);

    $response = $this->actingAs($actor)->postJson('/products', productPayload($category, ['description' => '  Camisa de oficina  ']));

    $product = Product::query()->sole();
    $response->assertRedirect("/products/{$product->id}");

    expect($product->name)->toBe('Camisa corporativa')
        ->and($product->status)->toBe(CatalogStatus::Active)
        ->and($product->product_category_id)->toBe($category->id)
        ->and($product->business_line)->toBe(BusinessLine::Uniforms)
        ->and($product->supply_mode)->toBe(SupplyMode::OnDemand)
        ->and($product->description)->toBe('Camisa de oficina')
        ->and($product->portal_visible)->toBeTrue()
        ->and($product->min_stock_default)->toBeNull();

    $audit = productAuditRows(AuditAction::ProductCreated)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($product->getMorphClass())
        ->and($audit->entity_id)->toBe($product->id)
        ->and($audit->old_values)->toBe([])
        ->and($audit->new_values)->toMatchArray([
            'name' => 'Camisa corporativa',
            'product_category_id' => $category->id,
            'category_name' => 'Camisas',
            'business_line' => 'uniforms',
            'supply_mode' => 'on_demand',
            'portal_visible' => true,
            'status' => 'active',
        ]);
});

it('E-01 registers a diaper product of another mode with portal visibility off', function () {
    $category = ProductCategory::factory()->create();

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, [
            'name' => 'Pañal ecológico',
            'business_line' => 'diapers',
            'supply_mode' => 'stock_depletable',
            'portal_visible' => false,
        ]))
        ->assertRedirect();

    $product = Product::query()->sole();

    expect($product->business_line)->toBe(BusinessLine::Diapers)
        ->and($product->supply_mode)->toBe(SupplyMode::StockDepletable)
        ->and($product->portal_visible)->toBeFalse()
        ->and($product->status)->toBe(CatalogStatus::Active);
});

it('E-02 rejects a product without supply mode and creates nothing', function () {
    $category = ProductCategory::factory()->create();
    $payload = productPayload($category);
    unset($payload['supply_mode']);

    $this->actingAs(productCreator())
        ->postJson('/products', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['supply_mode']);

    expect(Product::query()->count())->toBe(0)
        ->and(productAuditRows(AuditAction::ProductCreated))->toHaveCount(0);
});

it('E-02 rejects each missing or invalid required field', function (string $field, mixed $value) {
    $category = ProductCategory::factory()->create();

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, [$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Product::query()->count())->toBe(0);
})->with([
    'blank name' => ['name', '  '],
    'name over 150 characters' => ['name', str_repeat('a', 151)],
    'missing category' => ['product_category_id', null],
    'unknown category' => ['product_category_id', 999_999],
    'unknown business line' => ['business_line', 'tecnologia'],
    'unknown supply mode' => ['supply_mode', 'por_encargo'],
    'description over 5000 characters' => ['description', str_repeat('a', 5001)],
]);

it('E-03 denies registering a product to a user without products.create and audits the denial', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate, PermissionName::ProductsDelete, PermissionName::ProductsCatalog);
    $category = ProductCategory::factory()->create();

    $this->actingAs($actor)->postJson('/products', productPayload($category))->assertForbidden();

    expect(Product::query()->count())->toBe(0)
        ->and(productAuditRows(AuditAction::ProductCreated))->toHaveCount(0);

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toBe('products.store');
});

it('E-03 denies editing a product to a user without products.update', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsDeactivate);
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => 'Otro nombre']))
        ->assertForbidden();

    expect($product->fresh()->name)->toBe('Camisa corporativa')
        ->and(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->context['route'])->toBe('products.update');
});

it('E-64 (product) rejects a name that only differs in letter case', function () {
    $category = ProductCategory::factory()->create();
    Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['name' => 'camisa corporativa']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(Product::query()->count())->toBe(1);
});

it('PRD-003 accepts a different name and an inactive product still reserves its name', function () {
    $category = ProductCategory::factory()->create();
    Product::factory()->for($category, 'category')->inactive()->create(['name' => 'Camisa corporativa']);

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['name' => 'CAMISA CORPORATIVA']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['name' => 'Camisa ejecutiva']))
        ->assertRedirect();

    expect(Product::query()->count())->toBe(2);
});

it('PRD-003 cannot choose an inactive category when registering', function () {
    $inactive = ProductCategory::factory()->inactive()->create();

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($inactive))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product_category_id']);

    expect(Product::query()->count())->toBe(0);
});

it('PRD-003 keeps the category of a product when it was deactivated afterwards but rejects switching to another inactive one', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    $category->forceFill(['status' => CatalogStatus::Inactive])->save();
    $otherInactive = ProductCategory::factory()->inactive()->create();

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['description' => 'Nueva descripción']))
        ->assertRedirect();

    expect($product->fresh()->description)->toBe('Nueva descripción');

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($otherInactive))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product_category_id']);

    expect($product->fresh()->product_category_id)->toBe($category->id);
});

it('E-20 rejects a minimum stock in modes without minimum and accepts 6 in stock_with_minimum', function (string $mode) {
    $category = ProductCategory::factory()->create();

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['supply_mode' => $mode, 'min_stock_default' => 6]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock_default']);

    expect(Product::query()->count())->toBe(0);
})->with(['on_demand', 'stock_depletable', 'service']);

it('E-20 accepts the default minimum 6 in stock_with_minimum and stores it', function () {
    $category = ProductCategory::factory()->create();

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['name' => 'Pañal de adulto', 'supply_mode' => 'stock_with_minimum', 'min_stock_default' => 6]))
        ->assertRedirect();

    $product = Product::query()->sole();

    expect($product->supply_mode)->toBe(SupplyMode::StockWithMinimum)
        ->and($product->min_stock_default)->toBe(6)
        ->and(productAuditRows(AuditAction::ProductCreated)->sole()->new_values)->toMatchArray(['min_stock_default' => 6]);
});

it('E-20 accepts a default minimum of 0 and rejects 0 in other modes', function () {
    $category = ProductCategory::factory()->create();

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['supply_mode' => 'stock_with_minimum', 'min_stock_default' => 0]))
        ->assertRedirect();

    expect(Product::query()->sole()->min_stock_default)->toBe(0);

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['name' => 'Otro', 'supply_mode' => 'on_demand', 'min_stock_default' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock_default']);
});

it('DEC-PRD-46 requires the default minimum in stock_with_minimum and bounds it to 0-9999', function (mixed $minimum) {
    $category = ProductCategory::factory()->create();

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['supply_mode' => 'stock_with_minimum', 'min_stock_default' => $minimum]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock_default']);

    expect(Product::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'negative' => [-1],
    'above the limit' => [10_000],
    'not a number' => ['seis'],
    'decimal' => [2.5],
]);

it('DEC-PRD-34 rejects custom color unless the mode is on_demand', function (string $mode) {
    $category = ProductCategory::factory()->create();
    $extra = $mode === 'stock_with_minimum' ? ['min_stock_default' => 6] : [];

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['supply_mode' => $mode, 'allows_custom_color' => true, ...$extra]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['allows_custom_color']);

    // Declining the option is always accepted and stored as false.
    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['supply_mode' => $mode, 'allows_custom_color' => false, ...$extra]))
        ->assertRedirect();

    expect(Product::query()->sole()->allows_custom_color)->toBeFalse();
})->with(['stock_with_minimum', 'stock_depletable', 'service']);

it('DEC-PRD-34 admits custom color by default in on_demand unless the team disables it', function () {
    $category = ProductCategory::factory()->create();

    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['name' => 'Con color personalizado']))
        ->assertRedirect();
    $this->actingAs(productCreator())
        ->postJson('/products', productPayload($category, ['name' => 'Sin color personalizado', 'allows_custom_color' => false]))
        ->assertRedirect();

    expect(Product::query()->where('name', 'Con color personalizado')->sole()->allows_custom_color)->toBeTrue()
        ->and(Product::query()->where('name', 'Sin color personalizado')->sole()->allows_custom_color)->toBeFalse();
});

it('E-68 (product) saves a new name and description and audits only the changed fields with previous and new values', function () {
    $actor = productEditor();
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa', 'description' => 'Original']);

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => 'Camisa ejecutiva', 'description' => 'Actualizada']))
        ->assertRedirect("/products/{$product->id}");

    $product->refresh();

    expect($product->name)->toBe('Camisa ejecutiva')
        ->and($product->description)->toBe('Actualizada')
        ->and($product->status)->toBe(CatalogStatus::Active);

    $audit = productAuditRows(AuditAction::ProductUpdated)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($product->id)
        ->and($audit->old_values)->toBe(['name' => 'Camisa corporativa', 'description' => 'Original'])
        ->and($audit->new_values)->toBe(['name' => 'Camisa ejecutiva', 'description' => 'Actualizada']);
});

it('E-68 (product) audits the category change with the previous and new category name', function () {
    $camisas = ProductCategory::factory()->create(['name' => 'Camisas']);
    $pantalones = ProductCategory::factory()->create(['name' => 'Pantalones']);
    $product = Product::factory()->for($camisas, 'category')->create();

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($pantalones, ['name' => $product->name]))
        ->assertRedirect();

    $audit = productAuditRows(AuditAction::ProductUpdated)->sole();

    expect($product->fresh()->product_category_id)->toBe($pantalones->id)
        ->and($audit->old_values)->toEqual(['product_category_id' => $camisas->id, 'category_name' => 'Camisas'])
        ->and($audit->new_values)->toEqual(['product_category_id' => $pantalones->id, 'category_name' => 'Pantalones']);
});

it('E-68 (product) writes no audit row when nothing changed', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa', 'description' => null]);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => 'Camisa corporativa']))
        ->assertRedirect("/products/{$product->id}");

    expect(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0);
});

it('E-68 (product) writes no audit row when unchanged numeric fields arrive as strings', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->stockWithMinimum(6)->create(['description' => null]);

    $this->actingAs(productEditor())
        ->put("/products/{$product->id}", productPayload($category, [
            'name' => $product->name,
            'product_category_id' => (string) $category->id,
            'supply_mode' => 'stock_with_minimum',
            'min_stock_default' => '6',
        ]))
        ->assertRedirect("/products/{$product->id}");

    expect(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0)
        ->and($product->fresh()->min_stock_default)->toBe(6)
        ->and($product->fresh()->product_category_id)->toBe($category->id);
});

it('PRD-003 turns a concurrent duplicate name into a name error in both write Actions', function () {
    $actor = productEditor();
    $category = ProductCategory::factory()->create();
    Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Pantalón industrial']);

    expect(fn () => app(CreateProduct::class)->handle(productPayload($category), $actor))
        ->toThrow(ValidationException::class);
    expect(fn () => app(UpdateProduct::class)->handle($product, productPayload($category, ['name' => 'camisa corporativa']), $actor))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('name'));

    expect($product->fresh()->name)->toBe('Pantalón industrial')
        ->and(Product::query()->count())->toBe(2);
});

it('E-68 (product) rejects a duplicate name on edit and changes nothing', function () {
    $category = ProductCategory::factory()->create();
    Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Pantalón industrial', 'description' => 'Original']);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => 'camisa CORPORATIVA', 'description' => 'Cambiada']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($product->fresh()->name)->toBe('Pantalón industrial')
        ->and($product->fresh()->description)->toBe('Original')
        ->and(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0);
});

it('E-64 (product) lets a product keep its own name with a different letter case', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'camisa corporativa']);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => 'Camisa Corporativa']))
        ->assertRedirect();

    expect($product->fresh()->name)->toBe('Camisa Corporativa');
});

it('E-66 (default) clears the default minimum when the mode changes away from stock_with_minimum and audits the previous value', function (string $newMode) {
    $actor = productEditor();
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->stockWithMinimum(6)->create(['name' => 'Pañal de adulto']);

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => 'Pañal de adulto', 'supply_mode' => $newMode]))
        ->assertRedirect();

    $product->refresh();

    expect($product->supply_mode)->toBe(SupplyMode::from($newMode))
        ->and($product->min_stock_default)->toBeNull();

    $audit = productAuditRows(AuditAction::ProductUpdated)->sole();

    expect($audit->old_values)->toMatchArray(['supply_mode' => 'stock_with_minimum', 'min_stock_default' => 6])
        ->and($audit->new_values)->toMatchArray(['supply_mode' => $newMode, 'min_stock_default' => null]);
})->with(['on_demand', 'stock_depletable', 'service']);

it('E-66 (default) rejects keeping a minimum when the new mode does not admit it and changes nothing', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->stockWithMinimum(6)->create();

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'supply_mode' => 'on_demand', 'min_stock_default' => 6]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock_default']);

    expect($product->fresh()->supply_mode)->toBe(SupplyMode::StockWithMinimum)
        ->and($product->fresh()->min_stock_default)->toBe(6);
});

it('E-66 (default) requires a new default minimum when returning to stock_with_minimum', function () {
    $actor = productEditor();
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->stockWithMinimum(6)->create();

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'supply_mode' => 'on_demand']))
        ->assertRedirect();

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'supply_mode' => 'stock_with_minimum']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_stock_default']);

    expect($product->fresh()->supply_mode)->toBe(SupplyMode::OnDemand)
        ->and($product->fresh()->min_stock_default)->toBeNull();

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'supply_mode' => 'stock_with_minimum', 'min_stock_default' => 2]))
        ->assertRedirect();

    expect($product->fresh()->supply_mode)->toBe(SupplyMode::StockWithMinimum)
        ->and($product->fresh()->min_stock_default)->toBe(2);
});

it('PRD-009 audits a change of the default minimum within stock_with_minimum', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->stockWithMinimum(6)->create();

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'supply_mode' => 'stock_with_minimum', 'min_stock_default' => 8]))
        ->assertRedirect();

    $audit = productAuditRows(AuditAction::ProductUpdated)->sole();

    expect($audit->old_values)->toEqual(['min_stock_default' => 6])
        ->and($audit->new_values)->toEqual(['min_stock_default' => 8]);
});

it('E-66 (default) clears custom color when the mode changes away from on_demand and defaults it on again when returning', function () {
    $actor = productEditor();
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->onDemand()->create();

    expect($product->allows_custom_color)->toBeTrue();

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'supply_mode' => 'stock_depletable']))
        ->assertRedirect();

    expect($product->fresh()->allows_custom_color)->toBeFalse();

    $audit = productAuditRows(AuditAction::ProductUpdated)->sole();

    expect($audit->old_values)->toMatchArray(['supply_mode' => 'on_demand', 'allows_custom_color' => true])
        ->and($audit->new_values)->toMatchArray(['supply_mode' => 'stock_depletable', 'allows_custom_color' => false]);

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'supply_mode' => 'on_demand']))
        ->assertRedirect();

    expect($product->fresh()->allows_custom_color)->toBeTrue();
});

it('DEC-PRD-34 keeps a disabled custom color across edits that stay in on_demand and lets the team toggle it', function () {
    $actor = productEditor();
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->onDemand()->create(['allows_custom_color' => false]);

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'description' => 'Con texto']))
        ->assertRedirect();

    expect($product->fresh()->allows_custom_color)->toBeFalse();

    $this->actingAs($actor)
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'allows_custom_color' => true]))
        ->assertRedirect();

    expect($product->fresh()->allows_custom_color)->toBeTrue();
});

it('PRD-003 keeps the description and the portal visibility of a product when an edit does not send them', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['description' => 'Original', 'portal_visible' => false]);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name]))
        ->assertRedirect();

    expect($product->fresh()->description)->toBe('Original')
        ->and($product->fresh()->portal_visible)->toBeFalse()
        ->and(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'description' => null, 'portal_visible' => true]))
        ->assertRedirect();

    expect($product->fresh()->description)->toBeNull()
        ->and($product->fresh()->portal_visible)->toBeTrue();
});

it('PRD-003 does not let an edit change the status of a product', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->inactive()->create(['name' => 'Camisa corporativa']);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['status' => 'active', 'description' => 'Con texto']))
        ->assertRedirect();

    expect($product->fresh()->status)->toBe(CatalogStatus::Inactive);
});

it('PRD-003 returns 404 for an unknown product on edit', function () {
    $category = ProductCategory::factory()->create();

    $this->actingAs(productEditor())
        ->putJson('/products/999999', productPayload($category))
        ->assertNotFound();
});

// --- Details and admitted customizations (PRD-007, PRD-008, design Decision 10) ----------------

/**
 * Names of the detail locations or customizations a product holds, sorted, for readable assertions.
 *
 * @return list<string>
 */
function productRelationNames(Product $product, string $relation): array
{
    $names = $product->fresh()->{$relation}()->pluck('name')->all();
    sort($names);

    return array_values($names);
}

it('PRD-007 replaces the admitted detail locations and audits the previous and new name lists', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    [$pechera, $manga, $cuello] = [
        DetailLocation::factory()->create(['name' => 'Pechera']),
        DetailLocation::factory()->create(['name' => 'Orilla de mangas']),
        DetailLocation::factory()->create(['name' => 'Pie de cuello']),
    ];
    $product->detailLocations()->attach([$pechera->id, $manga->id]);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, [
            'name' => $product->name,
            'detail_location_ids' => [$manga->id, $cuello->id],
        ]))
        ->assertRedirect();

    $audit = productAuditRows(AuditAction::ProductUpdated)->sole();

    expect(productRelationNames($product, 'detailLocations'))->toBe(['Orilla de mangas', 'Pie de cuello'])
        ->and($audit->old_values)->toBe(['detail_locations' => ['Orilla de mangas', 'Pechera']])
        ->and($audit->new_values)->toBe(['detail_locations' => ['Orilla de mangas', 'Pie de cuello']]);
});

it('PRD-007 clears the detail locations with an empty list and keeps them when the field is not sent', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    $pechera = DetailLocation::factory()->create(['name' => 'Pechera']);
    $product->detailLocations()->attach($pechera->id);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'description' => 'Con texto']))
        ->assertRedirect();

    expect(productRelationNames($product, 'detailLocations'))->toBe(['Pechera']);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'detail_location_ids' => []]))
        ->assertRedirect();

    expect(productRelationNames($product, 'detailLocations'))->toBe([])
        ->and(productAuditRows(AuditAction::ProductUpdated)->last()->old_values)->toBe(['detail_locations' => ['Pechera']]);
});

it('PRD-007 rejects adding an inactive or unknown location but accepts resubmitting one that was deactivated afterwards', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    $inactive = DetailLocation::factory()->inactive()->create(['name' => 'Pechera']);
    $active = DetailLocation::factory()->create(['name' => 'Orilla de mangas']);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'detail_location_ids' => [$active->id, $inactive->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['detail_location_ids.1']);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'detail_location_ids' => [999999]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['detail_location_ids.0']);

    expect(productRelationNames($product, 'detailLocations'))->toBe([])
        ->and(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0);

    // The location was admitted while active and deactivated later: the product keeps it.
    $product->detailLocations()->attach($active->id);
    $active->forceFill(['status' => CatalogStatus::Inactive])->save();

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'detail_location_ids' => [$active->id]]))
        ->assertRedirect();

    expect(productRelationNames($product, 'detailLocations'))->toBe(['Orilla de mangas'])
        ->and(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0);
});

it('PRD-007 writes no audit row when the same locations arrive in another order or repeated', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    [$pechera, $manga] = [DetailLocation::factory()->create(), DetailLocation::factory()->create()];
    $product->detailLocations()->attach([$pechera->id, $manga->id]);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, [
            'name' => $product->name,
            'detail_location_ids' => [$manga->id, $pechera->id, $manga->id],
        ]))
        ->assertRedirect();

    expect($product->detailLocations()->count())->toBe(2)
        ->and(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0);
});

it('PRD-008 replaces the admitted customizations with active service products and audits the name lists', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    [$small, $large, $vinyl] = [
        Product::factory()->service()->create(['name' => 'Bordado pequeño']),
        Product::factory()->service()->create(['name' => 'Bordado grande']),
        Product::factory()->service()->create(['name' => 'Vinil']),
    ];
    $product->customizations()->attach([$small->id, $large->id]);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, [
            'name' => $product->name,
            'customization_ids' => [$large->id, $vinyl->id],
        ]))
        ->assertRedirect();

    $audit = productAuditRows(AuditAction::ProductUpdated)->sole();

    expect(productRelationNames($product, 'customizations'))->toBe(['Bordado grande', 'Vinil'])
        ->and($audit->old_values)->toBe(['customizations' => ['Bordado grande', 'Bordado pequeño']])
        ->and($audit->new_values)->toBe(['customizations' => ['Bordado grande', 'Vinil']]);
});

it('PRD-008 rejects a customization that is not a service, is inactive when added, is the product itself or unknown', function (string $case) {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);

    $id = match ($case) {
        'not a service' => Product::factory()->create()->id,
        'inactive' => Product::factory()->service()->inactive()->create()->id,
        'itself' => $product->id,
        'unknown' => 999999,
    };

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'customization_ids' => [$id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customization_ids.0']);

    expect($product->customizations()->count())->toBe(0)
        ->and(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(0);
})->with(['not a service', 'inactive', 'itself', 'unknown']);

it('PRD-008 keeps a customization that was deactivated afterwards when it is resubmitted and clears with an empty list', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    $service = Product::factory()->service()->create(['name' => 'Bordado pequeño']);
    $product->customizations()->attach($service->id);
    $service->forceFill(['status' => CatalogStatus::Inactive])->save();

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'customization_ids' => [$service->id]]))
        ->assertRedirect();

    expect(productRelationNames($product, 'customizations'))->toBe(['Bordado pequeño']);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'customization_ids' => []]))
        ->assertRedirect();

    expect(productRelationNames($product, 'customizations'))->toBe([])
        ->and(productAuditRows(AuditAction::ProductUpdated))->toHaveCount(1);
});

it('PRD-008 rejects malformed relation payloads', function (string $field, mixed $value) {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);

    $this->actingAs(productEditor())
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, $field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'locations not an array' => ['detail_location_ids', 'abc'],
    'customizations not an array' => ['customization_ids', 5],
]);

it('PRD-007 denies editing details to a user without products.update and changes nothing', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->create(['name' => 'Camisa corporativa']);
    $location = DetailLocation::factory()->create();

    $this->actingAs(userWithPermissions(PermissionName::ProductsView))
        ->putJson("/products/{$product->id}", productPayload($category, ['name' => $product->name, 'detail_location_ids' => [$location->id]]))
        ->assertForbidden();

    expect($product->detailLocations()->count())->toBe(0);
});

// --- Form pages (Phase 18, PRD-003, PRD-009, E-03) -------------------------------------------

it('E-03 forbids the create page to a user without products.create', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);

    $this->actingAs($actor)->get('/products/create')->assertForbidden();
});

it('E-03 forbids the edit page to a user without products.update', function () {
    $product = Product::factory()->create();
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate);

    $this->actingAs($actor)->get("/products/{$product->id}/edit")->assertForbidden();
});

it('E-03 redirects a guest from the form pages to the login', function () {
    $product = Product::factory()->create();

    $this->get('/products/create')->assertRedirect('/login');
    $this->get("/products/{$product->id}/edit")->assertRedirect('/login');
});

it('PRD-003 renders the create page with active categories, lines, modes and the proposed minimum of 6', function () {
    ProductCategory::factory()->create(['name' => 'Camisas', 'sort_order' => 2]);
    ProductCategory::factory()->create(['name' => 'Gorras', 'sort_order' => 1]);
    ProductCategory::factory()->inactive()->create(['name' => 'Descontinuadas']);

    $this->actingAs(productCreator())->get('/products/create')->assertInertia(function (Assert $page) {
        $page->component('products/Create')
            ->where('defaults.min_stock_default', 6)
            ->where('options.categories.0.label', 'Gorras')
            ->where('options.categories.1.label', 'Camisas')
            ->has('options.categories', 2)
            ->where('options.lines', [
                ['value' => 'uniforms', 'label' => BusinessLine::Uniforms->label()],
                ['value' => 'diapers', 'label' => BusinessLine::Diapers->label()],
            ])
            ->has('options.modes', 4)
            ->where('options.modes.0.value', 'on_demand');
    });
});

it('PRD-012 renders the edit page with the stored data and keeps an inactive product editable', function () {
    $category = ProductCategory::factory()->create(['name' => 'Camisas']);
    $product = Product::factory()->for($category, 'category')->inactive()->create([
        'name' => 'Camisa corporativa',
        'description' => 'Para oficina',
        'portal_visible' => false,
    ]);

    $this->actingAs(productEditor())->get("/products/{$product->id}/edit")->assertInertia(function (Assert $page) use ($product, $category) {
        $page->component('products/Edit')
            ->where('product.id', $product->id)
            ->where('product.name', 'Camisa corporativa')
            ->where('product.description', 'Para oficina')
            ->where('product.product_category_id', $category->id)
            ->where('product.business_line', 'uniforms')
            ->where('product.supply_mode', 'on_demand')
            ->where('product.min_stock_default', null)
            ->where('product.allows_custom_color', true)
            ->where('product.portal_visible', false)
            ->where('product.status', 'inactive')
            ->where('stock', null);
    });
});

it('PRD-012 offers the own category of the product even when it was deactivated, and no other inactive one', function () {
    $own = ProductCategory::factory()->inactive()->create(['name' => 'Antiguas']);
    ProductCategory::factory()->inactive()->create(['name' => 'Otras antiguas']);
    ProductCategory::factory()->create(['name' => 'Vigentes']);
    $product = Product::factory()->for($own, 'category')->create();

    $this->actingAs(productEditor())->get("/products/{$product->id}/edit")->assertInertia(function (Assert $page) {
        expect(collect($page->toArray()['props']['options']['categories'])->pluck('label')->sort()->values()->all())
            ->toBe(['Antiguas', 'Vigentes']);
    });
});

it('PRD-007 lists the active locations and services to add and keeps the held inactive ones, never the product itself', function () {
    $product = Product::factory()->create(['name' => 'Camisa corporativa']);
    $active = DetailLocation::factory()->create(['name' => 'Pechera']);
    $heldInactive = DetailLocation::factory()->inactive()->create(['name' => 'Orilla de mangas']);
    DetailLocation::factory()->inactive()->create(['name' => 'Cuello']);
    $product->detailLocations()->attach([$heldInactive->id]);

    $service = Product::factory()->service()->create(['name' => 'Bordado pequeño']);
    $heldService = Product::factory()->service()->inactive()->create(['name' => 'Vinil']);
    Product::factory()->service()->inactive()->create(['name' => 'Planchado']);
    Product::factory()->create(['name' => 'Camisa de oficina']);
    $product->customizations()->attach([$heldService->id]);

    $this->actingAs(productEditor())->get("/products/{$product->id}/edit")->assertInertia(function (Assert $page) use ($product, $active, $heldInactive, $service, $heldService) {
        $page->where('product.detail_location_ids', [$heldInactive->id])
            ->where('product.customization_ids', [$heldService->id])
            ->where('options.detail_locations', [
                ['value' => $heldInactive->id, 'label' => 'Orilla de mangas (inactivo)'],
                ['value' => $active->id, 'label' => 'Pechera'],
            ])
            ->where('options.customizations', [
                ['value' => $service->id, 'label' => 'Bordado pequeño'],
                ['value' => $heldService->id, 'label' => 'Vinil (inactivo)'],
            ]);

        expect(collect($page->toArray()['props']['options']['customizations'])->pluck('value')->all())->not->toContain($product->id);
    });
});

it('PRD-009 lists the own minimums of a stock_with_minimum product with sizes limited to each restriction', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->for($category, 'category')->stockWithMinimum(6)->create(['name' => 'Pantalón industrial']);
    $size = CatalogAttribute::factory()->size()->create();
    [$s, $m, $l] = collect(['S', 'M', 'L'])->map(fn (string $name, int $position) => AttributeValue::factory()->for($size, 'catalogAttribute')->create(['name' => $name, 'sort_order' => $position + 1]))->all();
    $declared = ProductAttribute::factory()->create(['product_id' => $product->id, 'catalog_attribute_id' => $size->id, 'role' => AttributeRole::Order, 'sort_order' => 1]);
    $declared->allowedValues()->attach([$s->id, $m->id, $l->id]);

    $free = Combination::factory()->for($product)->create();
    CatalogCode::factory()->create(['combination_id' => $free->id, 'code' => 'P-01']);
    $restricted = Combination::factory()->for($product)->create();
    CatalogCode::factory()->create(['combination_id' => $restricted->id, 'code' => 'P-02']);
    $restricted->values()->attach($s->id, ['catalog_attribute_id' => $size->id]);
    StockMinimumOverride::query()->create(['combination_id' => $free->id, 'size_value_id' => $m->id, 'minimum' => 2]);

    $this->actingAs(productEditor())->get("/products/{$product->id}/edit")->assertInertia(function (Assert $page) use ($free, $restricted, $s, $m, $l) {
        $page->where('product.min_stock_default', 6)
            ->where('stock.size_attribute', 'Talla')
            ->where('stock.combinations.0.id', $free->id)
            ->where('stock.combinations.0.code', 'P-01')
            ->where('stock.combinations.0.sizes', [
                ['value' => $s->id, 'label' => 'S'],
                ['value' => $m->id, 'label' => 'M'],
                ['value' => $l->id, 'label' => 'L'],
            ])
            ->where('stock.combinations.1.id', $restricted->id)
            ->where('stock.combinations.1.sizes', [['value' => $s->id, 'label' => 'S']])
            ->where('stock.overrides', [['combination_id' => $free->id, 'size_value_id' => $m->id, 'minimum' => 2]]);
    });
});

it('PRD-009 offers combinations without sizes when the product does not declare the size attribute', function () {
    $product = Product::factory()->stockWithMinimum(6)->create();
    $combination = Combination::factory()->for($product)->create();
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => 'G-01']);
    StockMinimumOverride::query()->create(['combination_id' => $combination->id, 'size_value_id' => null, 'minimum' => 3]);

    $this->actingAs(productEditor())->get("/products/{$product->id}/edit")->assertInertia(function (Assert $page) use ($combination) {
        $page->where('stock.size_attribute', null)
            ->where('stock.combinations.0.id', $combination->id)
            ->where('stock.combinations.0.sizes', null)
            ->where('stock.overrides', [['combination_id' => $combination->id, 'size_value_id' => null, 'minimum' => 3]]);
    });
});
