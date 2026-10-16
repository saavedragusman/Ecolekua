<?php

use App\Actions\Quotations\CalculateQuotation;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Enums\PriceTierKind;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\Combo;
use App\Models\PriceScheme;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\SizeGroup;
use App\Models\User;
use App\Support\Quotations\Calculation\CalculationInput;
use App\Support\Quotations\DraftInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

// Negotiated prices (COT-012, DEC-COT-10..12, DEC-COT-55, DEC-COT-66, DEC-COT-67, design Decision 17): a user
// with `quotations.negotiate` prices a line that requires an advisor; the price is kept while the line still
// requires an advisor on the same basis and is discarded otherwise. Fictitious catalog and prices only.

/**
 * Uniforms with a negotiation tier from 50 pieces: shirt `110` (sizes S, M; color Azul or custom; Detal 36,00),
 * chemise `155` (sizes S, M; Detal 16,00) and trousers `184` (sizes 28–46; groups 28–36 at 25,00 and 38–44 at
 * 28,00; 46 in no group).
 *
 * @return array<string, mixed>
 */
function negCatalog(): array
{
    $size = CatalogAttribute::factory()->size()->create();
    $color = CatalogAttribute::factory()->color()->create();
    $vals = [];

    foreach (['S', 'M', '28', '30', '32', '34', '36', '38', '40', '42', '44', '46'] as $position => $name) {
        $vals[$name] = AttributeValue::factory()->for($size, 'catalogAttribute')->create(['name' => $name, 'sort_order' => $position + 1]);
    }

    $vals['Azul'] = AttributeValue::factory()->for($color, 'catalogAttribute')->withTone('#1F2A44')->create(['name' => 'Azul']);

    $scheme = PriceScheme::factory()->create(['name' => 'Uniformes negociables']);
    $detal = $scheme->tiers()->create(['label' => 'Detal', 'min_quantity' => 1, 'max_quantity' => 49, 'kind' => PriceTierKind::Priced, 'sort_order' => 1]);
    $scheme->tiers()->create(['label' => '50 o más', 'min_quantity' => 50, 'max_quantity' => null, 'kind' => PriceTierKind::Negotiation, 'sort_order' => 2]);

    $declare = function (Product $product, CatalogAttribute $attribute, array $names, int $order) use ($vals): void {
        $declared = ProductAttribute::factory()->order()->create(['product_id' => $product->id, 'catalog_attribute_id' => $attribute->id, 'sort_order' => $order]);
        $declared->allowedValues()->attach(array_map(fn (string $name): int => $vals[$name]->id, $names));
    };

    $products = [];
    $combinations = [];

    foreach (['camisa' => ['110', '36.00'], 'chemise' => ['155', '16.00'], 'pantalon' => ['184', null]] as $key => [$code, $price]) {
        $products[$key] = Product::factory()->onDemand()->create(['name' => ucfirst($key), 'price_scheme_id' => $scheme->id]);
        $combinations[$key] = Combination::factory()->for($products[$key])->create();
        CatalogCode::factory()->create(['combination_id' => $combinations[$key]->id, 'code' => $code]);

        if ($price !== null) {
            CombinationPrice::factory()->create(['combination_id' => $combinations[$key]->id, 'price_tier_id' => $detal->id, 'unit_price' => $price]);
        }
    }

    $declare($products['camisa'], $size, ['S', 'M'], 1);
    $declare($products['camisa'], $color, ['Azul'], 2);
    $declare($products['chemise'], $size, ['S', 'M'], 1);
    $declare($products['pantalon'], $size, ['28', '30', '32', '34', '36', '38', '40', '42', '44', '46'], 1);

    $groups = [];

    foreach ([['28', '30', '32', '34', '36'], ['38', '40', '42', '44']] as $order => $names) {
        $group = SizeGroup::factory()->create(['combination_id' => $combinations['pantalon']->id, 'sort_order' => $order + 1]);

        foreach ($names as $name) {
            $group->sizes()->attach($vals[$name]->id, ['combination_id' => $combinations['pantalon']->id]);
        }

        $groups[] = $group;
    }

    CombinationPrice::factory()->create(['combination_id' => $combinations['pantalon']->id, 'price_tier_id' => $detal->id, 'size_group_id' => $groups[0]->id, 'unit_price' => '25.00']);
    CombinationPrice::factory()->create(['combination_id' => $combinations['pantalon']->id, 'price_tier_id' => $detal->id, 'size_group_id' => $groups[1]->id, 'unit_price' => '28.00']);

    return ['vals' => $vals, 'color' => $color, 'products' => $products, 'combinations' => $combinations, 'groups' => $groups, 'detal' => $detal];
}

