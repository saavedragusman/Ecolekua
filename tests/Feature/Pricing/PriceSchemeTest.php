<?php

use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Models\AuditLog;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PriceSchemeSeeder;
use Illuminate\Database\Eloquent\Collection;

// COT-001 (E-01, E-02, E-03, DEC-COT-23, DEC-COT-51, DEC-COT-57): price schemes and their tiers. Pages ship
// in Phase 22, so the write endpoints answer a redirect back (003 precedent).

function schemeManager(): User
{
    return userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);
}

/**
 * The Uniformes-like tier set of E-01 as the form sends it.
 *
 * @return list<array{label: string, min: int, max: ?int, kind: string}>
 */
function schemeTestTiers(): array
{
    return [
        ['label' => 'Detal', 'min' => 1, 'max' => 6, 'kind' => 'priced'],
        ['label' => '7–12', 'min' => 7, 'max' => 12, 'kind' => 'priced'],
        ['label' => '13–49', 'min' => 13, 'max' => 49, 'kind' => 'priced'],
        ['label' => 'Negociación', 'min' => 50, 'max' => null, 'kind' => 'negotiation'],
    ];
}

/**
 * A stored tiered scheme (Detal 1-6, Especial 7+) assigned to no product.
 */
function storedTieredScheme(string $name = 'Pañales de adulto'): PriceScheme
{
    $scheme = PriceScheme::factory()->create(['name' => $name]);
    $scheme->tiers()->create(['label' => 'Detal', 'min_quantity' => 1, 'max_quantity' => 6, 'kind' => PriceTierKind::Priced, 'sort_order' => 1]);
    $scheme->tiers()->create(['label' => 'Especial', 'min_quantity' => 7, 'max_quantity' => null, 'kind' => PriceTierKind::Priced, 'sort_order' => 2]);

    return $scheme->fresh();
}

/**
 * The form state of a stored scheme, with every tier carrying its id.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function schemeFormState(PriceScheme $scheme, array $overrides = []): array
{
    return [
        'name' => $scheme->name,
        'type' => $scheme->type->value,
        'tiers' => $scheme->tiers->map(fn (PriceTier $tier): array => [
            'id' => $tier->id,
            'label' => $tier->label,
            'min' => $tier->min_quantity,
            'max' => $tier->max_quantity,
            'kind' => $tier->kind->value,
        ])->all(),
        ...$overrides,
    ];
}

/**
 * Registers a price of the given amount in a tier for a fresh active combination of a new product
 * that uses the scheme.
 */
function schemePriceIn(PriceTier $tier, string $code, string $amount = '40.00', ?Product $product = null): CombinationPrice
{
    $product ??= Product::factory()->for(ProductCategory::factory(), 'category')->create(['price_scheme_id' => $tier->price_scheme_id]);
    $combination = Combination::factory()->create(['product_id' => $product->id]);
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => $code]);

    return CombinationPrice::factory()->create([
        'combination_id' => $combination->id,
        'price_tier_id' => $tier->id,
        'unit_price' => $amount,
    ]);
}

/**
 * @return Collection<int, AuditLog>
 */
function schemeAuditRows(): Collection
{
    return AuditLog::query()->where('action', 'like', 'pricing.scheme_%')->orderBy('id')->get();
}

it('E-01 creates an active tiered scheme with its four tiers and audits the created values', function () {
    $actor = schemeManager();

    $this->actingAs($actor)->postJson('/pricing/schemes', [
        'name' => 'Uniformes',
        'type' => 'tiered',
        'tiers' => schemeTestTiers(),
    ])->assertRedirect();

    $scheme = PriceScheme::query()->where('name', 'Uniformes')->sole();

    expect($scheme->type)->toBe(PriceSchemeType::Tiered)
        ->and($scheme->status)->toBe(CatalogStatus::Active)
        ->and($scheme->tiers->map(fn (PriceTier $tier): array => [$tier->label, $tier->min_quantity, $tier->max_quantity, $tier->kind->value, $tier->sort_order])->all())->toBe([
            ['Detal', 1, 6, 'priced', 1],
            ['7–12', 7, 12, 'priced', 2],
            ['13–49', 13, 49, 'priced', 3],
            ['Negociación', 50, null, 'negotiation', 4],
        ]);

    $audit = schemeAuditRows()->sole();

    expect($audit->action)->toBe(AuditAction::PriceSchemeCreated->value)
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($scheme->id)
        ->and($audit->old_values)->toBe([])
        ->and($audit->new_values)->toEqual([
            'name' => 'Uniformes',
            'type' => 'tiered',
            'status' => 'active',
            'tiers' => schemeTestTiers(),
        ]);
});

