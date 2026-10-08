<?php

use App\Actions\Products\CreateCombo;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Combo;
use App\Models\ComboComponent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// --- Creation (E-21) --------------------------------------------------------------------------

it('E-21 creates "Kit Oro antiderrame" active with three components, a code and a created audit row', function () {
    $catalog = comboCatalog();
    $actor = comboCreator();

    $response = postCombo($this, $actor, comboPayload($catalog));

    $combo = Combo::query()->sole();

    expect(parse_url($response->assertRedirect()->headers->get('Location'), PHP_URL_PATH))->toBe("/combos/{$combo->id}")
        ->and($combo->name)->toBe('Kit Oro antiderrame')
        ->and($combo->code)->toBe('K-ORO')
        ->and($combo->status)->toBe(CatalogStatus::Active)
        ->and($combo->portal_visible)->toBeTrue()
        ->and($combo->components->map(fn (ComboComponent $component): array => [$component->product->name, $component->quantity, $component->sort_order])->all())->toBe([
            ['Pañal antiderrame', 2, 1],
            ['Absorbente', 3, 2],
            ['Protector de cama', 1, 3],
        ]);

    $audit = comboAudit(AuditAction::ComboCreated)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($combo->id)
        ->and($audit->old_values)->toBe([])
        ->and($audit->new_values)->toEqual([ // toEqual: the JSON column does not keep the key order
            'code' => 'K-ORO',
            'name' => 'Kit Oro antiderrame',
            'portal_visible' => true,
            'components' => [
                ['product' => 'Pañal antiderrame', 'quantity' => 2, 'values' => ['Talla' => ['3XG', '4XG', '5XG']]],
                ['product' => 'Absorbente', 'quantity' => 3, 'values' => []],
                ['product' => 'Protector de cama', 'quantity' => 1, 'values' => []],
            ],
        ]);
});