/**
 * @param  array<string, int>  $rows  size name => quantity
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function negLine(array $fx, string $product, array $rows, array $extra = []): array
{
    $selection = $product === 'camisa' ? ['order' => [$fx['color']->id => $fx['vals']['Azul']->id]] : [];

    return [
        'kind' => 'product',
        'product_id' => $fx['products'][$product]->id,
        'selection' => $selection,
        'rows' => collect($rows)->map(fn (int $quantity, string $size) => ['size_value_id' => $fx['vals'][$size]->id, 'quantity' => $quantity])->values()->all(),
        ...$extra,
    ];
}

function negCreator(): User
{
    return userWithPermissions(PermissionName::QuotationsView, PermissionName::QuotationsCreate);
}

function negNegotiator(): User
{
    return userWithPermissions(PermissionName::QuotationsView, PermissionName::QuotationsCreate, PermissionName::QuotationsNegotiate);
}

/**
 * A draft saved through the draft update with these lines.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function negDraft(array $lines): Quotation
{
    $quotation = Quotation::factory()->create();

    test()->actingAs(negCreator())->putJson("/quotations/{$quotation->id}", [
        'customer_id' => $quotation->customer_id,
        'lines' => $lines,
    ])->assertRedirect();

    return $quotation->fresh();
}

/**
 * Saves the draft again with its stored lines (by id) changed by `$change`.
 *
 * @param  callable(list<array<string, mixed>>): list<array<string, mixed>>  $change
 */
function negResave(Quotation $quotation, array $fx, callable $change): TestResponse
{
    return test()->actingAs(negCreator())->putJson("/quotations/{$quotation->id}", [
        'customer_id' => $quotation->customer_id,
        'lines' => $change(DraftInput::from($quotation->fresh())['lines']),
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function negotiate(User $actor, QuotationLine $line, array $payload): TestResponse
{
    return test()->actingAs($actor)->putJson("/quotations/{$line->quotation_id}/lines/{$line->id}/negotiated-price", $payload);
}

/**
 * @return array<string, mixed>
 */
function negCalculate(Quotation $quotation): array
{
    return app(CalculateQuotation::class)->handle(CalculationInput::fromArray(DraftInput::from($quotation->fresh())))->toArray();
}

/**
 * @return list<array{size_group_id: ?int, unit_price: string}>
 */
function negStoredPrices(QuotationLine $line): array
{
    return DB::table('quotation_line_negotiated_prices')->where('quotation_line_id', $line->id)->orderBy('id')->get(['size_group_id', 'unit_price'])
        ->map(fn ($row) => ['size_group_id' => $row->size_group_id === null ? null : (int) $row->size_group_id, 'unit_price' => (string) $row->unit_price])->all();
}

it('E-19 con 50 piezas una usuaria con quotations.negotiate registra el precio de cada línea y cuenta en el total', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 30]), negLine($fx, 'chemise', ['M' => 20])]);
    [$camisas, $chemises] = $quotation->lines->all();
    $actor = negNegotiator();

    negotiate($actor, $camisas, ['prices' => [['size_group_id' => null, 'price' => '29.00']]])->assertRedirect();
    negotiate($actor, $chemises, ['prices' => [['size_group_id' => null, 'price' => '14,00']]])->assertRedirect();

    $result = negCalculate($quotation);
    $camisas->refresh();

    expect(negStoredPrices($camisas))->toBe([['size_group_id' => null, 'unit_price' => '29.00']])
        ->and($camisas->negotiated_by)->toBe($actor->id)
        ->and($camisas->negotiated_at)->not->toBeNull()
        ->and($camisas->negotiated_combination_id)->toBe($fx['combinations']['camisa']->id)
        ->and($camisas->negotiated_size_group_ids)->toBe([])
        ->and($camisas->negotiation_confirmed)->toBeFalse()
        ->and($result['lines'][0]['price_origin'])->toBe('negotiated')
        ->and($result['lines'][0]['subtotal']['value'])->toBe('870.00')
        ->and($result['lines'][1]['subtotal']['value'])->toBe('280.00')
        ->and($result['total']['value'])->toBe('1150.00')
        ->and((string) $quotation->fresh()->total)->toBe('1150.00')
        ->and($result['requires_advisor'])->toBeTrue();

    $audit = AuditLog::query()->where('action', AuditAction::QuotationNegotiatedPriceSet->value)->orderBy('id')->first();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($quotation->id)
        ->and($audit->new_values)->toMatchArray(['line' => 1, 'prices' => [['size_group_id' => null, 'price' => '29.00']], 'confirmed' => false]);
});

