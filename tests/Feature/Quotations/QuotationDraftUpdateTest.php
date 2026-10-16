<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Enums\PriceTierKind;
use App\Enums\QuotationStatus;
use App\Events\Quotations\QuotationRequiresAdvisor;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\ComboItemPrice;
use App\Models\Customer;
use App\Models\PriceScheme;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

// Saving a draft quotation (COT-013, COT-014, DEC-COT-29, DEC-COT-56, DEC-COT-65, design Decision 19): the
// header and every line are replaced, the draft is saved even with calculation errors and keeps the
// calculation cache, the recipient change keeps the advisor, and only the recipient and advisor changes are
// audited. Fictitious data only.

function updDraftUser(PermissionName ...$extra): User
{
    return userWithPermissions(PermissionName::QuotationsView, PermissionName::QuotationsCreate, ...$extra);
}

/**
 * A product priced at 12,50 on a fixed scheme (combination `FX-1`), optionally with sizes S and M as an order
 * attribute, and a tiered product whose second unit falls in a negotiation tier (combination `NG-1`).
 *
 * @return array<string, mixed>
 */
function updCatalog(): array
{
    $fixed = PriceScheme::factory()->fixed()->create();
    $toalla = Product::factory()->stockDepletable()->create(['name' => 'Toalla', 'price_scheme_id' => $fixed->id]);
    $toallaCombination = Combination::factory()->for($toalla)->create();
    CatalogCode::factory()->create(['combination_id' => $toallaCombination->id, 'code' => 'FX-1']);
    CombinationPrice::factory()->create(['combination_id' => $toallaCombination->id, 'price_tier_id' => $fixed->tiers()->sole()->id, 'unit_price' => '12.50']);

    $size = CatalogAttribute::factory()->size()->create();
    $sizes = [];
    $camiseta = Product::factory()->create(['name' => 'Camiseta', 'price_scheme_id' => $fixed->id]);
    $declared = ProductAttribute::factory()->order()->create(['product_id' => $camiseta->id, 'catalog_attribute_id' => $size->id, 'sort_order' => 1]);

    foreach (['S', 'M'] as $position => $name) {
        $sizes[$name] = AttributeValue::factory()->for($size, 'catalogAttribute')->create(['name' => $name, 'sort_order' => $position + 1])->id;
        $declared->allowedValues()->attach($sizes[$name]);
    }

    $camisetaCombination = Combination::factory()->for($camiseta)->create();
    CatalogCode::factory()->create(['combination_id' => $camisetaCombination->id, 'code' => 'CM-1']);
    CombinationPrice::factory()->create(['combination_id' => $camisetaCombination->id, 'price_tier_id' => $fixed->tiers()->sole()->id, 'unit_price' => '8.00']);

    $tiered = PriceScheme::factory()->create();
    $detal = $tiered->tiers()->create(['label' => 'Detal', 'min_quantity' => 1, 'max_quantity' => 1, 'kind' => PriceTierKind::Priced, 'sort_order' => 1]);
    $tiered->tiers()->create(['label' => 'Negociación', 'min_quantity' => 2, 'max_quantity' => null, 'kind' => PriceTierKind::Negotiation, 'sort_order' => 2]);
    $gorra = Product::factory()->create(['name' => 'Gorra', 'price_scheme_id' => $tiered->id]);
    $gorraCombination = Combination::factory()->for($gorra)->create();
    CatalogCode::factory()->create(['combination_id' => $gorraCombination->id, 'code' => 'NG-1']);
    CombinationPrice::factory()->create(['combination_id' => $gorraCombination->id, 'price_tier_id' => $detal->id, 'unit_price' => '9.00']);

    $sinEsquema = Product::factory()->create(['name' => 'Sin esquema']);
    $sinEsquemaCombination = Combination::factory()->for($sinEsquema)->create();
    CatalogCode::factory()->create(['combination_id' => $sinEsquemaCombination->id, 'code' => 'SE-1']);

    $service = Product::factory()->service()->create(['name' => 'Bordado']);
    $camiseta->customizations()->attach([$service->id]);

    $kit = Combo::factory()->create(['name' => 'Kit toallas']);
    CatalogCode::factory()->forCombo($kit)->create(['code' => 'K-1']);
    $component = ComboComponent::factory()->create(['combo_id' => $kit->id, 'product_id' => $toalla->id, 'quantity' => 2, 'sort_order' => 1]);
    ComboItemPrice::factory()->create(['combo_component_id' => $component->id, 'unit_price' => '10.00']);

    return [
        'toalla' => $toalla, 'camiseta' => $camiseta, 'gorra' => $gorra, 'sinEsquema' => $sinEsquema, 'service' => $service,
        'kit' => $kit, 'component' => $component, 'sizes' => $sizes,
        'combinations' => ['toalla' => $toallaCombination, 'camiseta' => $camisetaCombination, 'gorra' => $gorraCombination, 'sinEsquema' => $sinEsquemaCombination],
    ];
}