it('E-01 creates a fixed scheme with one hidden system tier and ignores no tier input', function () {
    $this->actingAs(schemeManager())->postJson('/pricing/schemes', ['name' => 'Precio fijo', 'type' => 'fixed'])->assertRedirect();

    $scheme = PriceScheme::query()->where('name', 'Precio fijo')->sole();
    $tier = $scheme->tiers->sole();

    expect($scheme->type)->toBe(PriceSchemeType::Fixed)
        ->and([$tier->label, $tier->min_quantity, $tier->max_quantity, $tier->kind, $tier->sort_order])->toBe(['Precio', 1, null, PriceTierKind::Priced, 1])
        ->and(schemeAuditRows()->sole()->new_values['tiers'])->toEqual([['label' => 'Precio', 'min' => 1, 'max' => null, 'kind' => 'priced']]);
});

it('E-02 rejects an invalid tier set with an error on the tiers and saves nothing', function (Closure $mutate, string $errorKey) {
    $this->actingAs(schemeManager())
        ->postJson('/pricing/schemes', ['name' => 'Uniformes', 'type' => 'tiered', 'tiers' => $mutate(schemeTestTiers())])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect(PriceScheme::query()->count())->toBe(0)
        ->and(PriceTier::query()->count())->toBe(0)
        ->and(schemeAuditRows())->toHaveCount(0);
})->with([
    'starts at 2' => [function (array $tiers): array {
        $tiers[0]['min'] = 2;

        return $tiers;
    }, 'tiers.0.min'],
    'gap between 6 and 8' => [function (array $tiers): array {
        $tiers[1]['min'] = 8;

        return $tiers;
    }, 'tiers.1.min'],
    'two tiers include 12' => [function (array $tiers): array {
        $tiers[2]['min'] = 12;

        return $tiers;
    }, 'tiers.2.min'],
    'negotiation tier that is not the last' => [function (array $tiers): array {
        $tiers[1]['kind'] = 'negotiation';

        return $tiers;
    }, 'tiers.1.kind'],
    'intermediate tier without a maximum' => [function (array $tiers): array {
        $tiers[1]['max'] = null;

        return $tiers;
    }, 'tiers.1.max'],
    'reserved CSV header as a label' => [function (array $tiers): array {
        $tiers[0]['label'] = 'Codigo';

        return $tiers;
    }, 'tiers.0.label'],
    'no tiers' => [fn (array $tiers): array => [], 'tiers'],
]);

it('E-02 reports every broken tier of the same request together', function () {
    $tiers = schemeTestTiers();
    $tiers[0]['min'] = 2;
    $tiers[2]['min'] = 20;

    $this->actingAs(schemeManager())
        ->postJson('/pricing/schemes', ['name' => 'Uniformes', 'type' => 'tiered', 'tiers' => $tiers])
        ->assertJsonValidationErrors(['tiers.0.min', 'tiers.2.min']);
});

it('E-02 rejects malformed tier input and a tiered scheme without tiers', function (array $payload, string $errorKey) {
    $this->actingAs(schemeManager())
        ->postJson('/pricing/schemes', ['name' => 'Uniformes', 'type' => 'tiered', 'tiers' => schemeTestTiers(), ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect(PriceScheme::query()->count())->toBe(0);
})->with([
    'name missing' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 61)], 'name'],
    'type missing' => [['type' => null], 'type'],
    'type unknown' => [['type' => 'free'], 'type'],
    'tiers missing' => [['tiers' => null], 'tiers'],
    'minimum is not a number' => [['tiers' => [['label' => 'Detal', 'min' => 'x', 'max' => null, 'kind' => 'priced']]], 'tiers.0.min'],
    'kind is unknown' => [['tiers' => [['label' => 'Detal', 'min' => 1, 'max' => null, 'kind' => 'free']]], 'tiers.0.kind'],
]);

