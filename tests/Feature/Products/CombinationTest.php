<?php

use App\Actions\Products\CreateCombination;
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
use App\Models\User;
use App\Support\Products\CombinationRules;
use App\Support\Products\ProductPresenter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

function combCreator(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate);
}

function combEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

function combDeactivator(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsDeactivate);
}

/**
 * Camisa corporativa with four axes (fabric, model, sleeve, gender) and two order attributes (size
 * and color). The product admits sizes S to XL only, although 2XL exists in the catalog, and a fabric
 * ("Algodón sin declarar") exists in the catalog without being admitted. Value names are unique
 * across the fixture, so tests address them by name. Fictitious data only.
 *
 * @return array{product: Product, attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>}
 */
function combCatalog(): array
{
    $attrs = [
        'Tela' => CatalogAttribute::factory()->fabric()->create(),
        'Modelo' => CatalogAttribute::factory()->create(['name' => 'Modelo']),
        'Manga' => CatalogAttribute::factory()->create(['name' => 'Manga']),
        'Género' => CatalogAttribute::factory()->gender()->create(),
        'Talla' => CatalogAttribute::factory()->size()->create(),
        'Color' => CatalogAttribute::factory()->color()->create(),
    ];

    $names = [
        'Tela' => ['ALG-OXF Pima', 'Drill', 'Gabardina', 'Algodón sin declarar'],
        'Modelo' => ['Columbia especial', 'Clásico'],
        'Manga' => ['Manga corta', 'Manga larga'],
        'Género' => ['Dama', 'Caballero'],
        'Talla' => ['S', 'M', 'L', 'XL', '2XL'],
    ];

    $vals = [];

    foreach ($names as $attribute => $valueNames) {
        foreach ($valueNames as $name) {
            // The descriptive name lists the values of an axis in catalog order.
            $vals[$name] = AttributeValue::factory()->for($attrs[$attribute], 'catalogAttribute')->create(['name' => $name, 'sort_order' => count($vals) + 1]);
        }
    }

    $product = Product::factory()->create(['name' => 'Camisa corporativa']);

    $declared = [
        'Tela' => ['axis', ['ALG-OXF Pima', 'Drill', 'Gabardina']],
        'Modelo' => ['axis', ['Columbia especial', 'Clásico']],
        'Manga' => ['axis', ['Manga corta', 'Manga larga']],
        'Género' => ['axis', ['Dama', 'Caballero']],
        'Talla' => ['order', ['S', 'M', 'L', 'XL']],
        'Color' => ['order', []],
    ];

    foreach (array_keys($declared) as $position => $attribute) {
        [$role, $allowed] = $declared[$attribute];
        $row = ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'catalog_attribute_id' => $attrs[$attribute]->id,
            'role' => AttributeRole::from($role),
            'sort_order' => $position + 1,
        ]);
        $row->allowedValues()->attach(array_map(fn (string $name): int => $vals[$name]->id, $allowed));
    }

    return ['product' => $product, 'attrs' => $attrs, 'vals' => $vals];
}

/**
 * `POST /products/{id}/combinations` payload. `$axes` and `$restrictions` map an attribute name to
 * value names; by default the combination is 110 (ALG-OXF Pima, Columbia especial, Manga corta,
 * Caballero).
 *
 * @param  array<string, list<string>>|null  $axes
 * @param  array<string, list<string>>  $restrictions
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function combPayload(array $catalog, array $overrides = [], ?array $axes = null, array $restrictions = []): array
{
    $axes ??= ['Tela' => ['ALG-OXF Pima'], 'Modelo' => ['Columbia especial'], 'Manga' => ['Manga corta'], 'Género' => ['Caballero']];
    $ids = fn (array $map): array => collect($map)->mapWithKeys(fn (array $names, string $attribute): array => [
        $catalog['attrs'][$attribute]->id => array_map(fn (string $name): int => $catalog['vals'][$name]->id, $names),
    ])->all();

    return array_merge(['code' => '110', 'axes' => $ids($axes), 'restrictions' => $ids($restrictions)], $overrides);
}

/**
 * Axes that differ from the default only in the sleeve and gender, so several combinations of one
 * product can coexist without overlapping.
 *
 * @return array<string, list<string>>
 */
