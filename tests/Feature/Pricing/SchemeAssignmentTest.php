<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Enums\PriceTierKind;
use App\Models\AuditLog;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\Combo;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

// COT-002 (E-04, DEC-COT-22, DEC-COT-23, DEC-COT-52): assigning a price scheme to one or many products.
// The pricing pages ship later, so the write endpoints answer a redirect back (003 precedent).

function assignmentManager(): User
{
    return userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);
}

/**
 * A stored tiered scheme (Detal 1-6, Especial 7+).
 */
function assignmentScheme(string $name): PriceScheme
{
    $scheme = PriceScheme::factory()->create(['name' => $name]);
    $scheme->tiers()->create(['label' => 'Detal', 'min_quantity' => 1, 'max_quantity' => 6, 'kind' => PriceTierKind::Priced, 'sort_order' => 1]);
    $scheme->tiers()->create(['label' => 'Especial', 'min_quantity' => 7, 'max_quantity' => null, 'kind' => PriceTierKind::Priced, 'sort_order' => 2]);

    return $scheme->fresh();
}

/**
 * Registers a price in a tier for a fresh combination (with its catalog code) of the product.
 */
function assignmentPrice(PriceTier $tier, string $code, string $amount, ?Product $product = null): CombinationPrice
{
    $product ??= assignableProduct($tier->scheme);
    $combination = Combination::factory()->create(['product_id' => $product->id]);
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => $code]);

    return CombinationPrice::factory()->create([
        'combination_id' => $combination->id,
        'price_tier_id' => $tier->id,
        'unit_price' => $amount,
    ]);
}

/**
 * A product in a fresh category, optionally already on a scheme.
 *
 * @param  array<string, mixed>  $attributes
 */
function assignableProduct(?PriceScheme $scheme = null, array $attributes = []): Product
{
    /** @var ProductFactory $factory */
    $factory = Product::factory();

    return $factory->for(ProductCategory::factory(), 'category')->create([...$attributes, 'price_scheme_id' => $scheme?->id]);
}

/**
 * @return Collection<int, AuditLog>
 */
function assignmentAuditRows(): Collection
{
    return AuditLog::query()->where('action', AuditAction::ProductSchemeAssigned->value)->orderBy('id')->get();
}

it('E-04 assigns Uniformes to three products without a scheme in one request and audits each one', function () {
    $actor = assignmentManager();
    $uniformes = assignmentScheme('Uniformes');
    $products = collect([assignableProduct(), assignableProduct(), assignableProduct()]);

    $this->actingAs($actor)->putJson('/pricing/products/scheme', [
        'product_ids' => $products->pluck('id')->all(),
        'price_scheme_id' => $uniformes->id,
    ])->assertRedirect();

    expect(Product::query()->whereKey($products->pluck('id'))->pluck('price_scheme_id')->unique()->all())->toBe([$uniformes->id]);

    $rows = assignmentAuditRows();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('entity_id')->all())->toBe($products->pluck('id')->all())
        ->and($rows->pluck('entity_type')->unique()->all())->toBe([$products->first()->getMorphClass()])
        ->and($rows->pluck('actor_id')->unique()->all())->toBe([$actor->id]);

    foreach ($rows as $row) {
        expect($row->old_values)->toBe(['scheme' => null])
            ->and($row->new_values)->toBe(['scheme' => 'Uniformes']);
    }
});

it('E-04 skips a product whose scheme does not change without writing or auditing it', function () {
    $uniformes = assignmentScheme('Uniformes');
    $same = assignableProduct($uniformes);
    $other = assignableProduct();
    $updatedAt = $same->fresh()->updated_at;

    $this->travel(5)->minutes();
    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$same->id, $other->id],
        'price_scheme_id' => $uniformes->id,
    ])->assertRedirect();

    expect($same->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($other->fresh()->price_scheme_id)->toBe($uniformes->id)
        ->and(assignmentAuditRows()->pluck('entity_id')->all())->toBe([$other->id]);
});