it('E-02 rejects a scheme name that repeats another one ignoring letter case', function () {
    PriceScheme::factory()->create(['name' => 'Uniformes']);

    $this->actingAs(schemeManager())
        ->postJson('/pricing/schemes', ['name' => 'UNIFORMES', 'type' => 'tiered', 'tiers' => schemeTestTiers()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(PriceScheme::query()->count())->toBe(1)
        ->and(schemeAuditRows())->toHaveCount(0);
});

it('DEC-COT-51 rejects an update that changes the type of the scheme and keeps the stored type', function () {
    $scheme = storedTieredScheme();

    $this->actingAs(schemeManager())
        ->putJson("/pricing/schemes/{$scheme->id}", schemeFormState($scheme, ['type' => 'fixed']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);

    expect($scheme->fresh()->type)->toBe(PriceSchemeType::Tiered)
        ->and(schemeAuditRows())->toHaveCount(0);
});

it('DEC-COT-51 accepts an update that repeats the stored type', function () {
    $scheme = storedTieredScheme();

    $this->actingAs(schemeManager())
        ->putJson("/pricing/schemes/{$scheme->id}", schemeFormState($scheme, ['name' => 'Pañales de adulto 2']))
        ->assertRedirect();

    expect($scheme->fresh()->name)->toBe('Pañales de adulto 2')
        ->and($scheme->fresh()->type)->toBe(PriceSchemeType::Tiered);
});

it('COT-001 changes labels and limits without touching the loaded prices and audits the tiers before and after', function () {
    $scheme = storedTieredScheme();
    [$detal, $especial] = $scheme->tiers->all();
    $detalPrice = schemePriceIn($detal, '110-1');
    $especialPrice = schemePriceIn($especial, '110-2', '32.00');

    $payload = schemeFormState($scheme);
    $payload['tiers'][0]['label'] = 'Menor';
    $payload['tiers'][0]['max'] = 9;
    $payload['tiers'][1]['label'] = 'Mayor';
    $payload['tiers'][1]['min'] = 10;

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)->assertRedirect();

    $tiers = $scheme->fresh()->tiers;

    expect($tiers->pluck('id')->all())->toBe([$detal->id, $especial->id])
        ->and($tiers->map(fn (PriceTier $tier): array => [$tier->label, $tier->min_quantity, $tier->max_quantity])->all())->toBe([['Menor', 1, 9], ['Mayor', 10, null]])
        ->and($detalPrice->fresh()->unit_price->toString())->toBe('40.00')
        ->and($especialPrice->fresh()->unit_price->toString())->toBe('32.00')
        ->and(CombinationPrice::query()->count())->toBe(2);

    $audit = schemeAuditRows()->sole();

    expect($audit->action)->toBe(AuditAction::PriceSchemeUpdated->value)
        ->and($audit->old_values)->toEqual(['tiers' => [
            ['label' => 'Detal', 'min' => 1, 'max' => 6, 'kind' => 'priced'],
            ['label' => 'Especial', 'min' => 7, 'max' => null, 'kind' => 'priced'],
        ]])
        ->and($audit->new_values)->toEqual(['tiers' => [
            ['label' => 'Menor', 'min' => 1, 'max' => 9, 'kind' => 'priced'],
            ['label' => 'Mayor', 'min' => 10, 'max' => null, 'kind' => 'priced'],
        ]]);
});

it('COT-001 swaps the labels and the order of existing tiers without hitting the unique indexes', function () {
    $scheme = storedTieredScheme();
    [$first, $second] = $scheme->tiers->all();

    $payload = schemeFormState($scheme);
    $payload['tiers'] = [
        ['id' => $second->id, 'label' => 'Detal', 'min' => 1, 'max' => 6, 'kind' => 'priced'],
        ['id' => $first->id, 'label' => 'Especial', 'min' => 7, 'max' => null, 'kind' => 'priced'],
    ];

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)->assertRedirect();

    expect($scheme->fresh()->tiers->map(fn (PriceTier $tier): array => [$tier->id, $tier->label, $tier->sort_order])->all())->toBe([
        [$second->id, 'Detal', 1],
        [$first->id, 'Especial', 2],
    ]);
});

it('COT-001 audits only the changed fields of a rename and writes nothing when nothing changed', function () {
    $scheme = storedTieredScheme();
    $actor = schemeManager();

    $this->actingAs($actor)->putJson("/pricing/schemes/{$scheme->id}", schemeFormState($scheme, ['name' => 'Absorbentes']))->assertRedirect();

    $audit = schemeAuditRows()->sole();

    expect($audit->old_values)->toBe(['name' => 'Pañales de adulto'])
        ->and($audit->new_values)->toBe(['name' => 'Absorbentes']);

    $updatedAt = $scheme->fresh()->updated_at;
    $this->travel(5)->minutes();

    $this->actingAs($actor)->putJson("/pricing/schemes/{$scheme->id}", schemeFormState($scheme->fresh()))->assertRedirect();

    expect(schemeAuditRows())->toHaveCount(1)
        ->and($scheme->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('COT-001 adds a tier without creating prices for it and keeps the existing prices', function () {
    $scheme = storedTieredScheme();
    [$detal, $especial] = $scheme->tiers->all();
    schemePriceIn($detal, '110-1');
    schemePriceIn($especial, '110-2', '32.00');

    $payload = schemeFormState($scheme);
    $payload['tiers'] = [
        ['id' => $detal->id, 'label' => 'Detal', 'min' => 1, 'max' => 6, 'kind' => 'priced'],
        ['label' => '7–12', 'min' => 7, 'max' => 12, 'kind' => 'priced'],
        ['id' => $especial->id, 'label' => 'Especial', 'min' => 13, 'max' => null, 'kind' => 'priced'],
    ];

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)->assertRedirect();

    $tiers = $scheme->fresh()->tiers;
    $added = $tiers->firstWhere('label', '7–12');

    expect($tiers->pluck('label')->all())->toBe(['Detal', '7–12', 'Especial'])
        ->and($added->prices()->count())->toBe(0)
        ->and(CombinationPrice::query()->count())->toBe(2);
});

it('DEC-COT-23 asks for confirmation before removing a tier that has prices and changes nothing without it', function () {
    $scheme = storedTieredScheme();
    $especial = $scheme->tiers->last();
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create(['price_scheme_id' => $scheme->id]);
    schemePriceIn($especial, '110-1', '32.00', $product);
    schemePriceIn($especial, '110-2', '30.00', $product);

    $payload = schemeFormState($scheme);
    $payload['tiers'] = [['id' => $scheme->tiers->first()->id, 'label' => 'Detal', 'min' => 1, 'max' => null, 'kind' => 'priced']];

    $response = $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tiers']);

    expect($response->json('errors.tiers.0'))->toContain('2 precios')->toContain('1 producto')
        ->and($scheme->fresh()->tiers)->toHaveCount(2)
        ->and($scheme->fresh()->tiers->first()->max_quantity)->toBe(6)
        ->and(CombinationPrice::query()->count())->toBe(2)
        ->and(schemeAuditRows())->toHaveCount(0);
});

it('DEC-COT-23 removes the tier and its prices on confirmation and lists the previous prices in the audit', function () {
    $scheme = storedTieredScheme();
    [$detal, $especial] = $scheme->tiers->all();
    $kept = schemePriceIn($detal, '110-1', '40.00');
    schemePriceIn($especial, '110-2', '32.50');

    $payload = schemeFormState($scheme, ['confirm_price_removal' => true]);
    $payload['tiers'] = [['id' => $detal->id, 'label' => 'Detal', 'min' => 1, 'max' => null, 'kind' => 'priced']];

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)->assertRedirect();

    expect($scheme->fresh()->tiers->pluck('id')->all())->toBe([$detal->id])
        ->and(CombinationPrice::query()->pluck('id')->all())->toBe([$kept->id]);

    $audit = schemeAuditRows()->sole();

    expect($audit->old_values['deleted_prices'])->toEqual([
        ['code' => '110-2', 'size_group' => null, 'tier' => 'Especial', 'price' => '32.50'],
    ])
        ->and($audit->old_values['deleted_prices'][0]['price'])->toBeMoneyString()
        ->and($audit->new_values)->toHaveKey('tiers');
});

it('DEC-COT-23 removes a tier without prices without asking for confirmation', function () {
    $scheme = storedTieredScheme();
    $detal = $scheme->tiers->first();

    $payload = schemeFormState($scheme);
    $payload['tiers'] = [['id' => $detal->id, 'label' => 'Detal', 'min' => 1, 'max' => null, 'kind' => 'priced']];

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)->assertRedirect();

    expect($scheme->fresh()->tiers)->toHaveCount(1)
        ->and(schemeAuditRows()->sole()->old_values)->not->toHaveKey('deleted_prices');
});

it('DEC-COT-57 treats turning a tier with prices into a negotiation tier as removing it', function () {
    $scheme = storedTieredScheme();
    [$detal, $especial] = $scheme->tiers->all();
    $price = schemePriceIn($especial, '110-2', '32.00');

    $payload = schemeFormState($scheme);
    $payload['tiers'][1]['kind'] = 'negotiation';

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tiers']);

    expect($especial->fresh()->kind)->toBe(PriceTierKind::Priced)
        ->and($price->fresh())->not->toBeNull();

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", [...$payload, 'confirm_price_removal' => true])->assertRedirect();

    expect($especial->fresh()->kind)->toBe(PriceTierKind::Negotiation)
        ->and(CombinationPrice::query()->count())->toBe(0)
        ->and(schemeAuditRows()->sole()->old_values['deleted_prices'])->toEqual([
            ['code' => '110-2', 'size_group' => null, 'tier' => 'Especial', 'price' => '32.00'],
        ])
        ->and($detal->fresh()->kind)->toBe(PriceTierKind::Priced);
});

it('COT-001 rejects an update that names a tier of another scheme', function () {
    $scheme = storedTieredScheme();
    $other = storedTieredScheme('Protector y absorbentes');

    $payload = schemeFormState($scheme);
    $payload['tiers'][0]['id'] = $other->tiers->first()->id;

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tiers.0.id']);

    expect($other->fresh()->tiers->first()->label)->toBe('Detal')
        ->and(schemeAuditRows())->toHaveCount(0);
});

it('COT-001 rejects invalid tiers on update with the same errors as on create', function () {
    $scheme = storedTieredScheme();

    $payload = schemeFormState($scheme);
    $payload['tiers'][1]['min'] = 9;

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tiers.1.min']);

    expect($scheme->fresh()->tiers->last()->min_quantity)->toBe(7);
});

it('COT-001 lets a fixed scheme change its name but never its tiers', function () {
    $scheme = PriceScheme::factory()->fixed()->create(['name' => 'Precio fijo']);

    $this->actingAs(schemeManager())->putJson("/pricing/schemes/{$scheme->id}", ['name' => 'Servicios', 'type' => 'fixed'])->assertRedirect();

    expect($scheme->fresh()->name)->toBe('Servicios')
        ->and($scheme->fresh()->tiers)->toHaveCount(1);

    $this->actingAs(schemeManager())
        ->putJson("/pricing/schemes/{$scheme->id}", ['name' => 'Servicios', 'type' => 'fixed', 'tiers' => schemeTestTiers()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tiers']);

    expect($scheme->fresh()->tiers)->toHaveCount(1);
});

it('E-03 refuses to deactivate a scheme assigned to an active product and names the product', function () {
    $scheme = PriceScheme::factory()->create(['name' => 'Uniformes']);
    $inactive = Product::factory()->for(ProductCategory::factory(), 'category')->create(['name' => 'Camisa vieja', 'status' => CatalogStatus::Inactive, 'price_scheme_id' => $scheme->id]);
    Product::factory()->for(ProductCategory::factory(), 'category')->create(['name' => 'Camisa corporativa', 'price_scheme_id' => $scheme->id]);

    $response = $this->actingAs(schemeManager())->postJson("/pricing/schemes/{$scheme->id}/deactivate")->assertUnprocessable();

    expect($response->json('message'))->toContain('Camisa corporativa')->not->toContain($inactive->name)
        ->and($scheme->fresh()->status)->toBe(CatalogStatus::Active)
        ->and(schemeAuditRows())->toHaveCount(0);
});

it('E-03 deactivates a scheme whose products are all inactive and reactivates it, auditing both', function () {
    $scheme = PriceScheme::factory()->create();
    Product::factory()->for(ProductCategory::factory(), 'category')->create(['status' => CatalogStatus::Inactive, 'price_scheme_id' => $scheme->id]);
    $actor = schemeManager();

    $this->actingAs($actor)->postJson("/pricing/schemes/{$scheme->id}/deactivate")->assertRedirect();

    expect($scheme->fresh()->status)->toBe(CatalogStatus::Inactive);

    $this->actingAs($actor)->postJson("/pricing/schemes/{$scheme->id}/activate")->assertRedirect();

    expect($scheme->fresh()->status)->toBe(CatalogStatus::Active);

    [$deactivated, $activated] = schemeAuditRows()->all();

    expect(schemeAuditRows())->toHaveCount(2)
        ->and($deactivated->action)->toBe(AuditAction::PriceSchemeDeactivated->value)
        ->and($deactivated->actor_id)->toBe($actor->id)
        ->and($deactivated->entity_id)->toBe($scheme->id)
        ->and($deactivated->old_values)->toBe(['status' => 'active'])
        ->and($deactivated->new_values)->toBe(['status' => 'inactive'])
        ->and($activated->action)->toBe(AuditAction::PriceSchemeActivated->value)
        ->and($activated->old_values)->toBe(['status' => 'inactive'])
        ->and($activated->new_values)->toBe(['status' => 'active']);
});

it('E-03 repeating the state of a scheme writes no change and no audit', function (string $action, CatalogStatus $state) {
    $scheme = PriceScheme::factory()->create(['status' => $state]);
    $updatedAt = $scheme->fresh()->updated_at;

    $this->travel(5)->minutes();
    $this->actingAs(schemeManager())->postJson("/pricing/schemes/{$scheme->id}/{$action}")->assertRedirect();

    expect($scheme->fresh()->status)->toBe($state)
        ->and($scheme->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(schemeAuditRows())->toHaveCount(0);
})->with([
    'activate an active scheme' => ['activate', CatalogStatus::Active],
    'deactivate an inactive scheme' => ['deactivate', CatalogStatus::Inactive],
]);

it('COT-022 denies every scheme write without pricing.manage, changes nothing and audits the denial', function (string $method, string $uri) {
    $scheme = storedTieredScheme('Uniformes');
    $actor = userWithPermissions(PermissionName::PricingView);
    $uri = str_replace('{scheme}', (string) $scheme->id, $uri);

    $this->actingAs($actor)->json($method, $uri, schemeFormState($scheme, ['name' => 'Otro nombre']))->assertForbidden();

    expect($scheme->fresh()->name)->toBe('Uniformes')
        ->and(PriceScheme::query()->count())->toBe(1)
        ->and(schemeAuditRows())->toHaveCount(0);

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id);
})->with([
    'create' => ['POST', '/pricing/schemes'],
    'update' => ['PUT', '/pricing/schemes/{scheme}'],
    'activate' => ['POST', '/pricing/schemes/{scheme}/activate'],
    'deactivate' => ['POST', '/pricing/schemes/{scheme}/deactivate'],
]);

it('COT-022 does not let product permissions stand in for pricing.manage when writing schemes', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);

    $this->actingAs($actor)->postJson('/pricing/schemes', ['name' => 'Uniformes', 'type' => 'fixed'])->assertForbidden();

    expect(PriceScheme::query()->count())->toBe(0);
});

/**
 * Name, type and tiers (label, min, max, kind) of every scheme as the seeder must leave them (COT-001).
 *
 * @return array<string, array{string, list<array{string, int, ?int, string}>}>
 */
function initialSchemes(): array
{
    return [
        'Uniformes' => ['tiered', [['Detal', 1, 6, 'priced'], ['7–12', 7, 12, 'priced'], ['13–49', 13, 49, 'priced'], ['Negociación', 50, null, 'negotiation']]],
        'Pañales de adulto' => ['tiered', [['Detal', 1, 19, 'priced'], ['Especial', 20, null, 'priced']]],
        'Protector y absorbentes' => ['tiered', [['Detal', 1, 6, 'priced'], ['Especial', 7, null, 'priced']]],
        'Precio fijo' => ['fixed', [['Precio', 1, null, 'priced']]],
    ];
}

it('COT-001 seeds the four initial schemes with their tiers and no prices', function () {
    $this->seed(PriceSchemeSeeder::class);

    $stored = PriceScheme::query()->orderBy('id')->get()->mapWithKeys(fn (PriceScheme $scheme): array => [
        $scheme->name => [$scheme->type->value, $scheme->tiers->map(fn (PriceTier $tier): array => [$tier->label, $tier->min_quantity, $tier->max_quantity, $tier->kind->value])->all()],
    ])->all();

    expect($stored)->toBe(initialSchemes())
        ->and(PriceScheme::query()->where('status', 'active')->count())->toBe(4)
        ->and(CombinationPrice::query()->count())->toBe(0);
});

it('DEC-COT-26 seeds the adult diapers line without a negotiation tier and uniforms with one from 50', function () {
    $this->seed(PriceSchemeSeeder::class);

    $diapers = PriceScheme::query()->where('name', 'Pañales de adulto')->sole();
    $uniforms = PriceScheme::query()->where('name', 'Uniformes')->sole();

    expect($diapers->tiers->where('kind', PriceTierKind::Negotiation))->toHaveCount(0)
        ->and($uniforms->tiers->last()->kind)->toBe(PriceTierKind::Negotiation)
        ->and($uniforms->tiers->last()->min_quantity)->toBe(50);
});

it('COT-001 seeds the schemes once: a second run creates nothing and audits nothing more', function () {
    $this->seed(PriceSchemeSeeder::class);
    $ids = PriceScheme::query()->orderBy('id')->pluck('id')->all();

    $this->seed(PriceSchemeSeeder::class);

    expect(PriceScheme::query()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(PriceTier::query()->count())->toBe(9)
        ->and(schemeAuditRows())->toHaveCount(4);
});

it('COT-001 leaves an existing scheme with the same name untouched, ignoring letter case', function () {
    $existing = PriceScheme::factory()->fixed()->create(['name' => 'UNIFORMES']);

    $this->seed(PriceSchemeSeeder::class);

    expect(PriceScheme::query()->count())->toBe(4)
        ->and($existing->fresh()->type)->toBe(PriceSchemeType::Fixed)
        ->and($existing->fresh()->tiers)->toHaveCount(1)
        ->and(schemeAuditRows())->toHaveCount(3);
});

it('COT-023 audits every seeded scheme as created, with console origin and no actor', function () {
    $this->seed(PriceSchemeSeeder::class);

    $audits = schemeAuditRows();
    $uniformes = $audits->first(fn (AuditLog $audit): bool => $audit->new_values['name'] === 'Uniformes');

    expect($audits)->toHaveCount(4)
        ->and($audits->pluck('action')->unique()->all())->toBe([AuditAction::PriceSchemeCreated->value])
        ->and($audits->pluck('actor_id')->filter()->all())->toBe([])
        ->and($audits->pluck('ip_address')->filter()->all())->toBe([])
        ->and($uniformes->context['source'])->toBe('console')
        ->and($uniformes->context['command'])->toBe('db:seed')
        ->and($uniformes->new_values['tiers'])->toHaveCount(4);
});

it('COT-001 runs the scheme seeder from the database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(PriceScheme::query()->orderBy('id')->pluck('name')->all())->toBe(array_keys(initialSchemes()));
});