/**
 * A product line of `$product` with rows `size name or '' => quantity`.
 *
 * @param  array<string, int>  $rows
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function updLine(array $fx, string $product, array $rows = ['' => 1], array $extra = []): array
{
    return [
        'kind' => 'product',
        'product_id' => $fx[$product]->id,
        'selection' => [],
        'rows' => collect($rows)->map(fn (int $quantity, string $size) => [
            'size_value_id' => $size === '' ? null : $fx['sizes'][$size],
            'quantity' => $quantity,
        ])->values()->all(),
        ...$extra,
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function updKit(array $fx, int $quantity = 1, array $extra = []): array
{
    return ['kind' => 'combo', 'combo_id' => $fx['kit']->id, 'combo_quantity' => $quantity, 'components' => [$fx['component']->id => []], ...$extra];
}

/**
 * The current header of a draft as an update payload, plus `$overrides`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function updPayload(Quotation $quotation, array $overrides = []): array
{
    return [
        'customer_id' => $quotation->customer_id,
        'include_contact' => $quotation->include_contact,
        'prospect_name' => $quotation->prospect_name,
        'prospect_phone' => $quotation->prospect_phone,
        'prospect_email' => $quotation->prospect_email,
        'advisor_id' => $quotation->advisor_id,
        'notes' => $quotation->notes,
        'lines' => [],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function putDraft(User $actor, Quotation $quotation, array $payload): TestResponse
{
    return test()->actingAs($actor)->putJson("/quotations/{$quotation->id}", $payload);
}

/**
 * Audit actions written for quotations, in order.
 *
 * @return list<string>
 */
function quotationAudits(): array
{
    return AuditLog::query()->where('action', 'like', 'quotations.%')->orderBy('id')->pluck('action')->all();
}

it('COT-013 guarda el borrador con errores de cálculo y la caché del último cálculo', function () {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [
        updLine($fx, 'toalla', ['' => 2]),
        updLine($fx, 'sinEsquema', ['' => 3]),
    ]]))->assertRedirect();

    $quotation->refresh();
    $lines = $quotation->lines;

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('position')->all())->toBe([1, 2])
        ->and($lines[0]->combination_id)->toBe($fx['combinations']['toalla']->id)
        ->and($lines[1]->combination_id)->toBe($fx['combinations']['sinEsquema']->id)
        ->and($lines[1]->rows->sole()->quantity)->toBe(3)
        ->and((string) $quotation->total)->toBe('25.00')
        ->and($quotation->requires_advisor)->toBeFalse();
});

it('COT-013 una línea con selección inválida se guarda sin combinación resuelta', function () {
    $fx = updCatalog();
    $fx['camiseta']->forceFill(['status' => 'inactive'])->save();
    $quotation = Quotation::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'camiseta', ['S' => 1])]]))->assertRedirect();

    expect($quotation->fresh()->lines->sole()->combination_id)->toBeNull();
});