it('E-04 changes nothing and audits nothing when every product already has the scheme', function () {
    $uniformes = assignmentScheme('Uniformes');
    $product = assignableProduct($uniformes);
    $price = assignmentPrice($uniformes->tiers->first(), '110-1', '40.00', $product);

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$product->id],
        'price_scheme_id' => $uniformes->id,
    ])->assertRedirect();

    expect($price->fresh())->not->toBeNull()
        ->and(assignmentAuditRows())->toHaveCount(0);
});

it('DEC-COT-22 rejects an inactive scheme and changes nothing', function () {
    $inactive = PriceScheme::factory()->inactive()->create(['name' => 'Vieja']);
    $product = assignableProduct();

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$product->id],
        'price_scheme_id' => $inactive->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['price_scheme_id']);

    expect($product->fresh()->price_scheme_id)->toBeNull()
        ->and(assignmentAuditRows())->toHaveCount(0);
});

it('DEC-COT-23 asks for confirmation before replacing the scheme of a product with prices and changes nothing without it', function () {
    $uniformes = assignmentScheme('Uniformes');
    $pañales = assignmentScheme('Pañales de adulto');
    $priced = assignableProduct($uniformes);
    assignmentPrice($uniformes->tiers->first(), '110-1', '40.00', $priced);
    assignmentPrice($uniformes->tiers->last(), '110-2', '32.00', $priced);
    $free = assignableProduct();

    $response = $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$free->id, $priced->id],
        'price_scheme_id' => $pañales->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['confirm_price_removal']);

    expect($response->json('errors.confirm_price_removal.0'))->toContain('2 precios')->toContain('1 producto')
        ->and($priced->fresh()->price_scheme_id)->toBe($uniformes->id)
        ->and($free->fresh()->price_scheme_id)->toBeNull()
        ->and(CombinationPrice::query()->count())->toBe(2)
        ->and(assignmentAuditRows())->toHaveCount(0);
});

it('DEC-COT-23 deletes the prices of every combination and group on confirmation and lists them in the audit', function () {
    $uniformes = assignmentScheme('Uniformes');
    $pañales = assignmentScheme('Pañales de adulto');
    [$detal, $especial] = $uniformes->tiers->all();
    $priced = assignableProduct($uniformes);
    assignmentPrice($detal, '110-1', '40.00', $priced);
    assignmentPrice($especial, '110-3', '32.50', $priced);
    assignmentPrice($detal, '110-2', '41.00', $priced);
    $otherProductPrice = assignmentPrice($detal, '120-1', '9.00');

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$priced->id],
        'price_scheme_id' => $pañales->id,
        'confirm_price_removal' => true,
    ])->assertRedirect();

    expect($priced->fresh()->price_scheme_id)->toBe($pañales->id)
        ->and(CombinationPrice::query()->pluck('id')->all())->toBe([$otherProductPrice->id]);

    $audit = assignmentAuditRows()->sole();

    expect($audit->old_values['scheme'])->toBe('Uniformes')
        ->and($audit->new_values)->toBe(['scheme' => 'Pañales de adulto'])
        ->and($audit->old_values['deleted_prices'])->toEqual([
            ['code' => '110-1', 'size_group' => null, 'tier' => 'Detal', 'price' => '40.00'],
            ['code' => '110-3', 'size_group' => null, 'tier' => 'Especial', 'price' => '32.50'],
            ['code' => '110-2', 'size_group' => null, 'tier' => 'Detal', 'price' => '41.00'],
        ])
        ->and($audit->old_values['deleted_prices'][0]['price'])->toBeMoneyString();
});

it('DEC-COT-23 does not ask for confirmation when the product being reassigned has no prices', function () {
    $uniformes = assignmentScheme('Uniformes');
    $pañales = assignmentScheme('Pañales de adulto');
    $product = assignableProduct($uniformes);

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$product->id],
        'price_scheme_id' => $pañales->id,
    ])->assertRedirect();

    $audit = assignmentAuditRows()->sole();

    expect($product->fresh()->price_scheme_id)->toBe($pañales->id)
        ->and($audit->old_values)->toBe(['scheme' => 'Uniformes']);
});

