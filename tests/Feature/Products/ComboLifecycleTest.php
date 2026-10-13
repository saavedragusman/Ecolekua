<?php

use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Policies\ComboPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

// --- Creation (E-21) --------------------------------------------------------------------------

it('DEC-PRD-64 keeps on edit a component whose product was deactivated after it was added', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $catalog['products']['Protector de cama']->forceFill(['status' => CatalogStatus::Inactive])->save();

    // The held component is resent unchanged (and with another quantity); the combo is saved.
    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [
            comboComponent($catalog, 'Pañal antiderrame', 2, ['Talla' => ['3XG', '4XG', '5XG']]),
            comboComponent($catalog, 'Absorbente', 3),
            comboComponent($catalog, 'Protector de cama', 4),
        ],
    ]))->assertRedirect("/combos/{$combo->id}");

    expect($combo->fresh()->components->pluck('quantity')->all())->toBe([2, 3, 4]);
});

it('DEC-PRD-64 rejects on edit adding a component with an inactive product and changes nothing', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $catalog['products']['Pañal ecológico']->forceFill(['status' => CatalogStatus::Inactive])->save();

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [
            comboComponent($catalog, 'Absorbente', 3),
            comboComponent($catalog, 'Pañal ecológico', 1),
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.1.product_id']);

    expect($combo->fresh()->components)->toHaveCount(3)
        ->and(comboAudit(AuditAction::ComboUpdated))->toHaveCount(0);
});

// --- Invalid components (E-22, E-62) ----------------------------------------------------------

it('E-62 rejects a component of another line on edit and leaves the combo unchanged', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $before = $combo->components->pluck('product_id')->all();

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'components' => [comboComponent($catalog, 'Absorbente', 1), comboComponent($catalog, 'Camisa corporativa', 1)],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.1.product_id']);

    expect($combo->fresh()->components->pluck('product_id')->all())->toBe($before)
        ->and(comboAudit(AuditAction::ComboUpdated))->toHaveCount(0);
});

// --- Name and code (E-64, E-08, DT-01) --------------------------------------------------------