it('E-19 con precios negociados y sin anticipo acordado la cotización todavía no está completa', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 50])]);

    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => null, 'price' => '29.00']]])->assertRedirect();

    $result = negCalculate($quotation);

    expect($result['lines'][0]['errors'])->toBe([])
        ->and($result['total']['value'])->toBe('1450.00')
        ->and($result['complete'])->toBeFalse();
});

it('COT-012 confirmar el precio de referencia no admite enviar precios', function () {
    $fx = negCatalog();
    $line = negLine($fx, 'camisa', ['M' => 10], ['selection' => ['order' => [$fx['color']->id => 'custom'], 'custom_color' => ['tone' => '#123456']]]);
    $quotation = negDraft([$line]);

    negotiate(negNegotiator(), $quotation->lines->sole(), ['confirmed' => true, 'prices' => [['size_group_id' => null, 'price' => '30.00']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['prices']);

    expect(negStoredPrices($quotation->lines->sole()))->toBe([]);
});

it('COT-012 el cálculo en vivo rechaza un precio negociado no positivo o sin base', function (array $negotiated, string $field) {
    $fx = negCatalog();

    test()->actingAs(negNegotiator())->postJson('/quotations/calculate', ['lines' => [negLine($fx, 'camisa', ['M' => 50], ['negotiated' => $negotiated])]])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'precio 0' => [['prices' => [['size_group_id' => null, 'price' => '0']], 'basis' => ['combination_id' => 1, 'size_group_ids' => []]], 'lines.0.negotiated.prices.0.price'],
    'sin base' => [['prices' => [['size_group_id' => null, 'price' => '29.00']]], 'lines.0.negotiated.basis'],
]);

it('COT-012 una línea que pasa a ser de combo pierde su precio negociado', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 50])]);
    $line = $quotation->lines->sole();
    negotiate(negNegotiator(), $line, ['prices' => [['size_group_id' => null, 'price' => '29.00']]])->assertRedirect();
    $combo = Combo::factory()->create();
    CatalogCode::factory()->forCombo($combo)->create();

    negResave($quotation, $fx, fn (array $lines) => [['id' => $line->id, 'kind' => 'combo', 'combo_id' => $combo->id, 'combo_quantity' => 1, 'components' => []]])
        ->assertRedirect();

    expect(negStoredPrices($line))->toBe([])
        ->and($line->fresh()->negotiated_combination_id)->toBeNull();
});

it('DEC-COT-67 varias tallas sin grupo se nombran en plural', function () {
    $fx = negCatalog();
    $extra = AttributeValue::factory()->for(CatalogAttribute::query()->where('special_use', 'size')->sole(), 'catalogAttribute')->create(['name' => '48', 'sort_order' => 99]);
    $fx['products']['pantalon']->productAttributes()->first()->allowedValues()->attach($extra->id);
    $fx['vals']['48'] = $extra;
    $quotation = negDraft([negLine($fx, 'pantalon', ['46' => 25, '48' => 25])]);

    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => null, 'price' => '20.00']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['price' => 'Las tallas 46, 48 no están en ningún grupo de talla de esta combinación']);
});

it('E-20 sin quotations.negotiate no se registra el precio negociado y la denegación queda auditada', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 50])]);

    negotiate(negCreator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => null, 'price' => '29.00']]])->assertForbidden();

    expect(negStoredPrices($quotation->lines->sole()))->toBe([])
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->count())->toBe(1);
});

it('E-21 un precio negociado para una línea que no requiere asesora se rechaza', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 10])]);

    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => null, 'price' => '29.00']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['price' => 'La línea no requiere asesora.']);

    expect(negStoredPrices($quotation->lines->sole()))->toBe([]);
});

it('COT-012 el precio negociado es un monto mayor que cero con a lo sumo 2 decimales', function (string $price) {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 50])]);

    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => null, 'price' => $price]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['prices.0.price']);
})->with(['0', '-1', '29.005', 'abc']);

