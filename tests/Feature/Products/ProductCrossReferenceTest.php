<?php

use App\Enums\AttributeRole;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
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
