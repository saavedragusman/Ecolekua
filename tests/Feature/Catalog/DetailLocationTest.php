<?php

use App\Actions\Products\CreateDetailLocation;
use App\Actions\Products\UpdateDetailLocation;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\DetailLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function locationManager(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCatalog);
}

/**
 * @return Collection<int, AuditLog>
 */
function locationAuditRows(AuditAction ...$actions): Collection
{
    return AuditLog::query()
        ->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))
        ->orderBy('id')
        ->get();
}

it('PRD-007 creates a detail location and audits catalog.created', function () {
    $actor = locationManager();

    $this->actingAs($actor)
        ->postJson('/catalog/detail-locations', ['name' => '  Pechera  ', 'svg_layer' => 'pechera'])
        ->assertRedirect();

    $location = DetailLocation::query()->sole();

    expect($location->name)->toBe('Pechera')
        ->and($location->svg_layer)->toBe('pechera')
        ->and($location->status)->toBe(CatalogStatus::Active);

    $audit = locationAuditRows(AuditAction::CatalogCreated)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($location->getMorphClass())
        ->and($audit->entity_id)->toBe($location->id)
        ->and($audit->new_values)->toEqual(['name' => 'Pechera', 'svg_layer' => 'pechera', 'status' => 'active']);
});

it('PRD-007 creates a detail location without a layer', function () {
    $this->actingAs(locationManager())->postJson('/catalog/detail-locations', ['name' => 'Espalda'])->assertRedirect();

    expect(DetailLocation::query()->sole()->svg_layer)->toBeNull();
});