function combAxes(string $sleeve, string $gender): array
{
    return ['Tela' => ['ALG-OXF Pima'], 'Modelo' => ['Columbia especial'], 'Manga' => [$sleeve], 'Género' => [$gender]];
}

function postCombination(mixed $test, User $actor, array $catalog, array $payload): TestResponse
{
    return $test->actingAs($actor)->postJson("/products/{$catalog['product']->id}/combinations", $payload);
}

/**
 * Creates a combination straight through the Action, as an administrator would. An inactive one is
 * built with the factories instead, because the Action always creates active combinations and would
 * reject an overlapping one.
 *
 * @param  array<string, list<string>>|null  $axes
 * @param  array<string, list<string>>  $restrictions
 */
function makeCombination(array $catalog, string $code, ?array $axes = null, array $restrictions = [], ?CatalogStatus $status = null): Combination
{
    if ($status === CatalogStatus::Inactive) {
        $axes ??= ['Tela' => ['ALG-OXF Pima'], 'Modelo' => ['Columbia especial'], 'Manga' => ['Manga corta'], 'Género' => ['Caballero']];
        $values = array_merge(...array_map(
            fn (array $names): array => array_map(fn (string $name): AttributeValue => $catalog['vals'][$name], $names),
            array_values($axes),
        ));
        $combination = Combination::factory()->for($catalog['product'])->inactive()->withAxes($values)->create();
        CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => $code]);

        return $combination;
    }

    return app(CreateCombination::class)->handle($catalog['product'], combPayload($catalog, ['code' => $code], $axes, $restrictions), combCreator());
}

/**
 * @return Collection<int, AuditLog>
 */
function combAudit(AuditAction ...$actions): Collection
{
    return AuditLog::query()
        ->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))
        ->orderBy('id')
        ->get();
}

// --- Creation (E-07, E-11) --------------------------------------------------------------------

it('E-07 creates a valid combination active with its code and descriptive name, and audits it', function () {
    $catalog = combCatalog();
    $actor = combCreator();

    $response = postCombination($this, $actor, $catalog, combPayload($catalog, ['description' => 'Camisa de oficina']));

    $combination = Combination::query()->sole();

    expect(parse_url($response->assertRedirect()->headers->get('Location'), PHP_URL_PATH))->toBe("/products/{$catalog['product']->id}")
        ->and($combination->status)->toBe(CatalogStatus::Active)
        ->and($combination->code)->toBe('110')
        ->and($combination->product_id)->toBe($catalog['product']->id)
        ->and($combination->description)->toBe('Camisa de oficina')
        ->and(ProductPresenter::combinationName($combination))->toBe('Camisa corporativa · ALG-OXF Pima · Columbia especial · Manga corta · Caballero');

    $audit = combAudit(AuditAction::CombinationCreated)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($combination->id)
        ->and($audit->old_values)->toBe([])
        ->and($audit->new_values)->toEqual([ // toEqual: the JSON column does not keep the key order
            'code' => '110',
            'description' => 'Camisa de oficina',
            'axes' => ['Tela' => ['ALG-OXF Pima'], 'Modelo' => ['Columbia especial'], 'Manga' => ['Manga corta'], 'Género' => ['Caballero']],
            'restrictions' => [],
        ]);
});

it('E-07 joins several values of one axis with " o " in the descriptive name', function () {
    $catalog = combCatalog();

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '159-1'], [
        'Tela' => ['Gabardina', 'Drill'], 'Modelo' => ['Clásico'], 'Manga' => ['Manga larga'], 'Género' => ['Caballero'],
    ]))->assertRedirect();

    expect(ProductPresenter::combinationName(Combination::query()->sole()))
        ->toBe('Camisa corporativa · Drill o Gabardina · Clásico · Manga larga · Caballero');
});

