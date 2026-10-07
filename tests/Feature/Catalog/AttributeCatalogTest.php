<?php

use App\Actions\Products\CreateAttributeValue;
use App\Actions\Products\CreateCatalogAttribute;
use App\Actions\Products\MoveCatalogItem;
use App\Actions\Products\UpdateCatalogAttribute;
use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function attributeManager(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCatalog);
}

/**
 * @return Collection<int, AuditLog>
 */
function attributeAuditRows(AuditAction ...$actions): Collection
{
    return AuditLog::query()
        ->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))
        ->orderBy('id')
        ->get();
}

/**
 * @return list<int>
 */
function valueIdsInOrder(CatalogAttribute $attribute): array
{
    return AttributeValue::query()
        ->where('catalog_attribute_id', $attribute->id)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

// --- Attributes: creation, presentation and special uses -------------------------------------

it('PRD-002 creates an attribute at the end of the order and audits catalog.created', function () {
    $actor = attributeManager();
    CatalogAttribute::factory()->create(['sort_order' => 4]);

    $this->actingAs($actor)
        ->postJson('/catalog/attributes', ['name' => '  Manga  ', 'presentation' => 'text', 'special_use' => null])
        ->assertRedirect();

    $attribute = CatalogAttribute::query()->where('name', 'Manga')->sole();

    expect($attribute->sort_order)->toBe(5)
        ->and($attribute->presentation)->toBe(AttributePresentation::Text)
        ->and($attribute->special_use)->toBeNull()
        ->and($attribute->status)->toBe(CatalogStatus::Active);

    $audit = attributeAuditRows(AuditAction::CatalogCreated)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($attribute->id)
        ->and($audit->new_values)->toEqual([
            'name' => 'Manga',
            'presentation' => 'text',
            'special_use' => null,
            'sort_order' => 5,
            'status' => 'active',
        ]);
});

it('PRD-002 requires a name of at most 60 characters, unique ignoring letter case, and a known presentation', function (array $payload) {
    CatalogAttribute::factory()->create(['name' => 'Manga']);

    $this->actingAs(attributeManager())
        ->postJson('/catalog/attributes', $payload + ['special_use' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(CatalogAttribute::query()->count())->toBe(1);
})->with([
    'missing name' => [['presentation' => 'text']],
    'too long' => [['name' => str_repeat('a', 61), 'presentation' => 'text']],
    'same name other case' => [['name' => 'manga', 'presentation' => 'text']],
]);

it('PRD-002 rejects an unknown presentation', function () {
    $this->actingAs(attributeManager())
        ->postJson('/catalog/attributes', ['name' => 'Manga', 'presentation' => 'video', 'special_use' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['presentation']);
});

it('E-45 rejects marking a second attribute as the fabric attribute', function () {
    CatalogAttribute::factory()->fabric()->create();

    $this->actingAs(attributeManager())
        ->postJson('/catalog/attributes', ['name' => 'Otra tela', 'presentation' => 'text', 'special_use' => 'fabric'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['special_use']);

    $other = CatalogAttribute::factory()->create(['name' => 'Modelo']);

    $this->actingAs(attributeManager())
        ->putJson("/catalog/attributes/{$other->id}", ['name' => 'Modelo', 'presentation' => 'text', 'special_use' => 'fabric'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['special_use']);

    expect($other->fresh()->special_use)->toBeNull()
        ->and(CatalogAttribute::query()->where('special_use', 'fabric')->count())->toBe(1)
        ->and(attributeAuditRows(AuditAction::CatalogUpdated, AuditAction::CatalogCreated))->toHaveCount(0);
});

it('DEC-PRD-49 keeps size and gender unique and editable with products.catalog', function (AttributeSpecialUse $use) {
    CatalogAttribute::factory()->create(['name' => 'Titular', 'special_use' => $use]);
    $free = CatalogAttribute::factory()->create(['name' => 'Libre']);

    $this->actingAs(attributeManager())
        ->putJson("/catalog/attributes/{$free->id}", ['name' => 'Libre', 'presentation' => 'text', 'special_use' => $use->value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['special_use']);

    $holder = CatalogAttribute::query()->where('name', 'Titular')->sole();

    // The holder releases the use and the free attribute takes it: both audited as catalog.updated.
    $this->actingAs(attributeManager())
        ->putJson("/catalog/attributes/{$holder->id}", ['name' => 'Titular', 'presentation' => 'text', 'special_use' => null])
        ->assertRedirect();
    $this->actingAs(attributeManager())
        ->putJson("/catalog/attributes/{$free->id}", ['name' => 'Libre', 'presentation' => 'text', 'special_use' => $use->value])
        ->assertRedirect();

    expect($holder->fresh()->special_use)->toBeNull()
        ->and($free->fresh()->special_use)->toBe($use);

    $audit = attributeAuditRows(AuditAction::CatalogUpdated)->last();

    expect($audit->old_values)->toEqual(['special_use' => null])
        ->and($audit->new_values)->toEqual(['special_use' => $use->value]);
})->with([AttributeSpecialUse::Size, AttributeSpecialUse::Gender]);

it('DEC-PRD-49 rejects the fabric use on a color-presentation attribute', function () {
    $this->actingAs(attributeManager())
        ->postJson('/catalog/attributes', ['name' => 'Paleta', 'presentation' => 'color', 'special_use' => 'fabric'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['special_use']);

    expect(CatalogAttribute::query()->count())->toBe(0);
});

it('E-58 rejects a second color-presentation attribute on creation and on change', function () {
    CatalogAttribute::factory()->color()->create();
    $other = CatalogAttribute::factory()->create(['name' => 'Modelo']);

    $this->actingAs(attributeManager())
        ->postJson('/catalog/attributes', ['name' => 'Otra paleta', 'presentation' => 'color', 'special_use' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['presentation']);

    $this->actingAs(attributeManager())
        ->putJson("/catalog/attributes/{$other->id}", ['name' => 'Modelo', 'presentation' => 'color', 'special_use' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['presentation']);

    expect($other->fresh()->presentation)->toBe(AttributePresentation::Text)
        ->and(CatalogAttribute::query()->where('presentation', 'color')->count())->toBe(1);
});

it('E-58 lets the only color attribute keep its presentation when it is edited', function () {
    $color = CatalogAttribute::factory()->color()->create();

    $this->actingAs(attributeManager())
        ->putJson("/catalog/attributes/{$color->id}", ['name' => 'Paleta', 'presentation' => 'color', 'special_use' => null])
        ->assertRedirect();

    expect($color->fresh()->name)->toBe('Paleta');
});

it('PRD-002 requires every value to have a tone before an attribute becomes color-presentation', function () {
    $attribute = CatalogAttribute::factory()->create(['name' => 'Acabado']);
    AttributeValue::factory()->for($attribute)->create(['tone' => null]);

    $this->actingAs(attributeManager())
        ->putJson("/catalog/attributes/{$attribute->id}", ['name' => 'Acabado', 'presentation' => 'color', 'special_use' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['presentation']);

    expect($attribute->fresh()->presentation)->toBe(AttributePresentation::Text);

    AttributeValue::query()->where('catalog_attribute_id', $attribute->id)->update(['tone' => '#112233']);

    $this->actingAs(attributeManager())
        ->putJson("/catalog/attributes/{$attribute->id}", ['name' => 'Acabado', 'presentation' => 'color', 'special_use' => null])
        ->assertRedirect();

    expect($attribute->fresh()->presentation)->toBe(AttributePresentation::Color);
});

it('R3-003 re-checks the tones inside the Action when the request is bypassed', function () {
    $attribute = CatalogAttribute::factory()->create(['name' => 'Acabado']);
    AttributeValue::factory()->for($attribute)->create(['tone' => '#112233']);
    AttributeValue::factory()->for($attribute)->create(['tone' => null]);

    try {
        app(UpdateCatalogAttribute::class)->handle($attribute, ['name' => 'Otro', 'presentation' => 'color', 'special_use' => null], attributeManager());
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('presentation');
    }

    expect($attribute->fresh()->presentation)->toBe(AttributePresentation::Text)
        ->and($attribute->fresh()->name)->toBe('Acabado')
        ->and(attributeAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('PRD-002 audits an attribute edit with the changed fields only and nothing when no field changes', function () {
    $attribute = CatalogAttribute::factory()->create(['name' => 'Manga']);
    $actor = attributeManager();

    $this->actingAs($actor)
        ->putJson("/catalog/attributes/{$attribute->id}", ['name' => 'Manga', 'presentation' => 'text', 'special_use' => null])
        ->assertRedirect();

    expect(attributeAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);

    $this->actingAs($actor)
        ->putJson("/catalog/attributes/{$attribute->id}", ['name' => 'Tipo de manga', 'presentation' => 'image', 'special_use' => null])
        ->assertRedirect();

    $audit = attributeAuditRows(AuditAction::CatalogUpdated)->sole();

    expect($audit->old_values)->toEqual(['name' => 'Manga', 'presentation' => 'text'])
        ->and($audit->new_values)->toEqual(['name' => 'Tipo de manga', 'presentation' => 'image']);
});

it('PRD-002 CreateCatalogAttribute turns a unique-index violation into a field validation error', function (array $existing, array $data, string $field) {
    CatalogAttribute::factory()->create($existing);

    try {
        app(CreateCatalogAttribute::class)->handle($data, attributeManager());
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(CatalogAttribute::query()->count())->toBe(1);
})->with([
    'name' => [['name' => 'Manga'], ['name' => 'manga', 'presentation' => AttributePresentation::Text->value, 'special_use' => null], 'name'],
    'special use' => [['name' => 'Tela', 'special_use' => AttributeSpecialUse::Fabric], ['name' => 'Otra', 'presentation' => AttributePresentation::Text->value, 'special_use' => 'fabric'], 'special_use'],
    'color' => [['name' => 'Color', 'presentation' => AttributePresentation::Color], ['name' => 'Paleta', 'presentation' => AttributePresentation::Color->value, 'special_use' => null], 'presentation'],
]);

// --- Values -----------------------------------------------------------------------------------

it('E-36 rejects a value of a color attribute without tone or with an invalid tone', function (mixed $tone) {
    $color = CatalogAttribute::factory()->color()->create();

    $this->actingAs(attributeManager())
        ->postJson("/catalog/attributes/{$color->id}/values", ['name' => 'Azul marino', 'tone' => $tone])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tone']);

    expect(AttributeValue::query()->count())->toBe(0)
        ->and(attributeAuditRows(AuditAction::CatalogCreated))->toHaveCount(0);
})->with(['missing' => [null], 'short hex' => ['#abc'], 'bad digit' => ['#12345G'], 'name' => ['blue'], 'no hash' => ['7a9a3b']]);

it('E-36 stores the tone chosen in the picker as uppercase hexadecimal', function () {
    $actor = attributeManager();
    $color = CatalogAttribute::factory()->color()->create(['name' => 'Color']);

    $this->actingAs($actor)
        ->postJson("/catalog/attributes/{$color->id}/values", ['name' => 'Verde hoja', 'description' => 'Tono de referencia', 'tone' => '#7a9a3b'])
        ->assertRedirect();

    $value = AttributeValue::query()->sole();

    expect($value->tone)->toBe('#7A9A3B')
        ->and($value->description)->toBe('Tono de referencia')
        ->and($value->sort_order)->toBe(1)
        ->and($value->status)->toBe(CatalogStatus::Active);

    $audit = attributeAuditRows(AuditAction::CatalogCreated)->sole();

    expect($audit->entity_id)->toBe($value->id)
        ->and($audit->new_values)->toEqual([
            'attribute' => 'Color',
            'name' => 'Verde hoja',
            'description' => 'Tono de referencia',
            'tone' => '#7A9A3B',
            'svg_layer' => null,
            'sort_order' => 1,
            'status' => 'active',
        ]);
});

it('E-36 rejects a tone on a value of a non-color attribute', function () {
    $text = CatalogAttribute::factory()->create();

    $this->actingAs(attributeManager())
        ->postJson("/catalog/attributes/{$text->id}/values", ['name' => 'Corta', 'tone' => '#7A9A3B'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tone']);

    expect(AttributeValue::query()->count())->toBe(0);
});

it('PRD-002 appends a value at the end of its own attribute order', function () {
    $sleeves = CatalogAttribute::factory()->create();
    $sizes = CatalogAttribute::factory()->create();
    AttributeValue::factory()->for($sleeves)->create(['sort_order' => 3]);
    AttributeValue::factory()->for($sizes)->create(['sort_order' => 40]);

    $this->actingAs(attributeManager())
        ->postJson("/catalog/attributes/{$sleeves->id}/values", ['name' => 'Larga'])
        ->assertRedirect();

    expect(AttributeValue::query()->where('name', 'Larga')->sole()->sort_order)->toBe(4);
});

it('PRD-002 keeps value names unique within an attribute ignoring case but repeatable across attributes', function () {
    $sleeves = CatalogAttribute::factory()->create();
    $models = CatalogAttribute::factory()->create();
    AttributeValue::factory()->for($sleeves)->create(['name' => 'Corta']);
    $actor = attributeManager();

    $this->actingAs($actor)
        ->postJson("/catalog/attributes/{$sleeves->id}/values", ['name' => 'corta'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($actor)
        ->postJson("/catalog/attributes/{$models->id}/values", ['name' => 'Corta'])
        ->assertRedirect();

    expect(AttributeValue::query()->count())->toBe(2);
});

it('PRD-002 CreateAttributeValue turns a unique-index violation into a name validation error', function () {
    $attribute = CatalogAttribute::factory()->create();
    AttributeValue::factory()->for($attribute)->create(['name' => 'Corta']);

    try {
        app(CreateAttributeValue::class)->handle($attribute, ['name' => 'CORTA'], attributeManager());
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name');
    }

    expect(AttributeValue::query()->count())->toBe(1);
});

it('PRD-002 accepts a well-formed svg layer and rejects malformed or reserved ones', function (mixed $layer, bool $valid) {
    $attribute = CatalogAttribute::factory()->create();

    $response = $this->actingAs(attributeManager())
        ->postJson("/catalog/attributes/{$attribute->id}/values", ['name' => 'Capa', 'svg_layer' => $layer]);

    if ($valid) {
        $response->assertRedirect();
        expect(AttributeValue::query()->sole()->svg_layer)->toBe($layer);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors(['svg_layer']);
        expect(AttributeValue::query()->count())->toBe(0);
    }
})->with([
    'simple' => ['pecho', true],
    'hyphenated' => ['manga-corta-2', true],
    'empty is none' => [null, true],
    'uppercase' => ['Pecho', false],
    'underscore' => ['manga_corta', false],
    'double hyphen' => ['manga--corta', false],
    'leading hyphen' => ['-manga', false],
    'too long' => [str_repeat('a', 65), false],
    'reserved cuerpo' => ['cuerpo', false],
    'reserved sombras' => ['sombras', false],
]);

it('PRD-002 renames a value and audits the old and new name', function () {
    $attribute = CatalogAttribute::factory()->create();
    $value = AttributeValue::factory()->for($attribute)->create(['name' => 'Corta', 'description' => 'Hasta el codo']);

    $this->actingAs(attributeManager())
        ->putJson("/catalog/values/{$value->id}", ['name' => 'Manga corta', 'description' => 'Hasta el codo', 'svg_layer' => null])
        ->assertRedirect();

    expect($value->fresh()->name)->toBe('Manga corta');

    $audit = attributeAuditRows(AuditAction::CatalogUpdated)->sole();

    expect($audit->entity_id)->toBe($value->id)
        ->and($audit->old_values)->toEqual(['name' => 'Corta'])
        ->and($audit->new_values)->toEqual(['name' => 'Manga corta']);
});

it('E-36 validates the tone when a value of a color attribute is edited', function () {
    $color = CatalogAttribute::factory()->color()->create();
    $value = AttributeValue::factory()->for($color)->withTone('#112233')->create(['name' => 'Gris']);
    $actor = attributeManager();

    $this->actingAs($actor)
        ->putJson("/catalog/values/{$value->id}", ['name' => 'Gris', 'tone' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tone']);

    $this->actingAs($actor)
        ->putJson("/catalog/values/{$value->id}", ['name' => 'Gris', 'tone' => '#a0b1c2'])
        ->assertRedirect();

    expect($value->fresh()->tone)->toBe('#A0B1C2');

    $audit = attributeAuditRows(AuditAction::CatalogUpdated)->sole();

    expect($audit->old_values)->toEqual(['tone' => '#112233'])
        ->and($audit->new_values)->toEqual(['tone' => '#A0B1C2']);
});

it('PRD-002 rejects renaming a value to the name of a sibling and writes no audit for an unchanged edit', function () {
    $attribute = CatalogAttribute::factory()->create();
    AttributeValue::factory()->for($attribute)->create(['name' => 'Corta']);
    $long = AttributeValue::factory()->for($attribute)->create(['name' => 'Larga']);
    $actor = attributeManager();

    $this->actingAs($actor)
        ->putJson("/catalog/values/{$long->id}", ['name' => 'CORTA'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($actor)
        ->putJson("/catalog/values/{$long->id}", ['name' => 'Larga'])
        ->assertRedirect();

    expect($long->fresh()->name)->toBe('Larga')
        ->and(attributeAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

// --- Lifecycle and order ---------------------------------------------------------------------

it('PRD-002 deactivates and reactivates attributes and values auditing the status, with no-op repeats', function () {
    $actor = attributeManager();
    $attribute = CatalogAttribute::factory()->create();
    $value = AttributeValue::factory()->for($attribute)->create();

    foreach ([["/catalog/attributes/{$attribute->id}", $attribute], ["/catalog/values/{$value->id}", $value]] as [$base, $model]) {
        $this->actingAs($actor)->postJson("{$base}/deactivate")->assertRedirect();
        expect($model->fresh()->status)->toBe(CatalogStatus::Inactive);

        $this->actingAs($actor)->postJson("{$base}/deactivate")->assertRedirect();

        $this->actingAs($actor)->postJson("{$base}/activate")->assertRedirect();
        expect($model->fresh()->status)->toBe(CatalogStatus::Active);

        $this->actingAs($actor)->postJson("{$base}/activate")->assertRedirect();
    }

    expect(attributeAuditRows(AuditAction::CatalogDeactivated))->toHaveCount(2)
        ->and(attributeAuditRows(AuditAction::CatalogActivated))->toHaveCount(2)
        ->and(attributeAuditRows(AuditAction::CatalogDeactivated)->first()->old_values)->toEqual(['status' => 'active']);
});

it('PRD-002 moves attributes across the whole table and audits both rows', function () {
    $first = CatalogAttribute::factory()->create(['sort_order' => 1]);
    $second = CatalogAttribute::factory()->create(['sort_order' => 6]);

    $this->actingAs(attributeManager())
        ->postJson("/catalog/attributes/{$first->id}/move", ['direction' => 'down'])
        ->assertRedirect();

    expect($first->fresh()->sort_order)->toBe(6)
        ->and($second->fresh()->sort_order)->toBe(1)
        ->and(attributeAuditRows(AuditAction::CatalogUpdated))->toHaveCount(2);

    $this->actingAs(attributeManager())
        ->postJson("/catalog/attributes/{$second->id}/move", ['direction' => 'up'])
        ->assertRedirect();

    expect($second->fresh()->sort_order)->toBe(1)
        ->and(attributeAuditRows(AuditAction::CatalogUpdated))->toHaveCount(2);
});

it('PRD-002 moves a value only among the values of its own attribute', function () {
    $sleeves = CatalogAttribute::factory()->create();
    $sizes = CatalogAttribute::factory()->create();
    $short = AttributeValue::factory()->for($sleeves)->create(['sort_order' => 1]);
    $foreign = AttributeValue::factory()->for($sizes)->create(['sort_order' => 2]);
    $long = AttributeValue::factory()->for($sleeves)->create(['sort_order' => 3]);

    $this->actingAs(attributeManager())
        ->postJson("/catalog/values/{$short->id}/move", ['direction' => 'down'])
        ->assertRedirect();

    // The neighbor is `long` (same attribute), never the foreign value that sits between them.
    expect($short->fresh()->sort_order)->toBe(3)
        ->and($long->fresh()->sort_order)->toBe(1)
        ->and($foreign->fresh()->sort_order)->toBe(2)
        ->and(attributeAuditRows(AuditAction::CatalogUpdated)->pluck('entity_id')->all())->toEqualCanonicalizing([$short->id, $long->id]);
});

it('PRD-002 treats moving the first value up or the last value down as a no-op even with other attributes around', function () {
    $sleeves = CatalogAttribute::factory()->create();
    $sizes = CatalogAttribute::factory()->create();
    $only = AttributeValue::factory()->for($sleeves)->create(['sort_order' => 5]);
    AttributeValue::factory()->for($sizes)->create(['sort_order' => 4]);
    AttributeValue::factory()->for($sizes)->create(['sort_order' => 6]);

    $this->actingAs(attributeManager())->postJson("/catalog/values/{$only->id}/move", ['direction' => 'up'])->assertRedirect();
    $this->actingAs(attributeManager())->postJson("/catalog/values/{$only->id}/move", ['direction' => 'down'])->assertRedirect();

    expect($only->fresh()->sort_order)->toBe(5)
        ->and(attributeAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('R3-001 resolves ties on sort order inside the attribute of the moved value', function () {
    $sleeves = CatalogAttribute::factory()->create();
    $sizes = CatalogAttribute::factory()->create();
    $a = AttributeValue::factory()->for($sleeves)->create(['sort_order' => 5]);
    $b = AttributeValue::factory()->for($sleeves)->create(['sort_order' => 5]);
    $c = AttributeValue::factory()->for($sleeves)->create(['sort_order' => 9]);
    $foreign = AttributeValue::factory()->for($sizes)->create(['sort_order' => 5]);

    $this->actingAs(attributeManager())
        ->postJson("/catalog/values/{$b->id}/move", ['direction' => 'up'])
        ->assertRedirect();

    expect(valueIdsInOrder($sleeves))->toBe([$b->id, $a->id, $c->id])
        ->and($foreign->fresh()->sort_order)->toBe(5)
        ->and(attributeAuditRows(AuditAction::CatalogUpdated)->pluck('entity_id')->all())
        ->toEqualCanonicalizing([$a->id, $b->id, $c->id]);

    $silent = attributeAuditRows(AuditAction::CatalogUpdated)->firstWhere('entity_id', $c->id);

    expect($silent->old_values)->toEqual(['sort_order' => 9])
        ->and($silent->new_values)->toEqual(['sort_order' => 3]);
});

it('R3-002 locks only the rows of the attribute scope in one query ordered by id', function () {
    $sleeves = CatalogAttribute::factory()->create();
    $sizes = CatalogAttribute::factory()->create();
    $first = AttributeValue::factory()->for($sleeves)->create(['sort_order' => 1]);
    AttributeValue::factory()->for($sleeves)->create(['sort_order' => 2]);
    AttributeValue::factory()->for($sizes)->create(['sort_order' => 3]);

    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        }
    });

    app(MoveCatalogItem::class)->handle($first, MoveCatalogItem::DOWN, attributeManager());

    expect($locks)->toHaveCount(1)
        ->and($locks[0]['sql'])->toContain('`catalog_attribute_id` = ?')
        ->and($locks[0]['sql'])->toContain('order by `id`')
        ->and($locks[0]['bindings'])->toBe([$sleeves->id]);
});

it('PRD-002 never deletes attributes or values: there are no delete routes', function () {
    $attribute = CatalogAttribute::factory()->create();
    $value = AttributeValue::factory()->for($attribute)->create();

    $this->actingAs(attributeManager())->deleteJson("/catalog/attributes/{$attribute->id}")->assertStatus(405);
    $this->actingAs(attributeManager())->deleteJson("/catalog/values/{$value->id}")->assertStatus(405);

    $deleteRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'catalog/') && in_array('DELETE', $route->methods(), true));

    expect($deleteRoutes)->toHaveCount(0)
        ->and(CatalogAttribute::query()->count())->toBe(1)
        ->and(AttributeValue::query()->count())->toBe(1);
});

it('PRD-016 denies each attribute and value write to a user without products.catalog and audits authorization.denied', function (string $method, Closure $path, array $payload) {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate);
    $attribute = CatalogAttribute::factory()->create(['name' => 'Manga', 'sort_order' => 1]);
    $value = AttributeValue::factory()->for($attribute)->create(['name' => 'Corta', 'sort_order' => 1]);
    AttributeValue::factory()->for($attribute)->create(['sort_order' => 2]);

    $this->actingAs($actor)->json($method, $path($attribute, $value), $payload)->assertForbidden();

    expect(CatalogAttribute::query()->count())->toBe(1)
        ->and(AttributeValue::query()->count())->toBe(2)
        ->and($attribute->fresh()->name)->toBe('Manga')
        ->and($value->fresh()->name)->toBe('Corta')
        ->and($value->fresh()->status)->toBe(CatalogStatus::Active)
        ->and(attributeAuditRows(AuditAction::CatalogCreated, AuditAction::CatalogUpdated, AuditAction::CatalogActivated, AuditAction::CatalogDeactivated))->toHaveCount(0);

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toStartWith('catalog.');
})->with([
    'attribute store' => ['POST', fn () => '/catalog/attributes', ['name' => 'Nueva', 'presentation' => 'text', 'special_use' => null]],
    'attribute update' => ['PUT', fn (CatalogAttribute $a) => "/catalog/attributes/{$a->id}", ['name' => 'Otra', 'presentation' => 'text', 'special_use' => null]],
    'attribute move' => ['POST', fn (CatalogAttribute $a) => "/catalog/attributes/{$a->id}/move", ['direction' => 'down']],
    'attribute deactivate' => ['POST', fn (CatalogAttribute $a) => "/catalog/attributes/{$a->id}/deactivate", []],
    'attribute activate' => ['POST', fn (CatalogAttribute $a) => "/catalog/attributes/{$a->id}/activate", []],
    'value store' => ['POST', fn (CatalogAttribute $a) => "/catalog/attributes/{$a->id}/values", ['name' => 'Nueva']],
    'value update' => ['PUT', fn (CatalogAttribute $a, AttributeValue $v) => "/catalog/values/{$v->id}", ['name' => 'Otra']],
    'value move' => ['POST', fn (CatalogAttribute $a, AttributeValue $v) => "/catalog/values/{$v->id}/move", ['direction' => 'down']],
    'value deactivate' => ['POST', fn (CatalogAttribute $a, AttributeValue $v) => "/catalog/values/{$v->id}/deactivate", []],
    'value activate' => ['POST', fn (CatalogAttribute $a, AttributeValue $v) => "/catalog/values/{$v->id}/activate", []],
]);

// --- Hooks filled by later slices ------------------------------------------------------------

it('DEC-PRD-51 call sites of the in-use hooks do not alter attribute lifecycle and edits while no product table exists', function () {
    $attribute = CatalogAttribute::factory()->fabric()->create();
    $value = AttributeValue::factory()->for($attribute)->create(['svg_layer' => null]);
    $actor = attributeManager();

    $this->actingAs($actor)->postJson("/catalog/attributes/{$attribute->id}/deactivate")->assertRedirect();
    $this->actingAs($actor)->postJson("/catalog/attributes/{$attribute->id}/activate")->assertRedirect();
    $this->actingAs($actor)
        ->putJson("/catalog/attributes/{$attribute->id}", ['name' => 'Tela', 'presentation' => 'image', 'special_use' => null])
        ->assertRedirect();

    expect($attribute->fresh()->presentation)->toBe(AttributePresentation::Image)
        ->and($attribute->fresh()->special_use)->toBeNull();
});

it('DEC-PRD-53 call sites of the layer hook let a value gain, change and lose its svg layer', function () {
    $attribute = CatalogAttribute::factory()->create();
    $value = AttributeValue::factory()->for($attribute)->create(['name' => 'Corta']);
    $actor = attributeManager();

    foreach (['manga-corta', 'manga', null] as $layer) {
        $this->actingAs($actor)
            ->putJson("/catalog/values/{$value->id}", ['name' => 'Corta', 'svg_layer' => $layer])
            ->assertRedirect();

        expect($value->fresh()->svg_layer)->toBe($layer);
    }
});

// --- Colors offered in a fabric (E-46) -------------------------------------------------------

/**
 * A fabric value offered in azul marino, blanco and verde, plus an unused active color Gris perla.
 *
 * @return array{fabric: AttributeValue, navy: AttributeValue, white: AttributeValue, green: AttributeValue, pearl: AttributeValue, palette: CatalogAttribute}
 */
function fabricWithOfferedColors(): array
{
    $palette = CatalogAttribute::factory()->color()->create();
    $navy = AttributeValue::factory()->for($palette)->withTone('#1F3A5F')->create(['name' => 'Azul marino']);
    $white = AttributeValue::factory()->for($palette)->withTone('#FFFFFF')->create(['name' => 'Blanco']);
    $green = AttributeValue::factory()->for($palette)->withTone('#2E7D32')->create(['name' => 'Verde']);
    $pearl = AttributeValue::factory()->for($palette)->withTone('#C9CCD1')->create(['name' => 'Gris perla']);
    $fabric = AttributeValue::factory()->for(CatalogAttribute::factory()->fabric())->create(['name' => 'ALG-OXF PIMA']);
    $fabric->offeredColors()->attach([$navy->id, $white->id, $green->id]);

    return compact('fabric', 'navy', 'white', 'green', 'pearl', 'palette');
}

it('E-46 adds Gris perla to a fabric and removes Verde, auditing the added and removed names', function () {
    ['fabric' => $fabric, 'navy' => $navy, 'white' => $white, 'green' => $green, 'pearl' => $pearl] = fabricWithOfferedColors();
    $actor = attributeManager();

    $this->actingAs($actor)
        ->putJson("/catalog/values/{$fabric->id}/offered-colors", ['color_ids' => [$navy->id, $white->id, $pearl->id]])
        ->assertRedirect();

    expect($fabric->offeredColors()->pluck('attribute_values.id')->all())->toEqualCanonicalizing([$navy->id, $white->id, $pearl->id])
        ->and($green->fresh()->status)->toBe(CatalogStatus::Active)
        ->and($green->fresh()->tone)->toBe('#2E7D32');

    $audit = attributeAuditRows(AuditAction::CatalogFabricColorsUpdated)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($fabric->id)
        ->and($audit->old_values)->toEqual(['removed' => ['Verde']])
        ->and($audit->new_values)->toEqual(['added' => ['Gris perla']]);
});

it('E-46 lists several added and removed colors by name and allows an empty set', function () {
    ['fabric' => $fabric, 'navy' => $navy, 'white' => $white, 'green' => $green, 'pearl' => $pearl] = fabricWithOfferedColors();

    $this->actingAs(attributeManager())
        ->putJson("/catalog/values/{$fabric->id}/offered-colors", ['color_ids' => [$pearl->id]])
        ->assertRedirect();

    $audit = attributeAuditRows(AuditAction::CatalogFabricColorsUpdated)->sole();

    expect($audit->old_values)->toEqual(['removed' => ['Azul marino', 'Blanco', 'Verde']])
        ->and($audit->new_values)->toEqual(['added' => ['Gris perla']]);

    $this->actingAs(attributeManager())
        ->putJson("/catalog/values/{$fabric->id}/offered-colors", ['color_ids' => []])
        ->assertRedirect();

    expect($fabric->offeredColors()->count())->toBe(0)
        ->and(AttributeValue::query()->whereKey([$navy->id, $white->id, $green->id, $pearl->id])->count())->toBe(4);
});

it('E-46 writes no audit when the offered colors do not change', function () {
    ['fabric' => $fabric, 'navy' => $navy, 'white' => $white, 'green' => $green] = fabricWithOfferedColors();

    $this->actingAs(attributeManager())
        ->putJson("/catalog/values/{$fabric->id}/offered-colors", ['color_ids' => [$green->id, $navy->id, $white->id, $navy->id]])
        ->assertRedirect();

    expect($fabric->offeredColors()->count())->toBe(3)
        ->and(attributeAuditRows(AuditAction::CatalogFabricColorsUpdated))->toHaveCount(0);
});

it('E-46 rejects adding an inactive color but keeps an offered color that was deactivated later', function () {
    ['fabric' => $fabric, 'navy' => $navy, 'white' => $white, 'green' => $green, 'pearl' => $pearl] = fabricWithOfferedColors();
    $pearl->update(['status' => CatalogStatus::Inactive]);
    $green->update(['status' => CatalogStatus::Inactive]);

    $this->actingAs(attributeManager())
        ->putJson("/catalog/values/{$fabric->id}/offered-colors", ['color_ids' => [$navy->id, $white->id, $green->id, $pearl->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['color_ids']);

    expect($fabric->offeredColors()->count())->toBe(3)
        ->and(attributeAuditRows(AuditAction::CatalogFabricColorsUpdated))->toHaveCount(0);

    // The already offered inactive color stays when it is resubmitted: only additions must be active.
    $this->actingAs(attributeManager())
        ->putJson("/catalog/values/{$fabric->id}/offered-colors", ['color_ids' => [$navy->id, $green->id]])
        ->assertRedirect();

    expect($fabric->offeredColors()->pluck('attribute_values.id')->all())->toEqualCanonicalizing([$navy->id, $green->id]);
});

it('PRD-002 rejects offered colors on a value that is not of the fabric attribute', function () {
    ['navy' => $navy] = fabricWithOfferedColors();
    $notFabric = AttributeValue::factory()->for(CatalogAttribute::factory()->create())->create();

    $this->actingAs(attributeManager())
        ->putJson("/catalog/values/{$notFabric->id}/offered-colors", ['color_ids' => [$navy->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['color_ids']);

    expect($notFabric->offeredColors()->count())->toBe(0)
        ->and(attributeAuditRows(AuditAction::CatalogFabricColorsUpdated))->toHaveCount(0);
});

it('PRD-002 rejects offered colors that are not values of the color attribute or do not exist', function () {
    ['fabric' => $fabric] = fabricWithOfferedColors();
    $stranger = AttributeValue::factory()->for(CatalogAttribute::factory()->create())->create();

    foreach ([[$stranger->id], [999999]] as $ids) {
        $this->actingAs(attributeManager())
            ->putJson("/catalog/values/{$fabric->id}/offered-colors", ['color_ids' => $ids])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['color_ids']);
    }

    expect($fabric->offeredColors()->count())->toBe(3);
});

it('PRD-002 requires the list of offered colors to be sent as integers', function (mixed $payload) {
    ['fabric' => $fabric] = fabricWithOfferedColors();

    $this->actingAs(attributeManager())
        ->putJson("/catalog/values/{$fabric->id}/offered-colors", $payload)
        ->assertUnprocessable();

    expect($fabric->offeredColors()->count())->toBe(3);
})->with([
    'missing' => [[]],
    'not an array' => [['color_ids' => 'azul']],
    'not integers' => [['color_ids' => ['azul']]],
]);

it('PRD-016 denies offered color changes to a user without products.catalog and audits authorization.denied', function () {
    ['fabric' => $fabric, 'pearl' => $pearl] = fabricWithOfferedColors();
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);

    $this->actingAs($actor)
        ->putJson("/catalog/values/{$fabric->id}/offered-colors", ['color_ids' => [$pearl->id]])
        ->assertForbidden();

    expect($fabric->offeredColors()->count())->toBe(3)
        ->and(attributeAuditRows(AuditAction::CatalogFabricColorsUpdated))->toHaveCount(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->context['route'])->toBe('catalog.values.offered-colors.update');
});

// --- Pages (PRD-002, spec section 8) ----------------------------------------------------------

it('PRD-002 page GET /catalog/attributes lists the attributes in sort order with labels and value counts, inactive ones included', function () {
    $size = CatalogAttribute::factory()->size()->create(['sort_order' => 3]);
    $fabric = CatalogAttribute::factory()->fabric()->create(['sort_order' => 1]);
    $color = CatalogAttribute::factory()->color()->inactive()->create(['sort_order' => 2]);
    AttributeValue::factory()->for($fabric)->count(2)->create();
    AttributeValue::factory()->for($fabric)->inactive()->create();
    AttributeValue::factory()->for($color)->withTone()->create();

    $this->actingAs(attributeManager())->get('/catalog/attributes')->assertOk()->assertInertia(function (Assert $page) use ($fabric, $size) {
        $page->component('catalog/Attributes')
            ->has('attributes', 3)
            ->where('attributes.0', [
                'id' => $fabric->id,
                'name' => 'Tela',
                'presentation' => 'text',
                'presentation_label' => 'Texto',
                'special_use' => 'fabric',
                'special_use_label' => 'Tela',
                'status' => 'active',
                'status_label' => 'Activo',
                'sort_order' => 1,
                'values_count' => 3,
            ])
            ->where('attributes.1.presentation', 'color')
            ->where('attributes.1.presentation_label', 'Color')
            ->where('attributes.1.special_use', null)
            ->where('attributes.1.special_use_label', null)
            ->where('attributes.1.status', 'inactive')
            ->where('attributes.1.status_label', 'Inactivo')
            ->where('attributes.1.values_count', 1)
            ->where('attributes.2.id', $size->id)
            ->where('attributes.2.values_count', 0)
            ->where('options.presentations', [
                ['value' => 'text', 'label' => 'Texto'],
                ['value' => 'image', 'label' => 'Imagen'],
                ['value' => 'color', 'label' => 'Color'],
            ])
            ->where('options.special_uses', [
                ['value' => 'fabric', 'label' => 'Tela'],
                ['value' => 'size', 'label' => 'Talla'],
                ['value' => 'gender', 'label' => 'Género'],
            ])
            ->where('can.manage', true);
    });
});

it('PRD-002 page renders an empty list of attributes', function () {
    $this->actingAs(attributeManager())->get('/catalog/attributes')->assertInertia(
        fn (Assert $page) => $page->component('catalog/Attributes')->has('attributes', 0)->where('can.manage', true)
    );
});

it('PRD-002 page GET /catalog/attributes/{attribute} lists the ordered values of a color attribute with tone, description, layer and status', function () {
    $color = CatalogAttribute::factory()->color()->create();
    CatalogAttribute::factory()->fabric()->create();
    $second = AttributeValue::factory()->for($color)->withTone('#1f3a5f')->inactive()->create(['name' => 'Azul marino', 'description' => 'Oscuro', 'svg_layer' => 'azul', 'sort_order' => 2]);
    $first = AttributeValue::factory()->for($color)->withTone('#FFFFFF')->create(['name' => 'Blanco', 'sort_order' => 1]);

    $this->actingAs(attributeManager())->get("/catalog/attributes/{$color->id}")->assertOk()->assertInertia(function (Assert $page) use ($color, $first, $second) {
        $page->component('catalog/AttributeShow')
            ->where('attribute', [
                'id' => $color->id,
                'name' => 'Color',
                'presentation' => 'color',
                'presentation_label' => 'Color',
                'special_use' => null,
                'special_use_label' => null,
                'status' => 'active',
                'status_label' => 'Activo',
                'sort_order' => $color->sort_order,
                'values_count' => 2,
            ])
            ->has('values', 2)
            ->where('values.0', [
                'id' => $first->id,
                'name' => 'Blanco',
                'description' => null,
                'tone' => '#FFFFFF',
                'svg_layer' => null,
                'status' => 'active',
                'status_label' => 'Activo',
                'sort_order' => 1,
                'offered_colors' => null,
            ])
            ->where('values.1.id', $second->id)
            ->where('values.1.description', 'Oscuro')
            ->where('values.1.svg_layer', 'azul')
            ->where('values.1.status', 'inactive')
            ->where('values.1.status_label', 'Inactivo')
            ->where('palette', [])
            ->where('can.manage', true);
    });
});

it('E-46 page of the fabric attribute exposes each value\'s offered colors and the active palette to choose from', function () {
    ['fabric' => $fabric, 'navy' => $navy, 'white' => $white, 'green' => $green, 'pearl' => $pearl, 'palette' => $palette] = fabricWithOfferedColors();
    $navy->update(['sort_order' => 1]);
    $white->update(['sort_order' => 2]);
    $green->update(['sort_order' => 3]);
    $pearl->update(['sort_order' => 4]);
    $inactiveColor = AttributeValue::factory()->for($palette)->withTone('#000000')->inactive()->create(['name' => 'Negro', 'sort_order' => 5]);
    $fabric->offeredColors()->attach($inactiveColor->id);
    $plain = AttributeValue::factory()->for($fabric->catalogAttribute)->create(['name' => 'DRILL', 'sort_order' => 2]);
    $fabric->update(['sort_order' => 1]);

    $this->actingAs(attributeManager())->get("/catalog/attributes/{$fabric->catalog_attribute_id}")->assertOk()->assertInertia(function (Assert $page) use ($fabric, $navy, $white, $green, $pearl, $inactiveColor, $plain) {
        $page->component('catalog/AttributeShow')
            ->where('attribute.special_use', 'fabric')
            ->where('values.0.id', $fabric->id)
            ->where('values.0.offered_colors', [
                ['id' => $navy->id, 'name' => 'Azul marino', 'tone' => '#1F3A5F', 'status' => 'active'],
                ['id' => $white->id, 'name' => 'Blanco', 'tone' => '#FFFFFF', 'status' => 'active'],
                ['id' => $green->id, 'name' => 'Verde', 'tone' => '#2E7D32', 'status' => 'active'],
                ['id' => $inactiveColor->id, 'name' => 'Negro', 'tone' => '#000000', 'status' => 'inactive'],
            ])
            ->where('values.1.id', $plain->id)
            ->where('values.1.offered_colors', [])
            ->where('palette', [
                ['id' => $navy->id, 'name' => 'Azul marino', 'tone' => '#1F3A5F', 'status' => 'active'],
                ['id' => $white->id, 'name' => 'Blanco', 'tone' => '#FFFFFF', 'status' => 'active'],
                ['id' => $green->id, 'name' => 'Verde', 'tone' => '#2E7D32', 'status' => 'active'],
                ['id' => $pearl->id, 'name' => 'Gris perla', 'tone' => '#C9CCD1', 'status' => 'active'],
            ]);
    });
});

it('E-46 page of the fabric attribute has an empty palette when no color attribute exists', function () {
    $fabric = AttributeValue::factory()->for(CatalogAttribute::factory()->fabric())->create();

    $this->actingAs(attributeManager())->get("/catalog/attributes/{$fabric->catalog_attribute_id}")->assertInertia(
        fn (Assert $page) => $page->component('catalog/AttributeShow')->where('palette', [])->where('values.0.offered_colors', [])
    );
});

it('PRD-016 pages of the attribute catalog are forbidden without products.catalog, even with products.view, and audit the denial', function (array $permissions, string $path, string $route) {
    $actor = userWithPermissions(...$permissions);
    $attribute = CatalogAttribute::factory()->create();

    $this->actingAs($actor)->get(str_replace('{id}', (string) $attribute->id, $path))->assertForbidden();

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toBe($route);
})->with([
    'index, products.view alone' => [[PermissionName::ProductsView], '/catalog/attributes', 'catalog.attributes.index'],
    'index, every product permission but catalog' => [[PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate, PermissionName::ProductsDelete], '/catalog/attributes', 'catalog.attributes.index'],
    'show, products.view alone' => [[PermissionName::ProductsView], '/catalog/attributes/{id}', 'catalog.attributes.show'],
    'show, every product permission but catalog' => [[PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate, PermissionName::ProductsDelete], '/catalog/attributes/{id}', 'catalog.attributes.show'],
]);

it('PRD-016 pages of the attribute catalog redirect a guest to the login', function () {
    $attribute = CatalogAttribute::factory()->create();

    $this->get('/catalog/attributes')->assertRedirect('/login');
    $this->get("/catalog/attributes/{$attribute->id}")->assertRedirect('/login');
});

it('PRD-002 page of an unknown attribute is not found', function () {
    $this->actingAs(attributeManager())->get('/catalog/attributes/999999')->assertNotFound();
});