it('E-21 stores the values each component admits and none for an attribute left unrestricted', function () {
    $catalog = comboCatalog();

    postCombo($this, comboCreator(), comboPayload($catalog, [
        'name' => 'Kit juvenil',
        'code' => 'K-JUV',
        'portal_visible' => false,
        'components' => [
            comboComponent($catalog, 'Pañal antiderrame', 1, ['Talla' => ['4XG']]),
            comboComponent($catalog, 'Absorbente', 1, ['Talla' => ['3XG'], 'Color' => ['Blanco']]),
            comboComponent($catalog, 'Protector de cama', 6),
        ],
    ]))->assertRedirect();

    $combo = Combo::query()->sole();
    [$diaper, $absorbent, $protector] = $combo->components->all();
    $admitted = fn (ComboComponent $component): array => $component->values()->orderBy('attribute_values.id')->pluck('name')->all();

    expect($combo->portal_visible)->toBeFalse()
        ->and($admitted($diaper))->toBe(['4XG'])
        ->and($admitted($absorbent))->toBe(['3XG', 'Blanco'])
        ->and($admitted($protector))->toBe([])
        ->and(DB::table('combo_component_values')->where('combo_component_id', $absorbent->id)->pluck('catalog_attribute_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect([$catalog['attrs']['Talla']->id, $catalog['attrs']['Color']->id])->sort()->values()->all());
});

it('N-3 accepts the same product in two components with different restrictions', function () {
    $catalog = comboCatalog();

    postCombo($this, comboCreator(), comboPayload($catalog, [
        'components' => [
            comboComponent($catalog, 'Absorbente', 3, ['Talla' => ['3XG']]),
            comboComponent($catalog, 'Absorbente', 3, ['Talla' => ['4XG']]),
        ],
    ]))->assertRedirect();

    expect(Combo::query()->sole()->components->pluck('product_id')->all())->toBe([
        $catalog['products']['Absorbente']->id,
        $catalog['products']['Absorbente']->id,
    ]);
});

it('DEC-PRD-64 rejects an inactive product as a component on create and saves nothing', function () {
    $catalog = comboCatalog();
    $catalog['products']['Protector de cama']->forceFill(['status' => CatalogStatus::Inactive])->save();

    postCombo($this, comboCreator(), comboPayload($catalog))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.2.product_id']);

    expectNoComboWritten();
});

// --- Invalid components (E-22, E-62) ----------------------------------------------------------

it('E-22 rejects an invalid component with the error on its field and saves nothing', function (string $case, string $field) {
    $catalog = comboCatalog();
    $azul = $catalog['vals']['Azul']->id;

    $component = match ($case) {
        'quantity zero' => comboComponent($catalog, 'Absorbente', 0),
        'quantity above the maximum' => comboComponent($catalog, 'Absorbente', 1000),
        'quantity not an integer' => [...comboComponent($catalog, 'Absorbente'), 'quantity' => '2.5'],
        'quantity missing' => Arr::except(comboComponent($catalog, 'Absorbente'), 'quantity'),
        'service product' => comboComponent($catalog, 'Bordado pequeño'),
        'unknown product' => [...comboComponent($catalog, 'Absorbente'), 'product_id' => 999_999],
        'value the product does not admit' => comboComponent($catalog, 'Pañal antiderrame', 1, ['Talla' => ['2XG']]),
        'value of another attribute' => [...comboComponent($catalog, 'Absorbente'), 'values' => [$catalog['attrs']['Talla']->id => [$azul]]],
        'attribute the product does not declare' => comboComponent($catalog, 'Protector de cama', 1, ['Talla' => ['3XG']]),
        'color no fabric offers' => comboComponent($catalog, 'Pañal ecológico', 1, ['Color' => ['Azul']]),
        'color of the product list that is not on it' => comboComponent($catalog, 'Absorbente', 1, ['Color' => ['Crema']]),
    };

    // `values:Talla` stands for the error on the restriction of that attribute.
    $expected = str_starts_with($field, 'values:')
        ? 'components.0.values.'.$catalog['attrs'][substr($field, 7)]->id
        : $field;

    postCombo($this, comboCreator(), comboPayload($catalog, ['components' => [$component]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$expected]);

    expectNoComboWritten();
})->with([
    'quantity zero' => ['quantity zero', 'components.0.quantity'],
    'quantity above the maximum' => ['quantity above the maximum', 'components.0.quantity'],
    'quantity not an integer' => ['quantity not an integer', 'components.0.quantity'],
    'quantity missing' => ['quantity missing', 'components.0.quantity'],
    'service product' => ['service product', 'components.0.product_id'],
    'unknown product' => ['unknown product', 'components.0.product_id'],
    'value the product does not admit' => ['value the product does not admit', 'values:Talla'],
    'value of another attribute' => ['value of another attribute', 'values:Talla'],
    'attribute the product does not declare' => ['attribute the product does not declare', 'values:Talla'],
    'color no fabric offers' => ['color no fabric offers', 'values:Color'],
    'color of the product list that is not on it' => ['color of the product list that is not on it', 'values:Color'],
]);

it('E-22 accepts the colors offered by any fabric of the product and the colors of its own list', function () {
    $catalog = comboCatalog();

    postCombo($this, comboCreator(), comboPayload($catalog, [
        'components' => [
            // Crema is offered by Algodón only; Blanco by both fabrics.
            comboComponent($catalog, 'Pañal ecológico', 1, ['Color' => ['Blanco', 'Crema']]),
            comboComponent($catalog, 'Absorbente', 1, ['Color' => ['Azul']]),
        ],
    ]))->assertRedirect();

    $color = $catalog['attrs']['Color']->id;

    expect(DB::table('combo_component_values')->where('catalog_attribute_id', $color)->count())->toBe(3);
});

it('E-22 reports every component that fails a product rule at once and only those', function () {
    $catalog = comboCatalog();

    postCombo($this, comboCreator(), comboPayload($catalog, [
        'components' => [
            comboComponent($catalog, 'Bordado pequeño'),
            comboComponent($catalog, 'Protector de cama'),
            comboComponent($catalog, 'Camisa corporativa'),
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.product_id', 'components.2.product_id'])
        ->assertJsonMissingValidationErrors(['components.1.product_id']);

    expectNoComboWritten();
});

it('E-22 requires at least one component and a well formed list', function (mixed $components) {
    $catalog = comboCatalog();

    postCombo($this, comboCreator(), comboPayload($catalog, ['components' => $components]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components']);

    expectNoComboWritten();
})->with([
    'empty list' => [[]],
    'not a list' => ['Absorbente'],
    'null' => [null],
]);

it('E-22 rejects a component that is not an object', function () {
    $catalog = comboCatalog();

    postCombo($this, comboCreator(), comboPayload($catalog, ['components' => ['Absorbente']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0']);

    expectNoComboWritten();
});

it('E-62 rejects a product of the uniforms line as a component and creates nothing', function () {
    $catalog = comboCatalog();

    postCombo($this, comboCreator(), comboPayload($catalog, [
        'components' => [
            comboComponent($catalog, 'Pañal antiderrame', 2),
            comboComponent($catalog, 'Camisa corporativa', 1),
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.1.product_id'])
        ->assertJsonMissingValidationErrors(['components.0.product_id']);

    expectNoComboWritten();
});

// --- Name and code (E-64, E-08, DT-01) --------------------------------------------------------

it('E-64 (combo) turns a duplicate name or code that slipped past the request rules into the same field error', function () {
    $catalog = comboCatalog();
    makeCombo($catalog);
    $keyOf = function (array $overrides) use ($catalog): array {
        try {
            app(CreateCombo::class)->handle(comboPayload($catalog, $overrides), comboCreator());
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        return [];
    };

    // The Action is called directly, so only the unique indexes can stop these two.
    expect($keyOf(['name' => 'KIT ORO ANTIDERRAME', 'code' => 'K-02']))->toBe(['name'])
        ->and($keyOf(['name' => 'Kit Plata', 'code' => 'k-oro']))->toBe(['code'])
        ->and(Combo::query()->count())->toBe(1)
        ->and(CatalogCode::query()->whereNotNull('combo_id')->count())->toBe(1);
});

it('E-64 (combo) requires a name of at most 150 characters', function (mixed $name) {
    $catalog = comboCatalog();

    postCombo($this, comboCreator(), comboPayload($catalog, ['name' => $name]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expectNoComboWritten();
})->with(['missing' => [null], 'blank' => ['   '], 'too long' => [str_repeat('K', 151)]]);

it('E-08 (combo) rejects a combo code equal to a combination code in any letter case', function (string $code) {
    $catalog = comboCatalog();
    $combination = Combination::factory()->for($catalog['products']['Pañal antiderrame'])->create();
    CatalogCode::factory()->create(['combination_id' => $combination->id, 'code' => '001RN']);

    postCombo($this, comboCreator(), comboPayload($catalog, ['code' => $code]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expectNoComboWritten();
})->with(['same case' => ['001RN'], 'other case' => ['001rn']]);

it('E-08 (combo) rejects a combination code equal to the code of a combo', function () {
    $catalog = comboCatalog();
    makeCombo($catalog);
    $product = $catalog['products']['Pañal antiderrame'];

    $this->actingAs(comboCreator())
        ->postJson("/products/{$product->id}/combinations", [
            'code' => 'k-oro',
            'axes' => [$catalog['attrs']['Talla']->id => comboIds($catalog, '3XG')],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect(Combination::query()->count())->toBe(0);
});

it('DT-01 (combo) trims the code, keeps it as typed and rejects spaces inside, blanks and more than 30 characters', function () {
    $catalog = comboCatalog();

    foreach (['K 01', '', str_repeat('K', 31)] as $code) {
        postCombo($this, comboCreator(), comboPayload($catalog, ['code' => $code]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    expectNoComboWritten();

    postCombo($this, comboCreator(), comboPayload($catalog, ['code' => '  001Kit-A  ']))->assertRedirect();

    expect(Combo::query()->sole()->code)->toBe('001Kit-A');
});

// --- Authorization (PRD-016) ------------------------------------------------------------------

it('PRD-016 requires products.create to create a combo, auditing the denial', function () {
    $catalog = comboCatalog();
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate, PermissionName::ProductsDelete, PermissionName::ProductsCatalog);

    postCombo($this, $actor, comboPayload($catalog))->assertForbidden();

    expectNoComboWritten();
    expect(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->context['route'])->toBe('combos.store');
});