it('E-11 stores and shows the codes 088-1 and 001RN exactly as typed', function () {
    $catalog = combCatalog();

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '088-1'], combAxes('Manga corta', 'Dama')))->assertRedirect();
    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '001RN'], combAxes('Manga corta', 'Caballero')))->assertRedirect();

    expect(CatalogCode::query()->orderBy('id')->pluck('code')->all())->toBe(['088-1', '001RN'])
        ->and(Combination::query()->orderBy('id')->get()->map(fn (Combination $combination): string => $combination->code)->all())->toBe(['088-1', '001RN']);
});

it('PRD-005 trims the outer whitespace of the code and stores the rest as typed', function () {
    $catalog = combCatalog();

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '  001RN  ']))->assertRedirect();

    expect(CatalogCode::query()->sole()->code)->toBe('001RN');
});

it('PRD-005 allows an optional description and a missing one stores null', function () {
    $catalog = combCatalog();

    postCombination($this, combCreator(), $catalog, combPayload($catalog))->assertRedirect();

    expect(Combination::query()->sole()->description)->toBeNull();

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '111', 'description' => str_repeat('x', 256)], combAxes('Manga larga', 'Dama')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['description']);
});

// --- Codes (E-08, DT-01) ----------------------------------------------------------------------

it('E-08 rejects a code already used by another combination and the database guarantees it anyway', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '110');

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '110'], combAxes('Manga larga', 'Dama')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect(Combination::query()->count())->toBe(1)
        ->and(CatalogCode::query()->count())->toBe(1)
        ->and(combAudit(AuditAction::CombinationCreated))->toHaveCount(1);

    $other = Combination::factory()->for($catalog['product'])->create();

    expect(fn () => DB::table('catalog_codes')->insert(['code' => '110', 'combination_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('DT-01 rejects a code that differs only by letter case, while 088-1 and 88 coexist', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '001RN');

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '001rn'], combAxes('Manga larga', 'Dama')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '088-1'], combAxes('Manga larga', 'Dama')))->assertRedirect();
    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '88'], combAxes('Manga larga', 'Caballero')))->assertRedirect();

    expect(CatalogCode::query()->orderBy('id')->pluck('code')->all())->toBe(['001RN', '088-1', '88']);
});

it('DT-01 rejects a code with inner whitespace, a blank code or a code over 30 characters', function (mixed $code) {
    $catalog = combCatalog();

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => $code]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect(Combination::query()->count())->toBe(0);
})->with([
    'inner space' => ['11 0'],
    'inner tab' => ["11\t0"],
    'blank' => ['   '],
    '31 characters' => [str_repeat('A', 31)],
]);

it('DT-01 accepts a code of exactly 30 characters', function () {
    $catalog = combCatalog();

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => str_repeat('A', 30)]))->assertRedirect();

    expect(CatalogCode::query()->sole()->code)->toBe(str_repeat('A', 30));
});

// --- Axes (E-09, E-10, E-48) ------------------------------------------------------------------

it('E-09 rejects the same axis values under another code', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '110');

    $response = postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '110-1']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['axes']);

    expect($response->json('errors.axes.0'))->toContain('110')
        ->and(Combination::query()->count())->toBe(1);
});

it('E-10 rejects a combination without a value for an axis, naming the field', function () {
    $catalog = combCatalog();
    $payload = combPayload($catalog);
    unset($payload['axes'][$catalog['attrs']['Género']->id]);

    postCombination($this, combCreator(), $catalog, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["axes.{$catalog['attrs']['Género']->id}"]);

    $payload['axes'][$catalog['attrs']['Género']->id] = [];

    postCombination($this, combCreator(), $catalog, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["axes.{$catalog['attrs']['Género']->id}"]);

    expect(Combination::query()->count())->toBe(0);
});