it('E-64 (location) rejects a name that only differs in letter case', function () {
    DetailLocation::factory()->create(['name' => 'Pechera']);

    $this->actingAs(locationManager())
        ->postJson('/catalog/detail-locations', ['name' => 'pechera'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(DetailLocation::query()->count())->toBe(1)
        ->and(locationAuditRows(AuditAction::CatalogCreated))->toHaveCount(0);
});

it('E-64 (location) rejects renaming to the name of another location and allows a case change of its own name', function () {
    DetailLocation::factory()->create(['name' => 'Pechera']);
    $other = DetailLocation::factory()->create(['name' => 'espalda']);

    $this->actingAs(locationManager())
        ->putJson("/catalog/detail-locations/{$other->id}", ['name' => 'PECHERA'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($other->fresh()->name)->toBe('espalda')
        ->and(locationAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);

    $this->actingAs(locationManager())
        ->putJson("/catalog/detail-locations/{$other->id}", ['name' => 'Espalda'])
        ->assertRedirect();

    expect($other->fresh()->name)->toBe('Espalda');
});

it('PRD-007 requires a name of at most 100 characters', function (string $method, mixed $name) {
    $location = DetailLocation::factory()->create(['name' => 'Pechera']);
    $url = $method === 'POST' ? '/catalog/detail-locations' : "/catalog/detail-locations/{$location->id}";

    $this->actingAs(locationManager())
        ->json($method, $url, ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($location->fresh()->name)->toBe('Pechera')
        ->and(DetailLocation::query()->count())->toBe(1);
})->with([
    'create missing' => ['POST', null],
    'create blank' => ['POST', '   '],
    'create too long' => ['POST', str_repeat('a', 101)],
    'update missing' => ['PUT', null],
    'update blank' => ['PUT', '   '],
    'update too long' => ['PUT', str_repeat('a', 101)],
]);

it('DEC-PRD-29 accepts a well-formed svg layer and rejects malformed or reserved ones', function (mixed $layer, bool $valid) {
    $location = DetailLocation::factory()->create(['name' => 'Pechera']);

    $create = $this->actingAs(locationManager())
        ->postJson('/catalog/detail-locations', ['name' => 'Nueva', 'svg_layer' => $layer]);
    $update = $this->actingAs(locationManager())
        ->putJson("/catalog/detail-locations/{$location->id}", ['name' => 'Pechera', 'svg_layer' => $layer]);

    if ($valid) {
        $create->assertRedirect();
        $update->assertRedirect();
        expect($location->fresh()->svg_layer)->toBe($layer);

        return;
    }

    $create->assertUnprocessable()->assertJsonValidationErrors(['svg_layer']);
    $update->assertUnprocessable()->assertJsonValidationErrors(['svg_layer']);
    expect($location->fresh()->svg_layer)->toBeNull()
        ->and(DetailLocation::query()->count())->toBe(1);
})->with([
    'simple' => ['orilla', true],
    'hyphenated' => ['orilla-mangas', true],
    'digits' => ['capa-2', true],
    'uppercase' => ['Orilla', false],
    'underscore' => ['orilla_mangas', false],
    'double hyphen' => ['orilla--mangas', false],
    'trailing hyphen' => ['orilla-', false],
    'space' => ['orilla mangas', false],
    'reserved cuerpo' => ['cuerpo', false],
    'reserved sombras' => ['sombras', false],
    'too long' => [str_repeat('a', 65), false],
]);

it('PRD-007 renames a location and audits only the changed fields', function () {
    $actor = locationManager();
    $location = DetailLocation::factory()->create(['name' => 'Pechera', 'svg_layer' => 'pechera']);

    $this->actingAs($actor)
        ->putJson("/catalog/detail-locations/{$location->id}", ['name' => 'Pechera frontal'])
        ->assertRedirect();

    expect($location->fresh()->name)->toBe('Pechera frontal')
        ->and($location->fresh()->svg_layer)->toBe('pechera');

    $audit = locationAuditRows(AuditAction::CatalogUpdated)->sole();

    expect($audit->entity_id)->toBe($location->id)
        ->and($audit->old_values)->toEqual(['name' => 'Pechera'])
        ->and($audit->new_values)->toEqual(['name' => 'Pechera frontal']);
});

it('PRD-007 changes and clears the svg layer auditing old and new values', function () {
    $location = DetailLocation::factory()->create(['name' => 'Pechera', 'svg_layer' => 'pechera']);

    $this->actingAs(locationManager())
        ->putJson("/catalog/detail-locations/{$location->id}", ['name' => 'Pechera', 'svg_layer' => 'pecho'])
        ->assertRedirect();
    $this->actingAs(locationManager())
        ->putJson("/catalog/detail-locations/{$location->id}", ['name' => 'Pechera', 'svg_layer' => null])
        ->assertRedirect();

    expect($location->fresh()->svg_layer)->toBeNull();

    $audits = locationAuditRows(AuditAction::CatalogUpdated);

    expect($audits)->toHaveCount(2)
        ->and($audits[0]->old_values)->toEqual(['svg_layer' => 'pechera'])
        ->and($audits[0]->new_values)->toEqual(['svg_layer' => 'pecho'])
        ->and($audits[1]->old_values)->toEqual(['svg_layer' => 'pecho'])
        ->and($audits[1]->new_values)->toEqual(['svg_layer' => null]);
});

it('PRD-007 keeps the svg layer when an edit does not send it', function () {
    $location = DetailLocation::factory()->create(['name' => 'Pechera', 'svg_layer' => 'pechera']);

    $this->actingAs(locationManager())
        ->putJson("/catalog/detail-locations/{$location->id}", ['name' => 'Pechera nueva'])
        ->assertRedirect();

    expect($location->fresh()->svg_layer)->toBe('pechera');
});

it('PRD-007 writes no audit when an edit changes nothing', function () {
    $location = DetailLocation::factory()->create(['name' => 'Pechera', 'svg_layer' => 'pechera']);

    $this->actingAs(locationManager())
        ->putJson("/catalog/detail-locations/{$location->id}", ['name' => 'Pechera', 'svg_layer' => 'pechera'])
        ->assertRedirect();

    expect(locationAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('R3-003 CreateDetailLocation turns a unique-index violation into a name validation error', function () {
    DetailLocation::factory()->create(['name' => 'Pechera']);

    try {
        app(CreateDetailLocation::class)->handle(['name' => 'pechera'], locationManager());
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name');
    }

    expect(DetailLocation::query()->count())->toBe(1)
        ->and(locationAuditRows(AuditAction::CatalogCreated))->toHaveCount(0);
});

it('R3-003 UpdateDetailLocation turns a unique-index violation into a name validation error', function () {
    DetailLocation::factory()->create(['name' => 'Pechera']);
    $other = DetailLocation::factory()->create(['name' => 'Espalda']);

    try {
        app(UpdateDetailLocation::class)->handle($other, ['name' => 'PECHERA'], locationManager());
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name');
    }

    expect($other->fresh()->name)->toBe('Espalda')
        ->and(locationAuditRows(AuditAction::CatalogUpdated))->toHaveCount(0);
});

it('PRD-007 deactivates and reactivates a location auditing the status change', function () {
    $actor = locationManager();
    $location = DetailLocation::factory()->create();

    $this->actingAs($actor)->postJson("/catalog/detail-locations/{$location->id}/deactivate")->assertRedirect();

    expect($location->fresh()->status)->toBe(CatalogStatus::Inactive);

    $deactivated = locationAuditRows(AuditAction::CatalogDeactivated)->sole();

    expect($deactivated->entity_id)->toBe($location->id)
        ->and($deactivated->old_values)->toEqual(['status' => 'active'])
        ->and($deactivated->new_values)->toEqual(['status' => 'inactive']);

    $this->actingAs($actor)->postJson("/catalog/detail-locations/{$location->id}/activate")->assertRedirect();

    expect($location->fresh()->status)->toBe(CatalogStatus::Active);

    $activated = locationAuditRows(AuditAction::CatalogActivated)->sole();

    expect($activated->old_values)->toEqual(['status' => 'inactive'])
        ->and($activated->new_values)->toEqual(['status' => 'active']);
});

it('PRD-013 repeating the same location state writes nothing', function () {
    $actor = locationManager();
    $active = DetailLocation::factory()->create();
    $inactive = DetailLocation::factory()->inactive()->create();

    $this->actingAs($actor)->postJson("/catalog/detail-locations/{$active->id}/activate")->assertRedirect();
    $this->actingAs($actor)->postJson("/catalog/detail-locations/{$inactive->id}/deactivate")->assertRedirect();

    expect($active->fresh()->status)->toBe(CatalogStatus::Active)
        ->and($inactive->fresh()->status)->toBe(CatalogStatus::Inactive)
        ->and(locationAuditRows(AuditAction::CatalogActivated, AuditAction::CatalogDeactivated))->toHaveCount(0);
});

it('PRD-007 never deletes a location: there is no delete route', function () {
    $location = DetailLocation::factory()->create();

    $this->actingAs(locationManager())->deleteJson("/catalog/detail-locations/{$location->id}")->assertStatus(405);

    $deleteRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'catalog/detail-locations') && in_array('DELETE', $route->methods(), true));

    expect($deleteRoutes)->toHaveCount(0)
        ->and(DetailLocation::query()->count())->toBe(1);
});

it('PRD-016 denies each location write to a user without products.catalog and audits authorization.denied', function (string $method, Closure $path, array $payload) {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate);
    $location = DetailLocation::factory()->create(['name' => 'Pechera', 'svg_layer' => 'pechera']);

    $this->actingAs($actor)->json($method, $path($location), $payload)->assertForbidden();

    expect(DetailLocation::query()->count())->toBe(1)
        ->and($location->fresh()->name)->toBe('Pechera')
        ->and($location->fresh()->svg_layer)->toBe('pechera')
        ->and($location->fresh()->status)->toBe(CatalogStatus::Active)
        ->and(locationAuditRows(AuditAction::CatalogCreated, AuditAction::CatalogUpdated, AuditAction::CatalogActivated, AuditAction::CatalogDeactivated))->toHaveCount(0);

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toStartWith('catalog.detail-locations.');
})->with([
    'store' => ['POST', fn () => '/catalog/detail-locations', ['name' => 'Nueva']],
    'update' => ['PUT', fn (DetailLocation $location) => "/catalog/detail-locations/{$location->id}", ['name' => 'Otra']],
    'deactivate' => ['POST', fn (DetailLocation $location) => "/catalog/detail-locations/{$location->id}/deactivate", []],
    'activate' => ['POST', fn (DetailLocation $location) => "/catalog/detail-locations/{$location->id}/activate", []],
]);

it('PRD-007 page GET /catalog/detail-locations renders catalog/DetailLocations listed by name, inactive ones included', function () {
    DetailLocation::factory()->create(['name' => 'pechera', 'svg_layer' => 'pechera']);
    $first = DetailLocation::factory()->create(['name' => 'Bolsillo', 'svg_layer' => null]);
    DetailLocation::factory()->inactive()->create(['name' => 'Manga', 'svg_layer' => 'manga']);

    $this->actingAs(locationManager())->get('/catalog/detail-locations')->assertOk()->assertInertia(function (Assert $page) use ($first) {
        $page->component('catalog/DetailLocations')
            ->has('locations', 3)
            ->where('locations.0', [
                'id' => $first->id,
                'name' => 'Bolsillo',
                'svg_layer' => null,
                'status' => 'active',
                'status_label' => 'Activo',
            ])
            ->where('locations.1.name', 'Manga')
            ->where('locations.1.svg_layer', 'manga')
            ->where('locations.1.status', 'inactive')
            ->where('locations.1.status_label', 'Inactivo')
            ->where('locations.2.name', 'pechera')
            ->where('can.manage', true);
    });
});

it('PRD-007 page renders an empty list of locations', function () {
    $this->actingAs(locationManager())->get('/catalog/detail-locations')->assertInertia(
        fn (Assert $page) => $page->component('catalog/DetailLocations')->has('locations', 0)->where('can.manage', true)
    );
});

it('PRD-016 page GET /catalog/detail-locations is forbidden without products.catalog, even with products.view, and audits the denial', function (array $permissions) {
    $actor = userWithPermissions(...$permissions);
    DetailLocation::factory()->create();

    $this->actingAs($actor)->get('/catalog/detail-locations')->assertForbidden();

    $audit = AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->context['route'])->toBe('catalog.detail-locations.index');
})->with([
    'products.view alone' => [[PermissionName::ProductsView]],
    'every product permission but catalog' => [[PermissionName::ProductsView, PermissionName::ProductsCreate, PermissionName::ProductsUpdate, PermissionName::ProductsDeactivate, PermissionName::ProductsDelete]],
]);

it('PRD-016 page GET /catalog/detail-locations redirects a guest to the login', function () {
    $this->get('/catalog/detail-locations')->assertRedirect('/login');
});

it('DEC-PRD-53 call site of the layer hook lets a location gain, change and lose its svg layer', function () {
    $location = DetailLocation::factory()->create(['name' => 'Pechera', 'svg_layer' => null]);

    foreach (['pechera', 'pecho', null] as $layer) {
        $this->actingAs(locationManager())
            ->putJson("/catalog/detail-locations/{$location->id}", ['name' => 'Pechera', 'svg_layer' => $layer])
            ->assertRedirect();

        expect($location->fresh()->svg_layer)->toBe($layer);
    }
});