it('COT-014 guarda filas por talla, personalizaciones con su cantidad por prenda y la nota', function () {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'camiseta', ['S' => 2, 'M' => 3], [
        'customizations' => [['service_product_id' => $fx['service']->id]],
        'note' => 'Logo en pecho izquierdo',
    ])]]))->assertRedirect();

    $line = $quotation->fresh()->lines->sole();

    expect($line->rows->map(fn ($row) => [$row->size_value_id, $row->quantity])->all())->toBe([[$fx['sizes']['S'], 2], [$fx['sizes']['M'], 3]])
        ->and($line->customizations->sole()->pivot->quantity_per_garment)->toBe(1)
        ->and($line->note)->toBe('Logo en pecho izquierdo')
        ->and((string) $quotation->fresh()->total)->toBe('40.00');
});

it('COT-014 guarda una línea de combo con sus componentes y las combinaciones resueltas', function () {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [updKit($fx, 3)]]))->assertRedirect();

    $line = $quotation->fresh()->lines->sole();
    $component = $line->components->sole();

    expect($line->combo_id)->toBe($fx['kit']->id)
        ->and($line->combo_quantity)->toBe(3)
        ->and($line->combination_id)->toBeNull()
        ->and($component->combo_component_id)->toBe($fx['component']->id)
        ->and($component->combination_id)->toBe($fx['combinations']['toalla']->id)
        ->and($component->position)->toBe(1)
        ->and((string) $quotation->fresh()->total)->toBe('60.00');
});

it('E-35 rechaza filas repetidas, sin filas o con talla mezclada con una fila sin talla', function (array $rows) {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();
    $line = updLine($fx, 'camiseta');
    $line['rows'] = array_map(fn (array $row) => ['size_value_id' => $row[0] === null ? null : $fx['sizes'][$row[0]], 'quantity' => $row[1]], $rows);

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [$line]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.rows']);

    expect($quotation->fresh()->lines)->toHaveCount(0);
})->with([
    'talla repetida' => [[['S', 1], ['S', 2]]],
    'sin filas' => [[]],
    'talla y sin talla' => [[['S', 1], [null, 2]]],
    'dos sin talla' => [[[null, 1], [null, 2]]],
]);

it('E-35 rechaza cantidades menores que uno, notas largas y personalizaciones fuera de 1 a 99', function (string $field, array $line) {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [array_replace_recursive(updLine($fx, 'camiseta', ['S' => 1]), $line)]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'cantidad 0' => ['lines.0.rows.0.quantity', ['rows' => [0 => ['quantity' => 0]]]],
    'nota de 501' => ['lines.0.note', ['note' => str_repeat('a', 501)]],
    'personalización 100' => ['lines.0.customizations.0.quantity_per_garment', ['customizations' => [['service_product_id' => 1, 'quantity_per_garment' => 100]]]],
]);

it('COT-013 las líneas enviadas con id conservan su fila y las que no se envían se eliminan', function () {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();
    $actor = updDraftUser();

    putDraft($actor, $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'toalla'), updLine($fx, 'camiseta', ['S' => 1])]]))->assertRedirect();
    [$first, $second] = $quotation->fresh()->lines->all();

    putDraft($actor, $quotation, updPayload($quotation->fresh(), ['lines' => [
        updLine($fx, 'camiseta', ['M' => 4], ['id' => $second->id]),
        updLine($fx, 'gorra'),
    ]]))->assertRedirect();

    $lines = $quotation->fresh()->lines;

    expect($lines->pluck('id')->first())->toBe($second->id)
        ->and($lines[0]->position)->toBe(1)
        ->and($lines[0]->rows->sole()->quantity)->toBe(4)
        ->and($lines)->toHaveCount(2)
        ->and(QuotationLine::query()->whereKey($first->id)->exists())->toBeFalse();
});