it('E-10 rejects a fabric the product does not admit and a value of another attribute', function () {
    $catalog = combCatalog();
    $telaId = $catalog['attrs']['Tela']->id;

    $payload = combPayload($catalog);
    $payload['axes'][$telaId] = [$catalog['vals']['Algodón sin declarar']->id];

    postCombination($this, combCreator(), $catalog, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["axes.{$telaId}"]);

    $payload['axes'][$telaId] = [$catalog['vals']['Dama']->id];

    postCombination($this, combCreator(), $catalog, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["axes.{$telaId}"]);

    expect(Combination::query()->count())->toBe(0);
});

it('E-10 rejects values for an attribute that is not an axis of the product', function () {
    $catalog = combCatalog();
    $tallaId = $catalog['attrs']['Talla']->id;

    $payload = combPayload($catalog);
    $payload['axes'][$tallaId] = [$catalog['vals']['S']->id];

    postCombination($this, combCreator(), $catalog, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["axes.{$tallaId}"]);
});

it('E-48 rejects a combination that shares a value on every axis with an active one, naming it', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '159-1', [
        'Tela' => ['Drill', 'Gabardina'], 'Modelo' => ['Clásico'], 'Manga' => ['Manga larga'], 'Género' => ['Caballero'],
    ]);
    $drillCaballero = ['Tela' => ['Drill'], 'Modelo' => ['Clásico'], 'Manga' => ['Manga larga'], 'Género' => ['Caballero']];

    $response = postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '159-2'], $drillCaballero))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['axes']);

    expect($response->json('errors.axes.0'))->toContain('159-1')
        ->and(Combination::query()->count())->toBe(1);

    $gabardinaDama = ['Tela' => ['Gabardina'], 'Modelo' => ['Clásico'], 'Manga' => ['Manga larga'], 'Género' => ['Dama']];

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '159-3'], $gabardinaDama))->assertRedirect();

    expect(Combination::query()->count())->toBe(2);
});

it('DEC-PRD-39 ignores inactive combinations when checking the overlap', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '110', status: CatalogStatus::Inactive);

    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '110-1']))->assertRedirect();

    expect(Combination::query()->count())->toBe(2);
});

it('DEC-PRD-39 blocks an exact duplicate of the active axes in the database even when the overlap check is bypassed', function () {
    $catalog = combCatalog();
    $values = array_map(fn (string $name): AttributeValue => $catalog['vals'][$name], ['ALG-OXF Pima', 'Columbia especial', 'Manga corta', 'Caballero']);

    Combination::factory()->for($catalog['product'])->withAxes($values)->create();

    expect(fn () => Combination::factory()->for($catalog['product'])->withAxes($values)->create())
        ->toThrow(UniqueConstraintViolationException::class);

    // An inactive duplicate is allowed: only active combinations take part in the guarantee.
    Combination::factory()->for($catalog['product'])->inactive()->withAxes($values)->create();

    expect(Combination::query()->count())->toBe(2);
});

it('Decision 12 converts a duplicate-key failure of a concurrent write into the same field error', function () {
    $catalog = combCatalog();
    $values = array_map(fn (string $name): AttributeValue => $catalog['vals'][$name], ['ALG-OXF Pima', 'Columbia especial', 'Manga corta', 'Caballero']);
    $existing = Combination::factory()->for($catalog['product'])->withAxes($values)->create();
    CatalogCode::factory()->create(['combination_id' => $existing->id, 'code' => '110']);

    $capture = function (Closure $write): UniqueConstraintViolationException {
        try {
            $write();
        } catch (UniqueConstraintViolationException $exception) {
            return $exception;
        }

        throw new LogicException('The write did not violate a unique index.');
    };

    $duplicateSignature = $capture(fn () => Combination::factory()->for($catalog['product'])->withAxes($values)->create());
    $duplicateCode = $capture(fn () => CatalogCode::factory()->create(['combination_id' => Combination::factory()->for($catalog['product'])->create()->id, 'code' => '110']));

    expect(CombinationRules::duplicateKeyError($duplicateSignature)->errors())->toHaveKey('axes')
        ->and(CombinationRules::duplicateKeyError($duplicateCode)->errors())->toHaveKey('code');
});