it('DEC-COT-23 keeps the prices of a product that is skipped because its scheme does not change', function () {
    $uniformes = assignmentScheme('Uniformes');
    $pañales = assignmentScheme('Pañales de adulto');
    $kept = assignableProduct($pañales);
    $price = assignmentPrice($pañales->tiers->first(), '110-1', '40.00', $kept);
    $moving = assignableProduct($uniformes);

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$kept->id, $moving->id],
        'price_scheme_id' => $pañales->id,
    ])->assertRedirect();

    expect($price->fresh())->not->toBeNull();
});

it('E-04 rejects an id that is not a product, so a combo cannot be assigned, and changes nothing', function () {
    $uniformes = assignmentScheme('Uniformes');
    $product = assignableProduct();
    // An id no product can have, as a combo id the form could send by mistake.
    $combo = Combo::factory()->make()->forceFill(['id' => 900000]);
    $combo->save();

    expect(Product::query()->whereKey($combo->id)->exists())->toBeFalse();

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$product->id, $combo->id],
        'price_scheme_id' => $uniformes->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['product_ids.1']);

    expect($product->fresh()->price_scheme_id)->toBeNull()
        ->and(assignmentAuditRows())->toHaveCount(0)
        ->and(array_key_exists('price_scheme_id', $combo->getAttributes()))->toBeFalse();
});

it('E-04 rejects malformed input', function (array $payload, string $error) {
    $uniformes = assignmentScheme('Uniformes');
    $product = assignableProduct();

    $payload = array_map(fn (mixed $value): mixed => match ($value) {
        '{product}' => $product->id,
        '{scheme}' => $uniformes->id,
        default => $value,
    }, $payload);

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);

    expect($product->fresh()->price_scheme_id)->toBeNull();
})->with([
    'no products' => [['product_ids' => [], 'price_scheme_id' => '{scheme}'], 'product_ids'],
    'products missing' => [['price_scheme_id' => '{scheme}'], 'product_ids'],
    'unknown product' => [['product_ids' => [999999], 'price_scheme_id' => '{scheme}'], 'product_ids.0'],
    'scheme missing' => [['product_ids' => ['{product}']], 'price_scheme_id'],
    'unknown scheme' => [['product_ids' => ['{product}'], 'price_scheme_id' => 999999], 'price_scheme_id'],
    'not a list of ids' => [['product_ids' => 'abc', 'price_scheme_id' => '{scheme}'], 'product_ids'],
]);

it('DEC-COT-52 lets a user with pricing.manage and without products.view assign a scheme', function () {
    $actor = userWithPermissions(PermissionName::PricingManage);
    $uniformes = assignmentScheme('Uniformes');
    $product = assignableProduct();

    expect($actor->hasPermission(PermissionName::ProductsView))->toBeFalse();

    $this->actingAs($actor)->putJson('/pricing/products/scheme', [
        'product_ids' => [$product->id],
        'price_scheme_id' => $uniformes->id,
    ])->assertRedirect();

    expect($product->fresh()->price_scheme_id)->toBe($uniformes->id);
});

it('E-04 the single-product endpoint does the same as the bulk one for one id', function () {
    $actor = assignmentManager();
    $uniformes = assignmentScheme('Uniformes');
    $pañales = assignmentScheme('Pañales de adulto');
    $viaBulk = assignableProduct($pañales);
    $viaSingle = assignableProduct($pañales);

    $this->actingAs($actor)->putJson('/pricing/products/scheme', ['product_ids' => [$viaBulk->id], 'price_scheme_id' => $uniformes->id])->assertRedirect();
    $this->actingAs($actor)->putJson("/pricing/products/{$viaSingle->id}/scheme", ['price_scheme_id' => $uniformes->id])->assertRedirect();

    $rows = assignmentAuditRows();

    expect($viaSingle->fresh()->price_scheme_id)->toBe($viaBulk->fresh()->price_scheme_id)
        ->and($rows)->toHaveCount(2)
        ->and($rows[1]->old_values)->toBe($rows[0]->old_values)
        ->and($rows[1]->new_values)->toBe($rows[0]->new_values)
        ->and($rows[1]->entity_id)->toBe($viaSingle->id);
});