it('E-76 solo se negocian los grupos de talla que usan las filas de la línea', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'pantalon', ['32' => 25, '34' => 25])]);
    $line = $quotation->lines->sole();
    [$first, $second] = $fx['groups'];

    negotiate(negNegotiator(), $line, ['prices' => [['size_group_id' => $first->id, 'price' => '20.00'], ['size_group_id' => $second->id, 'price' => '22.00']]])
        ->assertStatus(422)->assertJsonValidationErrors(['prices']);
    negotiate(negNegotiator(), $line, ['prices' => [['size_group_id' => $second->id, 'price' => '22.00']]])
        ->assertStatus(422)->assertJsonValidationErrors(['prices']);
    negotiate(negNegotiator(), $line, ['prices' => [['size_group_id' => $first->id, 'price' => '20.00']]])->assertRedirect();

    expect(negStoredPrices($line))->toBe([['size_group_id' => $first->id, 'unit_price' => '20.00']])
        ->and($line->fresh()->negotiated_size_group_ids)->toBe([$first->id])
        ->and(negCalculate($quotation)['lines'][0]['subtotal']['value'])->toBe('1000.00');
});

it('E-76 con filas en dos grupos se negocia un precio por grupo', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'pantalon', ['32' => 25, '40' => 25])]);
    [$first, $second] = $fx['groups'];

    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => $first->id, 'price' => '20.00']]])
        ->assertStatus(422)->assertJsonValidationErrors(['prices']);
    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => $second->id, 'price' => '22.00'], ['size_group_id' => $first->id, 'price' => '20.00']]])
        ->assertRedirect();

    $result = negCalculate($quotation);

    expect($result['lines'][0]['rows'][0]['unit_price']['value'])->toBe('20.00')
        ->and($result['lines'][0]['rows'][1]['unit_price']['value'])->toBe('22.00')
        ->and($result['lines'][0]['subtotal']['value'])->toBe('1050.00');
});

it('DEC-COT-67 una talla en ningún grupo deja la línea sin precio y no se puede negociar', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'pantalon', ['32' => 25, '46' => 25])]);

    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => $fx['groups'][0]->id, 'price' => '20.00']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['price' => 'La talla 46 no está en ningún grupo de talla de esta combinación']);

    expect(negStoredPrices($quotation->lines->sole()))->toBe([])
        ->and(negCalculate($quotation)['lines'][0]['errors'])->toBe(['lines.0' => 'sin precio']);
});

it('E-23 el color personalizado se confirma con el precio de referencia', function () {
    $fx = negCatalog();
    $line = negLine($fx, 'camisa', ['M' => 10], ['selection' => ['order' => [$fx['color']->id => 'custom'], 'custom_color' => ['tone' => '#123456']]]);
    $quotation = negDraft([$line]);

    negotiate(negNegotiator(), $quotation->lines->sole(), ['confirmed' => true])->assertRedirect();

    $result = negCalculate($quotation);

    expect(negStoredPrices($quotation->lines->sole()))->toBe([['size_group_id' => null, 'unit_price' => '36.00']])
        ->and($quotation->lines->sole()->fresh()->negotiation_confirmed)->toBeTrue()
        ->and($result['lines'][0]['price_origin'])->toBe('negotiated')
        ->and($result['lines'][0]['subtotal']['value'])->toBe('360.00')
        ->and($result['total']['value'])->toBe('360.00');
});

it('COT-012 un tramo de negociación no tiene precio de referencia que confirmar', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 50])]);

    negotiate(negNegotiator(), $quotation->lines->sole(), ['confirmed' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['prices']);
});

it('DEC-COT-55 guardar el borrador con las mismas líneas conserva el precio negociado', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 30]), negLine($fx, 'chemise', ['M' => 20])]);
    negotiate(negNegotiator(), $quotation->lines[0], ['prices' => [['size_group_id' => null, 'price' => '29.00']]])->assertRedirect();

    negResave($quotation, $fx, function (array $lines) {
        $lines[0]['rows'][0]['quantity'] = 35;

        return $lines;
    })->assertRedirect();

    expect(negStoredPrices($quotation->lines[0]))->toBe([['size_group_id' => null, 'unit_price' => '29.00']]);
});

