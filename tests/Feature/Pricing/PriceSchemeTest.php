<?php

use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Models\AuditLog;
use App\Models\CombinationPrice;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PriceSchemeSeeder;
use Illuminate\Database\Eloquent\Collection;

// COT-001 (E-01, E-02): price schemes and their tiers. Pages ship in Phase 22, so the write endpoints
// answer a redirect back (003 precedent).

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

it('COT-022 denies creating a scheme without pricing.manage, changes nothing and audits the denial', function () {
    $actor = userWithPermissions(PermissionName::PricingView);

    $this->actingAs($actor)->postJson('/pricing/schemes', ['name' => 'Uniformes', 'type' => 'fixed'])->assertForbidden();

    expect(PriceScheme::query()->count())->toBe(0)
        ->and(schemeAuditRows())->toHaveCount(0);

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id);
});

it('COT-022 does not let product permissions stand in for pricing.manage when creating schemes', function () {
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
