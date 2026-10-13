<?php

use App\Enums\AttributeRole;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\Combination;
use App\Models\DetailLocation;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// E-73 / DEC-PRD-98: a product of the diaper line declares no detail locations nor admitted
// customizations, and its combinations include no customizations. Fictitious data only.

function diaperEditor(): User
{
    return userWithPermissions(
        PermissionName::ProductsView,
        PermissionName::ProductsCreate,
        PermissionName::ProductsUpdate,
    );
}

/**
 * A product with one axis (two values) so combinations can be created over HTTP.
 *
 * @return array{product: Product, attribute: CatalogAttribute, short: AttributeValue, long: AttributeValue}
 */
function diaperFixture(bool $diapers, string $name = 'Producto E-73'): array
{
    $attribute = CatalogAttribute::factory()->create(['name' => "Eje {$name}"]);
    $short = AttributeValue::factory()->for($attribute, 'catalogAttribute')->create(['name' => 'Corto', 'sort_order' => 1]);
    $long = AttributeValue::factory()->for($attribute, 'catalogAttribute')->create(['name' => 'Largo', 'sort_order' => 2]);

    $product = $diapers
        ? Product::factory()->diapers()->create(['name' => $name])
        : Product::factory()->create(['name' => $name]);

    ProductAttribute::factory()->create([
        'product_id' => $product->id,
        'catalog_attribute_id' => $attribute->id,
        'role' => AttributeRole::Axis,
        'sort_order' => 1,
    ])->allowedValues()->attach([$short->id, $long->id]);

    return ['product' => $product, 'attribute' => $attribute, 'short' => $short, 'long' => $long];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function diaperProductPayload(Product $product, array $overrides = []): array
{
    return array_merge([
        'name' => $product->name,
        'product_category_id' => $product->product_category_id,
        'business_line' => $product->business_line->value,
        'supply_mode' => $product->supply_mode->value,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function diaperCombinationPayload(array $fixture, string $code, array $overrides = []): array
{
    return array_merge([
        'code' => $code,
        'axes' => [$fixture['attribute']->id => [$fixture['short']->id]],
    ], $overrides);
}

it('E-73 rejects editing a diaper product with a detail location and changes nothing', function () {
    $fixture = diaperFixture(diapers: true);
    $location = DetailLocation::factory()->create(['name' => 'Pechera']);

    $this->actingAs(diaperEditor())
        ->putJson("/products/{$fixture['product']->id}", diaperProductPayload($fixture['product'], ['detail_location_ids' => [$location->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['detail_location_ids']);

    expect($fixture['product']->detailLocations()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ProductUpdated->value)->count())->toBe(0);
});

it('E-73 rejects editing a diaper product with an admitted customization and changes nothing', function () {
    $fixture = diaperFixture(diapers: true);
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);

    $this->actingAs(diaperEditor())
        ->putJson("/products/{$fixture['product']->id}", diaperProductPayload($fixture['product'], ['customization_ids' => [$vinyl->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customization_ids']);

    expect($fixture['product']->customizations()->count())->toBe(0);
});

it('E-73 lets a diaper product be edited with empty detail and customization lists', function () {
    $fixture = diaperFixture(diapers: true);

    $this->actingAs(diaperEditor())
        ->putJson("/products/{$fixture['product']->id}", diaperProductPayload($fixture['product'], [
            'name' => 'Pañal renombrado',
            'detail_location_ids' => [],
            'customization_ids' => [],
        ]))
        ->assertRedirect();

    expect($fixture['product']->fresh()->name)->toBe('Pañal renombrado');
});

it('E-73 rejects registering a combination of a diaper with an included customization', function () {
    $fixture = diaperFixture(diapers: true);
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);

    $this->actingAs(diaperEditor())
        ->postJson("/products/{$fixture['product']->id}/combinations", diaperCombinationPayload($fixture, 'PAN-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['included_customization_ids']);

    expect(Combination::query()->count())->toBe(0);
});

it('E-73 rejects editing a combination of a diaper with an included customization', function () {
    $fixture = diaperFixture(diapers: true);
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);

    $this->actingAs(diaperEditor())
        ->postJson("/products/{$fixture['product']->id}/combinations", diaperCombinationPayload($fixture, 'PAN-1'))
        ->assertRedirect();

    $combination = Combination::query()->sole();

    $this->actingAs(diaperEditor())
        ->putJson("/products/{$fixture['product']->id}/combinations/{$combination->id}", diaperCombinationPayload($fixture, 'PAN-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['included_customization_ids']);

    expect($combination->customizations()->count())->toBe(0);
});

it('E-73 lets a diaper combination be saved with an empty included list', function () {
    $fixture = diaperFixture(diapers: true);

    $this->actingAs(diaperEditor())
        ->postJson("/products/{$fixture['product']->id}/combinations", diaperCombinationPayload($fixture, 'PAN-1', ['included_customization_ids' => []]))
        ->assertRedirect();

    expect(Combination::query()->count())->toBe(1);
});

it('E-73 rejects moving a uniforms product with details, customizations and included customizations to the diaper line and names them', function () {
    $fixture = diaperFixture(diapers: false, name: 'Mono escolar');
    $location = DetailLocation::factory()->create(['name' => 'Pechera']);
    $vinyl = Product::factory()->service()->create(['name' => 'Vinil']);
    $embroidery = Product::factory()->service()->create(['name' => 'Bordado pequeño']);

    $this->actingAs(diaperEditor())
        ->putJson("/products/{$fixture['product']->id}", diaperProductPayload($fixture['product'], [
            'detail_location_ids' => [$location->id],
            'customization_ids' => [$embroidery->id],
        ]))
        ->assertRedirect();
    $this->actingAs(diaperEditor())
        ->postJson("/products/{$fixture['product']->id}/combinations", diaperCombinationPayload($fixture, '139-1', ['included_customization_ids' => [$vinyl->id]]))
        ->assertRedirect();

    $response = $this->actingAs(diaperEditor())
        ->putJson("/products/{$fixture['product']->id}", diaperProductPayload($fixture['product'], ['business_line' => 'diapers']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['business_line']);

    expect($response->json('errors.business_line.0'))
        ->toContain('«Pechera»')
        ->toContain('«Bordado pequeño»')
        ->toContain('«139-1»')
        ->and($fixture['product']->fresh()->business_line->value)->toBe('uniforms')
        ->and($fixture['product']->detailLocations()->count())->toBe(1)
        ->and($fixture['product']->customizations()->count())->toBe(1);
});

it('E-73 names only what blocks the move to the diaper line', function () {
    $fixture = diaperFixture(diapers: false, name: 'Mono escolar');
    $location = DetailLocation::factory()->create(['name' => 'Pechera']);

    $this->actingAs(diaperEditor())
        ->putJson("/products/{$fixture['product']->id}", diaperProductPayload($fixture['product'], ['detail_location_ids' => [$location->id]]))
        ->assertRedirect();

    $message = $this->actingAs(diaperEditor())
        ->putJson("/products/{$fixture['product']->id}", diaperProductPayload($fixture['product'], ['business_line' => 'diapers']))
        ->assertUnprocessable()
        ->json('errors.business_line.0');

    expect($message)->toContain('«Pechera»')
        ->not->toContain('personalizaciones');
});

it('E-73 moves a product to the diaper line when it has no details or customizations, or when the same edit clears them', function () {
    $clean = diaperFixture(diapers: false, name: 'Pañal limpio');

    $this->actingAs(diaperEditor())
        ->putJson("/products/{$clean['product']->id}", diaperProductPayload($clean['product'], ['business_line' => 'diapers']))
        ->assertRedirect();

    $location = DetailLocation::factory()->create(['name' => 'Pechera']);
    $cleared = diaperFixture(diapers: false, name: 'Pañal con detalle');
    $this->actingAs(diaperEditor())
        ->putJson("/products/{$cleared['product']->id}", diaperProductPayload($cleared['product'], ['detail_location_ids' => [$location->id]]))
        ->assertRedirect();

    $this->actingAs(diaperEditor())
        ->putJson("/products/{$cleared['product']->id}", diaperProductPayload($cleared['product'], ['business_line' => 'diapers', 'detail_location_ids' => []]))
        ->assertRedirect();

    expect($clean['product']->fresh()->business_line->value)->toBe('diapers')
        ->and($cleared['product']->fresh()->business_line->value)->toBe('diapers')
        ->and($cleared['product']->detailLocations()->count())->toBe(0);
});

it('E-73 exposes the business line to the pages so the interface hides the sections of a diaper', function () {
    $fixture = diaperFixture(diapers: true);
    $combination = Combination::factory()->for($fixture['product'])->create();

    $this->actingAs(diaperEditor())->get("/products/{$fixture['product']->id}")
        ->assertInertia(fn (Assert $page) => $page->where('product.business_line', 'diapers'));

    $this->actingAs(diaperEditor())->get("/products/{$fixture['product']->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('product.business_line', 'diapers'));

    $this->actingAs(diaperEditor())->get("/products/{$fixture['product']->id}/combinations/create")
        ->assertInertia(fn (Assert $page) => $page->where('product.business_line', 'diapers'));

    $this->actingAs(diaperEditor())->get("/products/{$fixture['product']->id}/combinations/{$combination->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('product.business_line', 'diapers'));
});