it('COT-013 una misma línea no se puede enviar dos veces', function () {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();
    $actor = updDraftUser();
    putDraft($actor, $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'toalla')]]))->assertRedirect();
    $id = $quotation->fresh()->lines->sole()->id;

    putDraft($actor, $quotation, updPayload($quotation, ['lines' => [
        updLine($fx, 'toalla', extra: ['id' => $id]),
        updLine($fx, 'gorra', extra: ['id' => $id]),
    ]]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.id']);

    expect($quotation->fresh()->lines)->toHaveCount(1);
});

it('Decision 19 la línea de un producto sin tallas lleva una sola fila sin talla', function () {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [
        updLine($fx, 'toalla', ['S' => 1]),
    ]]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.rows']);

    expect($quotation->fresh()->lines)->toHaveCount(0);
});

it('COT-013 si la asesora falla, el cambio de destinatario tampoco se guarda', function () {
    $quotation = Quotation::factory()->prospect()->create();
    $customer = Customer::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, [
        'customer_id' => $customer->id,
        'prospect_name' => null,
        'prospect_phone' => null,
        'prospect_email' => null,
        'advisor_id' => userWithPermissions(PermissionName::QuotationsView)->id,
    ]))->assertStatus(422)->assertJsonValidationErrors(['advisor_id']);

    expect($quotation->fresh()->customer_id)->toBeNull()
        ->and(quotationAudits())->toBe([]);
});

it('COT-013 la búsqueda de destinatarios trata % y _ como texto', function () {
    Customer::factory()->create(['name' => 'Taller Ficticio Sur']);

    $response = test()->actingAs(updDraftUser())->getJson('/quotations/recipients?q=%25');

    expect($response->json('data'))->toBe([]);
});

it('COT-013 una línea de otra cotización se rechaza', function () {
    $fx = updCatalog();
    $foreign = QuotationLine::factory()->create();
    $quotation = Quotation::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'toalla', extra: ['id' => $foreign->id])]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.id']);
});

it('DEC-COT-18 una cotización que no está en borrador no se edita', function () {
    $quotation = Quotation::factory()->issued()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation))
        ->assertStatus(422)
        ->assertJsonPath('message', 'La cotización no está en borrador; duplíquela para cambiarla.');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Issued);
});

it('E-73 cambiar el destinatario conserva la asesora y audita solo el cambio de destinatario', function () {
    $creator = updDraftUser(PermissionName::CustomersPortfolio);
    $quotation = Quotation::factory()->prospect()->create(['advisor_id' => $creator->id, 'created_by' => $creator->id]);
    $other = userWithPermissions(PermissionName::CustomersPortfolio);
    $customer = Customer::factory()->assignedTo($other)->create();

    putDraft($creator, $quotation, updPayload($quotation, [
        'customer_id' => $customer->id,
        'prospect_name' => null,
        'prospect_phone' => null,
        'prospect_email' => null,
    ]))->assertRedirect();

    $quotation->refresh();
    $audit = AuditLog::query()->where('action', AuditAction::QuotationRecipientChanged->value)->sole();

    expect($quotation->customer_id)->toBe($customer->id)
        ->and($quotation->prospect_name)->toBeNull()
        ->and($quotation->advisor_id)->toBe($creator->id)
        ->and(quotationAudits())->toBe([AuditAction::QuotationRecipientChanged->value])
        ->and($audit->old_values)->toMatchArray(['customer_id' => null])
        ->and($audit->new_values)->toMatchArray(['customer_id' => $customer->id]);
});