it('N-2 allows a single active combination on a product without axes', function () {
    $product = Product::factory()->create();
    $catalog = ['product' => $product, 'attrs' => [], 'vals' => []];

    postCombination($this, combCreator(), $catalog, ['code' => 'U-1'])->assertRedirect();

    $second = postCombination($this, combCreator(), $catalog, ['code' => 'U-2'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['axes']);

    expect($second->json('errors.axes.0'))->toContain('U-1')
        ->and($product->combinations()->count())->toBe(1);
});

// --- Restrictions (E-56) ----------------------------------------------------------------------

it('E-56 stores a restriction to a subset of the admitted sizes and audits it by name', function () {
    $catalog = combCatalog();

    postCombination($this, combCreator(), $catalog, combPayload($catalog, restrictions: ['Talla' => ['M', 'S']]))->assertRedirect();

    $combination = Combination::query()->sole();

    expect($combination->values()->wherePivot('catalog_attribute_id', $catalog['attrs']['Talla']->id)->pluck('name')->sort()->values()->all())->toBe(['M', 'S'])
        ->and(combAudit(AuditAction::CombinationCreated)->sole()->new_values['restrictions'])->toBe(['Talla' => ['M', 'S']]);
});

it('E-56 rejects a restriction to sizes the product does not admit, naming the field', function () {
    $catalog = combCatalog();
    $tallaId = $catalog['attrs']['Talla']->id;

    postCombination($this, combCreator(), $catalog, combPayload($catalog, restrictions: ['Talla' => ['M', '2XL']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["restrictions.{$tallaId}"]);

    expect(Combination::query()->count())->toBe(0);
});

it('E-56 rejects a restriction on the color of a product with fabric, on an axis or on a foreign attribute', function () {
    $catalog = combCatalog();
    $azul = AttributeValue::factory()->for($catalog['attrs']['Color'], 'catalogAttribute')->withTone('#1F3A93')->create(['name' => 'Azul']);
    $catalog['vals']['Azul'] = $azul;

    postCombination($this, combCreator(), $catalog, combPayload($catalog, restrictions: ['Color' => ['Azul']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["restrictions.{$catalog['attrs']['Color']->id}"]);

    postCombination($this, combCreator(), $catalog, combPayload($catalog, restrictions: ['Manga' => ['Manga corta']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["restrictions.{$catalog['attrs']['Manga']->id}"]);

    $payload = combPayload($catalog);
    $payload['restrictions'][999999] = [1];

    postCombination($this, combCreator(), $catalog, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['restrictions.999999']);

    expect(Combination::query()->count())->toBe(0);
});

it('E-56 treats an empty restriction list as no restriction', function () {
    $catalog = combCatalog();
    $payload = combPayload($catalog);
    $payload['restrictions'][$catalog['attrs']['Talla']->id] = [];

    postCombination($this, combCreator(), $catalog, $payload)->assertRedirect();

    expect(Combination::query()->sole()->values()->count())->toBe(4);
});

it('E-56 lets a product without fabric restrict the color to a subset of its own list', function () {
    $color = CatalogAttribute::factory()->color()->create();
    $values = collect(['Azul' => '#1F3A93', 'Blanco' => '#FFFFFF', 'Verde' => '#3B7A57'])
        ->map(fn (string $tone, string $name): AttributeValue => AttributeValue::factory()->for($color, 'catalogAttribute')->withTone($tone)->create(['name' => $name]));
    $product = Product::factory()->create();
    ProductAttribute::factory()->create(['product_id' => $product->id, 'catalog_attribute_id' => $color->id, 'role' => AttributeRole::Order, 'sort_order' => 1])
        ->allowedValues()->attach([$values['Azul']->id, $values['Blanco']->id]);
    $catalog = ['product' => $product, 'attrs' => ['Color' => $color], 'vals' => $values->all()];

    postCombination($this, combCreator(), $catalog, ['code' => 'C-1', 'restrictions' => [$color->id => [$values['Azul']->id]]])->assertRedirect();

    postCombination($this, combCreator(), $catalog, ['code' => 'C-2', 'restrictions' => [$color->id => [$values['Verde']->id]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["restrictions.{$color->id}"]);
});

// --- Editing (E-68) ---------------------------------------------------------------------------

it('E-68 (combination) saves a new description with a changed-fields-only audit', function () {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '110-1');
    $actor = combEditor();

    $this->actingAs($actor)
        ->putJson("/products/{$catalog['product']->id}/combinations/{$combination->id}", combPayload($catalog, ['code' => '110-1', 'description' => 'Camisa de oficina']))
        ->assertRedirect();

    $audit = combAudit(AuditAction::CombinationUpdated)->sole();

    expect($combination->fresh()->description)->toBe('Camisa de oficina')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($combination->id)
        ->and($audit->old_values)->toBe(['description' => null])
        ->and($audit->new_values)->toBe(['description' => 'Camisa de oficina']);
});

it('E-68 (combination) writes no audit row when nothing changed', function () {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '110-1', restrictions: ['Talla' => ['S', 'M']]);
    $updatedAt = $combination->fresh()->updated_at;

    $this->travel(5)->minutes();
    $this->actingAs(combEditor())
        ->putJson("/products/{$catalog['product']->id}/combinations/{$combination->id}", combPayload($catalog, ['code' => '110-1'], restrictions: ['Talla' => ['M', 'S']]))
        ->assertRedirect();

    expect(combAudit(AuditAction::CombinationUpdated))->toHaveCount(0)
        ->and($combination->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('E-68 (combination) rejects a code already used by another combination and changes nothing', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '110');
    $combination = makeCombination($catalog, '110-1', combAxes('Manga larga', 'Dama'));

    $this->actingAs(combEditor())
        ->putJson("/products/{$catalog['product']->id}/combinations/{$combination->id}", combPayload($catalog, ['code' => '110'], combAxes('Manga larga', 'Dama')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect($combination->fresh()->code)->toBe('110-1')
        ->and(combAudit(AuditAction::CombinationUpdated))->toHaveCount(0);
});

it('E-68 (combination) lets a combination keep its own code, even changing the letter case', function () {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '001RN');

    $this->actingAs(combEditor())
        ->putJson("/products/{$catalog['product']->id}/combinations/{$combination->id}", combPayload($catalog, ['code' => '001rn']))
        ->assertRedirect();

    $audit = combAudit(AuditAction::CombinationUpdated)->sole();

    expect($combination->fresh()->code)->toBe('001rn')
        ->and($audit->old_values)->toBe(['code' => '001RN'])
        ->and($audit->new_values)->toBe(['code' => '001rn']);
});

it('E-68 (combination) edits axes and restrictions, recalculates the signature and audits only the changes', function () {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '110');

    $this->actingAs(combEditor())
        ->putJson("/products/{$catalog['product']->id}/combinations/{$combination->id}", combPayload($catalog, axes: combAxes('Manga larga', 'Dama'), restrictions: ['Talla' => ['S']]))
        ->assertRedirect();

    $audit = combAudit(AuditAction::CombinationUpdated)->sole();

    expect($audit->old_values)->toEqual(['axes' => ['Tela' => ['ALG-OXF Pima'], 'Modelo' => ['Columbia especial'], 'Manga' => ['Manga corta'], 'Género' => ['Caballero']], 'restrictions' => []])
        ->and($audit->new_values)->toEqual(['axes' => ['Tela' => ['ALG-OXF Pima'], 'Modelo' => ['Columbia especial'], 'Manga' => ['Manga larga'], 'Género' => ['Dama']], 'restrictions' => ['Talla' => ['S']]])
        ->and(ProductPresenter::combinationName($combination->fresh()))->toBe('Camisa corporativa · ALG-OXF Pima · Columbia especial · Manga larga · Dama');

    // The old axes are free again: a new combination can take them.
    postCombination($this, combCreator(), $catalog, combPayload($catalog, ['code' => '110-2']))->assertRedirect();
});

it('E-48 rejects editing the axes of an active combination so that it overlaps another one', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '110');
    $combination = makeCombination($catalog, '110-1', combAxes('Manga larga', 'Dama'));

    $response = $this->actingAs(combEditor())
        ->putJson("/products/{$catalog['product']->id}/combinations/{$combination->id}", combPayload($catalog, ['code' => '110-1']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['axes']);

    expect($response->json('errors.axes.0'))->toContain('110')
        ->and($combination->fresh()->values()->count())->toBe(4)
        ->and(combAudit(AuditAction::CombinationUpdated))->toHaveCount(0);
});

it('E-59 lets an inactive combination be edited into overlapping axes and rejects it on activation', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '110');
    $combination = makeCombination($catalog, '110-4', combAxes('Manga larga', 'Dama'), status: CatalogStatus::Inactive);

    $this->actingAs(combEditor())
        ->putJson("/products/{$catalog['product']->id}/combinations/{$combination->id}", combPayload($catalog, ['code' => '110-4']))
        ->assertRedirect();

    $this->actingAs(combDeactivator())
        ->postJson("/products/{$catalog['product']->id}/combinations/{$combination->id}/activate")
        ->assertUnprocessable();

    expect($combination->fresh()->status)->toBe(CatalogStatus::Inactive);
});

it('PRD-012 validates the same rules on edit as on creation', function () {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '110');
    $payload = combPayload($catalog, ['code' => '110'], restrictions: ['Talla' => ['M', '2XL']]);
    $url = "/products/{$catalog['product']->id}/combinations/{$combination->id}";

    $this->actingAs(combEditor())->putJson($url, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["restrictions.{$catalog['attrs']['Talla']->id}"]);

    unset($payload['restrictions'], $payload['axes'][$catalog['attrs']['Género']->id]);

    $this->actingAs(combEditor())->putJson($url, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["axes.{$catalog['attrs']['Género']->id}"]);

    expect(combAudit(AuditAction::CombinationUpdated))->toHaveCount(0);
});

// --- Lifecycle (E-59, PRD-013) ----------------------------------------------------------------

it('E-59 rejects reactivating a combination that overlaps an active one, naming it, and writes no audit', function () {
    $catalog = combCatalog();
    makeCombination($catalog, '110');
    $inactive = makeCombination($catalog, '110-4', status: CatalogStatus::Inactive);

    $response = $this->actingAs(combDeactivator())
        ->postJson("/products/{$catalog['product']->id}/combinations/{$inactive->id}/activate")
        ->assertUnprocessable();

    expect($response->json('message'))->toContain('110')
        ->and($inactive->fresh()->status)->toBe(CatalogStatus::Inactive)
        ->and(combAudit(AuditAction::CombinationActivated))->toHaveCount(0);
});

it('PRD-013 deactivates and reactivates a combination auditing the status, and the other combinations are untouched', function () {
    $catalog = combCatalog();
    $target = makeCombination($catalog, '110');
    $other = makeCombination($catalog, '110-1', combAxes('Manga larga', 'Dama'));
    $actor = combDeactivator();
    $base = "/products/{$catalog['product']->id}/combinations/{$target->id}";

    $this->actingAs($actor)->postJson("{$base}/deactivate")->assertRedirect();

    expect($target->fresh()->status)->toBe(CatalogStatus::Inactive)
        ->and($target->fresh()->active_signature)->toBeNull()
        ->and($other->fresh()->status)->toBe(CatalogStatus::Active);

    $this->actingAs($actor)->postJson("{$base}/activate")->assertRedirect();

    [$deactivated] = combAudit(AuditAction::CombinationDeactivated)->all();
    [$activated] = combAudit(AuditAction::CombinationActivated)->all();

    expect($target->fresh()->status)->toBe(CatalogStatus::Active)
        ->and($target->fresh()->active_signature)->toBe($target->axis_signature)
        ->and($deactivated->actor_id)->toBe($actor->id)
        ->and($deactivated->entity_id)->toBe($target->id)
        ->and($deactivated->old_values)->toBe(['status' => 'active'])
        ->and($deactivated->new_values)->toBe(['status' => 'inactive'])
        ->and($activated->old_values)->toBe(['status' => 'inactive'])
        ->and($activated->new_values)->toBe(['status' => 'active']);
});

it('PRD-013 no-op: repeating the state of a combination writes nothing and no audit', function (string $action, CatalogStatus $state) {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '110', status: $state);
    $updatedAt = $combination->fresh()->updated_at;

    $this->travel(5)->minutes();
    $this->actingAs(combDeactivator())
        ->postJson("/products/{$catalog['product']->id}/combinations/{$combination->id}/{$action}")
        ->assertRedirect();

    expect($combination->fresh()->status)->toBe($state)
        ->and($combination->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(combAudit(AuditAction::CombinationActivated, AuditAction::CombinationDeactivated))->toHaveCount(0);
})->with([
    'activate an active combination' => ['activate', CatalogStatus::Active],
    'deactivate an inactive combination' => ['deactivate', CatalogStatus::Inactive],
]);

// --- Authorization (PRD-016) ------------------------------------------------------------------

it('PRD-016 requires products.create to create a combination, auditing the denial', function () {
    $catalog = combCatalog();
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate, PermissionName::ProductsDelete, PermissionName::ProductsCatalog);

    postCombination($this, $actor, $catalog, combPayload($catalog))->assertForbidden();

    expect(Combination::query()->count())->toBe(0)
        ->and(CatalogCode::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->context['route'])->toBe('products.combinations.store');
});

it('PRD-016 requires products.update to edit a combination', function () {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '110');
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsDeactivate, PermissionName::ProductsDelete, PermissionName::ProductsCatalog);

    $this->actingAs($actor)
        ->putJson("/products/{$catalog['product']->id}/combinations/{$combination->id}", combPayload($catalog, ['code' => '999']))
        ->assertForbidden();

    expect($combination->fresh()->code)->toBe('110');
});

it('PRD-016 requires products.deactivate to activate or deactivate a combination', function (string $action, CatalogStatus $initial) {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '110', status: $initial);
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDelete, PermissionName::ProductsCatalog);

    $this->actingAs($actor)
        ->postJson("/products/{$catalog['product']->id}/combinations/{$combination->id}/{$action}")
        ->assertForbidden();

    expect($combination->fresh()->status)->toBe($initial)
        ->and(combAudit(AuditAction::CombinationActivated, AuditAction::CombinationDeactivated))->toHaveCount(0);
})->with([
    'deactivate' => ['deactivate', CatalogStatus::Active],
    'activate' => ['activate', CatalogStatus::Inactive],
]);

it('PRD-005 returns 404 for an unknown product or a combination of another product', function () {
    $catalog = combCatalog();
    $combination = makeCombination($catalog, '110');
    $otherProduct = Product::factory()->create();

    $this->actingAs(combEditor())
        ->putJson("/products/{$otherProduct->id}/combinations/{$combination->id}", combPayload($catalog))
        ->assertNotFound();

    $this->actingAs(combDeactivator())
        ->postJson("/products/{$otherProduct->id}/combinations/{$combination->id}/deactivate")
        ->assertNotFound();

    $this->actingAs(combCreator())->postJson('/products/999999/combinations', combPayload($catalog))->assertNotFound();

    expect($combination->fresh()->status)->toBe(CatalogStatus::Active);
});