it('E-22 si la línea deja de requerir asesora, el borrador descarta el precio negociado sin auditar', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 30]), negLine($fx, 'chemise', ['M' => 20])]);
    negotiate(negNegotiator(), $quotation->lines[0], ['prices' => [['size_group_id' => null, 'price' => '29.00']]])->assertRedirect();
    $audits = AuditLog::query()->count();

    negResave($quotation, $fx, function (array $lines) {
        $lines[1]['rows'][0]['quantity'] = 10;

        return $lines;
    })->assertRedirect();

    $line = $quotation->lines[0]->fresh();

    expect(negStoredPrices($line))->toBe([])
        ->and($line->negotiated_combination_id)->toBeNull()
        ->and($line->negotiated_size_group_ids)->toBeNull()
        ->and($line->negotiated_by)->toBeNull()
        ->and(AuditLog::query()->count())->toBe($audits)
        ->and(negCalculate($quotation)['lines'][0]['subtotal']['value'])->toBe('1080.00');
});

it('E-22 el cálculo en vivo indica que se descartó el precio negociado', function () {
    $fx = negCatalog();
    $lines = [
        negLine($fx, 'camisa', ['M' => 30], ['negotiated' => ['prices' => [['size_group_id' => null, 'price' => '29.00']], 'confirmed' => false, 'basis' => ['combination_id' => null, 'size_group_ids' => []]]]),
        negLine($fx, 'chemise', ['M' => 10]),
    ];
    $lines[0]['negotiated']['basis']['combination_id'] = $fx['combinations']['camisa']->id;

    $result = test()->actingAs(negNegotiator())->postJson('/quotations/calculate', ['lines' => $lines])->assertOk()->json();

    expect($result['lines'][0]['negotiated_discarded'])->toBeTrue()
        ->and($result['lines'][0]['negotiated_discarded_reason'])->toBe('no_longer_requires_advisor')
        ->and($result['lines'][0]['notice'])->toBe('Se descartó el precio negociado de la línea 1: ya no requiere asesora.')
        ->and($result['lines'][0]['price_origin'])->toBe('tier')
        ->and($result['lines'][0]['subtotal']['value'])->toBe('1080.00');
});

it('E-19 el cálculo en vivo aplica el precio negociado que envía el editor', function () {
    $fx = negCatalog();
    $basis = ['combination_id' => $fx['combinations']['camisa']->id, 'size_group_ids' => []];

    $result = test()->actingAs(negNegotiator())->postJson('/quotations/calculate', ['lines' => [
        negLine($fx, 'camisa', ['M' => 50], ['negotiated' => ['prices' => [['size_group_id' => null, 'price' => '29.00']], 'confirmed' => false, 'basis' => $basis]]),
    ]])->assertOk()->json();

    expect($result['lines'][0]['price_origin'])->toBe('negotiated')
        ->and($result['lines'][0]['negotiated_discarded'])->toBeFalse()
        ->and($result['lines'][0]['negotiation_basis'])->toBe($basis)
        ->and($result['total']['value'])->toBe('1450.00');
});

it('COT-012 al guardar, el precio negociado sale de lo guardado y nunca del payload', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 50])]);
    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => null, 'price' => '29.00']]])->assertRedirect();

    negResave($quotation, $fx, function (array $lines) {
        $lines[0]['negotiated'] = ['prices' => [['size_group_id' => null, 'price' => '1.00']], 'confirmed' => false, 'basis' => ['combination_id' => $lines[0]['negotiated']['basis']['combination_id'], 'size_group_ids' => []]];

        return $lines;
    })->assertRedirect();

    expect(negStoredPrices($quotation->lines->sole()))->toBe([['size_group_id' => null, 'unit_price' => '29.00']])
        ->and((string) $quotation->fresh()->total)->toBe('1450.00');
});

it('DEC-COT-18 una cotización emitida no se negocia', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 50])]);
    $quotation->forceFill(['status' => 'issued', 'issued_at' => now(), 'expires_on' => now()->addDays(7)])->save();

    negotiate(negNegotiator(), $quotation->lines->sole(), ['prices' => [['size_group_id' => null, 'price' => '29.00']]])
        ->assertStatus(422)
        ->assertJsonPath('message', 'La cotización no está en borrador; duplíquela para cambiarla.');
});

it('COT-012 una línea de otra cotización no se encuentra', function () {
    $fx = negCatalog();
    $quotation = negDraft([negLine($fx, 'camisa', ['M' => 50])]);
    $other = Quotation::factory()->create();

    test()->actingAs(negNegotiator())
        ->putJson("/quotations/{$other->id}/lines/{$quotation->lines->sole()->id}/negotiated-price", ['prices' => [['size_group_id' => null, 'price' => '29.00']]])
        ->assertNotFound();
});