it('E-73 el cambio de asesora a mano exige una usuaria activa con cartera y se audita', function () {
    $quotation = Quotation::factory()->create(['advisor_id' => userWithPermissions(PermissionName::CustomersPortfolio)->id]);
    $previous = $quotation->advisor_id;
    $actor = updDraftUser();
    $chosen = userWithPermissions(PermissionName::CustomersPortfolio);

    putDraft($actor, $quotation, updPayload($quotation, ['advisor_id' => userWithPermissions(PermissionName::QuotationsView)->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['advisor_id']);

    putDraft($actor, $quotation, updPayload($quotation, ['advisor_id' => $chosen->id]))->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::QuotationAdvisorChanged->value)->sole();

    expect($quotation->fresh()->advisor_id)->toBe($chosen->id)
        ->and($audit->old_values)->toBe(['advisor_id' => $previous])
        ->and($audit->new_values)->toBe(['advisor_id' => $chosen->id]);
});

it('DEC-COT-65 un valor vacío de asesora conserva la actual', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $quotation = Quotation::factory()->create(['advisor_id' => $advisor->id]);

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['advisor_id' => null]))->assertRedirect();

    expect($quotation->fresh()->advisor_id)->toBe($advisor->id)
        ->and(quotationAudits())->toBe([]);
});

it('COT-013 un borrador no se pasa a un cliente inactivo, pero se sigue guardando si su cliente se inactivó', function () {
    $quotation = Quotation::factory()->create();
    $inactive = Customer::factory()->inactive()->create();
    $actor = updDraftUser();

    putDraft($actor, $quotation, updPayload($quotation, ['customer_id' => $inactive->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['customer_id']);

    $quotation->customer->forceFill(['status' => 'inactive'])->save();

    putDraft($actor, $quotation, updPayload($quotation, ['notes' => 'Sigue en borrador']))->assertRedirect();

    expect($quotation->fresh()->notes)->toBe('Sigue en borrador');
});

it('DEC-COT-29 editar líneas no se audita', function () {
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();

    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'toalla')]]))->assertRedirect();
    putDraft(updDraftUser(), $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'toalla', ['' => 5])]]))->assertRedirect();

    expect(quotationAudits())->toBe([]);
});

it('COT-012 avisa que requiere asesora solo cuando la marca pasa de no a sí', function () {
    Event::fake([QuotationRequiresAdvisor::class]);
    $fx = updCatalog();
    $quotation = Quotation::factory()->create();
    $actor = updDraftUser();

    putDraft($actor, $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'gorra')]]))->assertRedirect();
    Event::assertNotDispatched(QuotationRequiresAdvisor::class);

    putDraft($actor, $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'gorra', ['' => 2])]]))->assertRedirect();
    putDraft($actor, $quotation, updPayload($quotation, ['lines' => [updLine($fx, 'gorra', ['' => 3])]]))->assertRedirect();

    expect($quotation->fresh()->requires_advisor)->toBeTrue();
    Event::assertDispatchedTimes(QuotationRequiresAdvisor::class, 1);
});

it('COT-022 sin quotations.create no se edita un borrador y la denegación queda auditada', function () {
    $quotation = Quotation::factory()->create();
    $actor = userWithPermissions(PermissionName::QuotationsView);

    putDraft($actor, $quotation, updPayload($quotation, ['notes' => 'x']))->assertForbidden();

    expect($quotation->fresh()->notes)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->actor_id)->toBe($actor->id);
});

it('COT-013 la búsqueda de destinatarios lista solo clientes activos y filtra por nombre', function () {
    Customer::factory()->create(['name' => 'Clínica Ficticia Norte']);
    Customer::factory()->create(['name' => 'Taller Ficticio Sur']);
    Customer::factory()->inactive()->create(['name' => 'Clínica Ficticia Inactiva']);

    $response = test()->actingAs(updDraftUser())->getJson('/quotations/recipients?q=clínica');

    $response->assertOk();
    expect(array_column($response->json('data'), 'name'))->toBe(['Clínica Ficticia Norte']);
});

it('COT-022 la búsqueda de destinatarios exige quotations.create', function () {
    test()->actingAs(userWithPermissions(PermissionName::QuotationsView))->getJson('/quotations/recipients')->assertForbidden();
});