it('E-64 (combo) rejects a repeated name in other letter case on create and on edit', function () {
    $catalog = comboCatalog();
    $first = makeCombo($catalog);

    postCombo($this, comboCreator(), comboPayload($catalog, ['name' => 'kit oro ANTIDERRAME', 'code' => 'K-02']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $second = makeCombo($catalog, ['name' => 'Kit Plata', 'code' => 'K-03']);

    putCombo($this, comboEditor(), $second, comboPayload($catalog, ['name' => 'KIT ORO antiderrame', 'code' => 'K-03']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(Combo::query()->count())->toBe(2)
        ->and($second->fresh()->name)->toBe('Kit Plata')
        ->and($first->fresh()->name)->toBe('Kit Oro antiderrame');
});

it('E-64 (combo) lets a combo change the letter case of its own name and treats accents as different', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, ['name' => 'KIT ORO ANTIDERRAME']))->assertRedirect();

    expect($combo->fresh()->name)->toBe('KIT ORO ANTIDERRAME');

    postCombo($this, comboCreator(), comboPayload($catalog, ['name' => 'Kít Oro antiderrame', 'code' => 'K-02']))->assertRedirect();

    expect(Combo::query()->count())->toBe(2);
});

it('E-08 (combo) rejects the code of another combo and lets a combo keep or change its own code', function () {
    $catalog = comboCatalog();
    $first = makeCombo($catalog);
    $second = makeCombo($catalog, ['name' => 'Kit Plata', 'code' => 'K-02']);

    putCombo($this, comboEditor(), $second, comboPayload($catalog, ['name' => 'Kit Plata', 'code' => 'k-oro']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    putCombo($this, comboEditor(), $second, comboPayload($catalog, ['name' => 'Kit Plata', 'code' => 'K-02']))->assertRedirect();
    putCombo($this, comboEditor(), $second, comboPayload($catalog, ['name' => 'Kit Plata', 'code' => 'K-PLATA']))->assertRedirect();

    expect($second->fresh()->code)->toBe('K-PLATA')
        ->and($first->fresh()->code)->toBe('K-ORO');
});

// --- Editing (PRD-012) ------------------------------------------------------------------------

it('PRD-012 replaces the components of a combo and audits only the fields that changed', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $actor = comboEditor();

    putCombo($this, $actor, $combo, comboPayload($catalog, [
        'components' => [
            comboComponent($catalog, 'Pañal antiderrame', 2, ['Talla' => ['3XG', '4XG']]),
            comboComponent($catalog, 'Protector de cama', 2),
        ],
    ]))->assertRedirect("/combos/{$combo->id}");

    $combo = $combo->fresh();
    $audit = comboAudit(AuditAction::ComboUpdated)->sole();

    expect($combo->components->map(fn (ComboComponent $component): array => [$component->product->name, $component->quantity, $component->sort_order])->all())->toBe([
        ['Pañal antiderrame', 2, 1],
        ['Protector de cama', 2, 2],
    ])
        ->and(DB::table('combo_component_values')->count())->toBe(2)
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($combo->id)
        ->and(array_keys($audit->old_values))->toBe(['components'])
        ->and($audit->old_values['components'])->toEqual([
            ['product' => 'Pañal antiderrame', 'quantity' => 2, 'values' => ['Talla' => ['3XG', '4XG', '5XG']]],
            ['product' => 'Absorbente', 'quantity' => 3, 'values' => []],
            ['product' => 'Protector de cama', 'quantity' => 1, 'values' => []],
        ])
        ->and($audit->new_values['components'])->toEqual([
            ['product' => 'Pañal antiderrame', 'quantity' => 2, 'values' => ['Talla' => ['3XG', '4XG']]],
            ['product' => 'Protector de cama', 'quantity' => 2, 'values' => []],
        ]);
});

it('PRD-012 audits a renamed combo with the previous and new name only', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, ['name' => 'Kit Oro plus', 'portal_visible' => false]))->assertRedirect();

    $audit = comboAudit(AuditAction::ComboUpdated)->sole();

    expect($audit->old_values)->toEqual(['name' => 'Kit Oro antiderrame', 'portal_visible' => true])
        ->and($audit->new_values)->toEqual(['name' => 'Kit Oro plus', 'portal_visible' => false]);
});

it('PRD-012 writes no audit row and keeps updated_at when the combo is saved unchanged', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $updatedAt = $combo->fresh()->updated_at;

    $this->travel(5)->minutes();
    putCombo($this, comboEditor(), $combo, comboPayload($catalog))->assertRedirect();

    expect(comboAudit(AuditAction::ComboUpdated))->toHaveCount(0)
        ->and($combo->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($combo->fresh()->components)->toHaveCount(3);
});

it('PRD-012 keeps portal visibility when it is not sent', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog, ['portal_visible' => false]);

    putCombo($this, comboEditor(), $combo, comboPayload($catalog))->assertRedirect();

    expect($combo->fresh()->portal_visible)->toBeFalse()
        ->and(comboAudit(AuditAction::ComboUpdated))->toHaveCount(0);
});

it('PRD-012 applies the component rules on edit and changes nothing when one fails', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);

    putCombo($this, comboEditor(), $combo, comboPayload($catalog, [
        'name' => 'Kit renombrado',
        'components' => [comboComponent($catalog, 'Pañal antiderrame', 1, ['Talla' => ['2XG']])],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.values.'.$catalog['attrs']['Talla']->id]);

    expect($combo->fresh()->name)->toBe('Kit Oro antiderrame')
        ->and($combo->fresh()->components)->toHaveCount(3)
        ->and(comboAudit(AuditAction::ComboUpdated))->toHaveCount(0);
});

// --- Lifecycle (PRD-013) ----------------------------------------------------------------------

it('PRD-013 deactivates and reactivates a combo and audits the status change', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $actor = comboDeactivator();

    $this->actingAs($actor)->postJson("/combos/{$combo->id}/deactivate")->assertRedirect();

    $deactivated = comboAudit(AuditAction::ComboDeactivated)->sole();

    expect($combo->fresh()->status)->toBe(CatalogStatus::Inactive)
        ->and($deactivated->actor_id)->toBe($actor->id)
        ->and($deactivated->old_values)->toBe(['status' => 'active'])
        ->and($deactivated->new_values)->toBe(['status' => 'inactive'])
        // The code stays reserved and the components are untouched.
        ->and($combo->fresh()->code)->toBe('K-ORO')
        ->and($combo->fresh()->components)->toHaveCount(3);

    $this->actingAs($actor)->postJson("/combos/{$combo->id}/activate")->assertRedirect();

    $activated = comboAudit(AuditAction::ComboActivated)->sole();

    expect($combo->fresh()->status)->toBe(CatalogStatus::Active)
        ->and($activated->old_values)->toBe(['status' => 'inactive'])
        ->and($activated->new_values)->toBe(['status' => 'active']);
});

