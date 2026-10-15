<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\PriceScheme;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;

// COT-009, COT-022, E-20, design Decisions 6 and 14: the calculation and the selection options as JSON
// endpoints of the ERP editor. Authorization tests hit the backend directly (AGENTS.md §10).

/**
 * A product without attributes on a fixed scheme at 12,50 (combination `FX-1`).
 *
 * @return array{product: Product, combination: Combination}
 */
function endpointProduct(): array
{
    $scheme = PriceScheme::factory()->fixed()->create();
    $product = Product::factory()->stockDepletable()->create(['name' => 'Toalla', 'price_scheme_id' => $scheme->id]);
    $combination = Combination::factory()->for($product)->create();
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => 'FX-1']);
    CombinationPrice::factory()->create(['combination_id' => $combination->id, 'price_tier_id' => $scheme->tiers()->sole()->id, 'unit_price' => '12.50']);

    return ['product' => $product, 'combination' => $combination];
}

/**
 * @param  array<string, mixed>  $extra  line fields to merge
 * @return array<string, mixed>
 */
function endpointPayload(Product $product, int $quantity = 2, array $extra = []): array
{
    return ['lines' => [[
        'kind' => 'product',
        'product_id' => $product->id,
        'selection' => [],
        'rows' => [['size_value_id' => null, 'quantity' => $quantity]],
        ...$extra,
    ]]];
}

function postCalculate(User $actor, array $payload): TestResponse
{
    return test()->actingAs($actor)->postJson('/quotations/calculate', $payload);
}

function deniedAudits(): int
{
    return AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->count();
}

it('COT-009 calcula y devuelve los importes como texto con valor y presentación', function () {
    $fx = endpointProduct();

    $response = postCalculate(userWithPermissions(PermissionName::QuotationsCreate), endpointPayload($fx['product']));

    $response->assertOk()
        ->assertJsonPath('lines.0.code', 'FX-1')
        ->assertJsonPath('lines.0.subtotal', ['value' => '25.00', 'display' => '25,00'])
        ->assertJsonPath('total', ['value' => '25.00', 'display' => '25,00'])
        ->assertJsonPath('required_advance.value', '25.00')
        ->assertJsonPath('rate', null)
        ->assertJsonPath('complete', true);
});

it('COT-009 los problemas de negocio vuelven como errores del cálculo, no como 422', function () {
    $fx = endpointProduct();
    $fx['product']->forceFill(['price_scheme_id' => null])->save();

    postCalculate(userWithPermissions(PermissionName::QuotationsCreate), endpointPayload($fx['product']))
        ->assertOk()
        ->assertJsonPath('lines.0.errors', ['lines.0' => 'sin precio'])
        ->assertJsonPath('complete', false);
});

it('COT-009 una estructura mal formada responde 422', function () {
    postCalculate(userWithPermissions(PermissionName::QuotationsCreate), ['lines' => [['kind' => 'otra']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.kind']);
});

it('COT-022 sin quotations.create no se puede calcular y la denegación queda auditada', function () {
    $fx = endpointProduct();
    $actor = userWithPermissions(PermissionName::QuotationsView);

    postCalculate($actor, endpointPayload($fx['product']))->assertForbidden();

    expect(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->actor_id)->toBe($actor->id);
});

it('E-20 enviar un precio negociado sin quotations.negotiate responde 403 auditado', function () {
    $fx = endpointProduct();
    $payload = endpointPayload($fx['product'], extra: ['negotiated' => ['prices' => [['size_group_id' => null, 'price' => '10.00']], 'confirmed' => false]]);

    postCalculate(userWithPermissions(PermissionName::QuotationsCreate), $payload)->assertForbidden();

    expect(deniedAudits())->toBe(1);
});

it('E-20 enviar un anticipo acordado sin quotations.negotiate responde 403 auditado', function () {
    $fx = endpointProduct();

    postCalculate(userWithPermissions(PermissionName::QuotationsCreate), [...endpointPayload($fx['product']), 'agreed_advance' => '5.00'])
        ->assertForbidden();

    expect(deniedAudits())->toBe(1);
});

it('E-20 con quotations.negotiate se puede calcular con precio negociado', function () {
    $fx = endpointProduct();
    $payload = endpointPayload($fx['product'], extra: ['negotiated' => ['prices' => [['size_group_id' => null, 'price' => '10.00']], 'confirmed' => false]]);

    postCalculate(userWithPermissions(PermissionName::QuotationsCreate, PermissionName::QuotationsNegotiate), $payload)->assertOk();

    expect(deniedAudits())->toBe(0);
});

it('PRD-019 las opciones de selección se ofrecen con quotations.create', function () {
    $fx = endpointProduct();

    test()->actingAs(userWithPermissions(PermissionName::QuotationsCreate))
        ->postJson('/quotations/selection-options', ['product_id' => $fx['product']->id, 'axes' => []])
        ->assertOk()
        ->assertJsonPath('code', 'FX-1')
        ->assertJsonPath('combination_id', $fx['combination']->id);
});

it('COT-022 sin quotations.create no se ofrecen opciones de selección', function () {
    $fx = endpointProduct();

    test()->actingAs(userWithPermissions(PermissionName::QuotationsView))
        ->postJson('/quotations/selection-options', ['product_id' => $fx['product']->id])
        ->assertForbidden();

    expect(deniedAudits())->toBe(1);
});

it('PRD-019 una consulta de opciones inválida responde 422 con el campo', function () {
    test()->actingAs(userWithPermissions(PermissionName::QuotationsCreate))
        ->postJson('/quotations/selection-options', ['product_id' => 999_999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product']);
});