it('E-04 the single-product endpoint also asks for confirmation and rejects an inactive scheme', function () {
    $uniformes = assignmentScheme('Uniformes');
    $pañales = assignmentScheme('Pañales de adulto');
    $inactive = PriceScheme::factory()->inactive()->create();
    $product = assignableProduct($uniformes);
    assignmentPrice($uniformes->tiers->first(), '110-1', '40.00', $product);
    $actor = assignmentManager();

    $this->actingAs($actor)->putJson("/pricing/products/{$product->id}/scheme", ['price_scheme_id' => $pañales->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['confirm_price_removal']);
    $this->actingAs($actor)->putJson("/pricing/products/{$product->id}/scheme", ['price_scheme_id' => $inactive->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['price_scheme_id']);

    expect($product->fresh()->price_scheme_id)->toBe($uniformes->id)
        ->and(CombinationPrice::query()->count())->toBe(1);
});

it('E-04 returns 404 for a single-product route with an unknown product', function () {
    $uniformes = assignmentScheme('Uniformes');

    $this->actingAs(assignmentManager())->putJson('/pricing/products/999999/scheme', ['price_scheme_id' => $uniformes->id])->assertNotFound();
});

it('E-04 rolls everything back when a later product cannot be assigned', function () {
    $uniformes = assignmentScheme('Uniformes');
    $first = assignableProduct();

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$first->id, 999999],
        'price_scheme_id' => $uniformes->id,
    ])->assertUnprocessable();

    expect($first->fresh()->price_scheme_id)->toBeNull()
        ->and(assignmentAuditRows())->toHaveCount(0);
});

it('COT-022 denies assignment without pricing.manage, changes nothing and audits the denial', function (string $uri) {
    $actor = userWithPermissions(PermissionName::PricingView, PermissionName::ProductsUpdate);
    $uniformes = assignmentScheme('Uniformes');
    $product = assignableProduct();
    $uri = str_replace('{product}', (string) $product->id, $uri);

    $this->actingAs($actor)->putJson($uri, ['product_ids' => [$product->id], 'price_scheme_id' => $uniformes->id])->assertForbidden();

    expect($product->fresh()->price_scheme_id)->toBeNull()
        ->and(assignmentAuditRows())->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->actor_id)->toBe($actor->id);
})->with([
    'bulk' => ['/pricing/products/scheme'],
    'single' => ['/pricing/products/{product}/scheme'],
]);

it('E-04 declares the bulk route before the parameterized one so the static segment is not captured', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('pricing.products.scheme.bulk')->uri())->toBe('pricing/products/scheme')
        ->and($routes->getByName('pricing.products.scheme.update')->uri())->toBe('pricing/products/{product}/scheme')
        ->and($routes->match(Request::create('/pricing/products/scheme', 'PUT'))->getName())->toBe('pricing.products.scheme.bulk');
});

it('E-04 processes a product sent twice once, with a single audit row', function () {
    $uniformes = assignmentScheme('Uniformes');
    $product = assignableProduct();

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$product->id, $product->id],
        'price_scheme_id' => $uniformes->id,
    ])->assertRedirect();

    expect($product->fresh()->price_scheme_id)->toBe($uniformes->id)
        ->and(assignmentAuditRows())->toHaveCount(1);
});

it('E-04 leaves the tiers of both schemes untouched when prices are deleted', function () {
    $uniformes = assignmentScheme('Uniformes');
    $pañales = assignmentScheme('Pañales de adulto');
    $product = assignableProduct($uniformes);
    assignmentPrice($uniformes->tiers->first(), '110-1', '40.00', $product);

    $this->actingAs(assignmentManager())->putJson('/pricing/products/scheme', [
        'product_ids' => [$product->id],
        'price_scheme_id' => $pañales->id,
        'confirm_price_removal' => true,
    ])->assertRedirect();

    expect(PriceTier::query()->where('price_scheme_id', $uniformes->id)->count())->toBe(2)
        ->and(PriceTier::query()->where('price_scheme_id', $pañales->id)->count())->toBe(2);
});