it('PRD-013 changes nothing and audits nothing when the combo already has the requested status', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $updatedAt = $combo->fresh()->updated_at;

    $this->travel(5)->minutes();
    $this->actingAs(comboDeactivator())->postJson("/combos/{$combo->id}/activate")->assertRedirect();

    expect(comboAudit(AuditAction::ComboActivated))->toHaveCount(0)
        ->and($combo->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();

    $this->actingAs(comboDeactivator())->postJson("/combos/{$combo->id}/deactivate")->assertRedirect();
    $this->actingAs(comboDeactivator())->postJson("/combos/{$combo->id}/deactivate")->assertRedirect();

    expect(comboAudit(AuditAction::ComboDeactivated))->toHaveCount(1);
});

// --- Authorization (PRD-016) ------------------------------------------------------------------

it('PRD-016 requires products.update to edit a combo', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsDeactivate, PermissionName::ProductsDelete, PermissionName::ProductsCatalog);

    putCombo($this, $actor, $combo, comboPayload($catalog, ['name' => 'Kit renombrado']))->assertForbidden();

    expect($combo->fresh()->name)->toBe('Kit Oro antiderrame')
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->context['route'])->toBe('combos.update');
});

it('PRD-016 requires products.deactivate to activate or deactivate a combo', function (string $action, CatalogStatus $initial) {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $combo->forceFill(['status' => $initial])->save();
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDelete, PermissionName::ProductsCatalog);

    $this->actingAs($actor)->postJson("/combos/{$combo->id}/{$action}")->assertForbidden();

    expect($combo->fresh()->status)->toBe($initial)
        ->and(comboAudit(AuditAction::ComboActivated, AuditAction::ComboDeactivated))->toHaveCount(0);
})->with([
    'deactivate' => ['deactivate', CatalogStatus::Active],
    'activate' => ['activate', CatalogStatus::Inactive],
]);

it('PRD-016 discovers ComboPolicy and maps each ability to its permission', function (string $ability, PermissionName $permission) {
    $combo = Combo::factory()->create();
    $subject = in_array($ability, ['viewAny', 'create'], true) ? Combo::class : $combo;
    $holder = userWithPermissions($permission);
    $outsider = userWithPermissions(...collect(PermissionName::cases())->filter(fn (PermissionName $case): bool => $case !== $permission)->all());

    expect(Gate::getPolicyFor(Combo::class))->toBeInstanceOf(ComboPolicy::class)
        ->and(Gate::forUser($holder)->allows($ability, $subject))->toBeTrue()
        ->and(Gate::forUser($outsider)->denies($ability, $subject))->toBeTrue();
})->with([
    'viewAny' => ['viewAny', PermissionName::ProductsView],
    'view' => ['view', PermissionName::ProductsView],
    'create' => ['create', PermissionName::ProductsCreate],
    'update' => ['update', PermissionName::ProductsUpdate],
    'deactivate' => ['deactivate', PermissionName::ProductsDeactivate],
    'delete' => ['delete', PermissionName::ProductsDelete],
]);

it('PRD-010 returns 404 for an unknown combo', function () {
    $catalog = comboCatalog();

    $this->actingAs(comboEditor())->putJson('/combos/999999', comboPayload($catalog))->assertNotFound();
    $this->actingAs(comboDeactivator())->postJson('/combos/999999/deactivate')->assertNotFound();
    $this->actingAs(comboDeactivator())->postJson('/combos/999999/activate')->assertNotFound();
});
