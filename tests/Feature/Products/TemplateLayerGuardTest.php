<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\DetailLocation;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductTemplate;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
 * Layer guards (PRD-020, DEC-PRD-53): a product cannot admit a value or a location whose layer is
 * missing from one of its templates (E-40), and the catalog cannot give a value or a location a layer
 * that a template of a product admitting it lacks (E-72). Fixtures are fictitious and self-contained.
 */

function guardEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

function guardCatalogManager(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCatalog);
}

/**
 * A shirt with the sleeve attribute as an axis admitting Manga corta (`manga-corta`) and Manga larga
 * (`manga-larga`), the location Orilla de mangas (`orilla-mangas`) and a template with exactly those
 * layers plus `cuerpo`. `Manga 3/4` (`manga-3-4`) and `Manga tres cuartos` (`manga-tres-cuartos`) exist
 * in the catalog but are not admitted yet.
 *
 * @return array{product: Product, sleeves: CatalogAttribute, values: array<string, AttributeValue>, edge: DetailLocation, template: ProductTemplate}
 */
function guardShirt(): array
{
    $sleeves = CatalogAttribute::factory()->create(['name' => 'Manga']);
    $values = [];

    foreach (['Manga corta' => 'manga-corta', 'Manga larga' => 'manga-larga', 'Manga 3/4' => 'manga-3-4', 'Manga tres cuartos' => 'manga-tres-cuartos', 'Manga lisa' => null] as $name => $layer) {
        $values[$name] = AttributeValue::factory()->for($sleeves, 'catalogAttribute')->create(['name' => $name, 'svg_layer' => $layer, 'sort_order' => count($values) + 1]);
    }

    $product = Product::factory()->create(['name' => 'Camisa corporativa']);
    ProductAttribute::factory()->axis()->create(['product_id' => $product->id, 'catalog_attribute_id' => $sleeves->id, 'sort_order' => 1])
        ->allowedValues()->attach([$values['Manga corta']->id, $values['Manga larga']->id]);

    $edge = DetailLocation::factory()->create(['name' => 'Orilla de mangas', 'svg_layer' => 'orilla-mangas']);
    $product->detailLocations()->attach($edge->id);

    $template = ProductTemplate::factory()->for($product)->create(['layers' => ['cuerpo', 'manga-corta', 'manga-larga', 'orilla-mangas']]);

    return compact('product', 'sleeves', 'values', 'edge', 'template');
}

/**
 * @param  array<string, mixed>  $shirt
 * @param  list<string>  $valueNames
 */
function guardPutSleeves(mixed $test, array $shirt, array $valueNames): TestResponse
{
    return $test->actingAs(guardEditor())->putJson("/products/{$shirt['product']->id}/attributes", ['attributes' => [[
        'attribute_id' => $shirt['sleeves']->id,
        'role' => 'axis',
        'allowed_value_ids' => array_map(fn (string $name): int => $shirt['values'][$name]->id, $valueNames),
    ]]]);
}

/**
 * @param  list<int>  $locationIds
 */
function guardPutLocations(mixed $test, Product $product, array $locationIds): TestResponse
{
    return $test->actingAs(guardEditor())->putJson("/products/{$product->id}", [
        'name' => $product->name,
        'product_category_id' => $product->product_category_id,
        'business_line' => $product->business_line->value,
        'supply_mode' => $product->supply_mode->value,
        'detail_location_ids' => $locationIds,
    ]);
}

/**
 * @return list<string>
 */
function guardAllowedNames(Product $product): array
{
    return $product->productAttributes()->firstOrFail()->allowedValues()->pluck('attribute_values.name')->sort()->values()->all();
}

it('E-40 rejects adding a Manga value whose layer is missing from the template naming the layer and changes nothing', function () {
    $shirt = guardShirt();

    $response = guardPutSleeves($this, $shirt, ['Manga corta', 'Manga larga', 'Manga tres cuartos'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attributes');

    expect($response->json('errors.attributes.0'))->toContain('manga-tres-cuartos')
        ->and(guardAllowedNames($shirt['product']))->toBe(['Manga corta', 'Manga larga'])
        ->and(AuditLog::query()->where('action', AuditAction::ProductAttributesUpdated->value)->count())->toBe(0);
});

it('E-40 accepts adding a value whose layer the template has or that has no layer', function () {
    $shirt = guardShirt();
    $shirt['template']->update(['layers' => ['cuerpo', 'manga-corta', 'manga-larga', 'manga-3-4', 'orilla-mangas']]);

    guardPutSleeves($this, $shirt, ['Manga corta', 'Manga larga', 'Manga 3/4', 'Manga lisa'])->assertRedirect();

    expect(guardAllowedNames($shirt['product']))->toBe(['Manga 3/4', 'Manga corta', 'Manga larga', 'Manga lisa']);
});

it('E-40 checks every template of the product, including those by gender', function () {
    $shirt = guardShirt();
    $shirt['template']->update(['layers' => ['cuerpo', 'manga-corta', 'manga-larga', 'manga-3-4', 'orilla-mangas']]);
    $gender = CatalogAttribute::factory()->gender()->create();
    $dama = AttributeValue::factory()->for($gender, 'catalogAttribute')->create(['name' => 'Dama']);
    ProductTemplate::factory()->for($shirt['product'])->create(['variant_value_id' => $dama->id, 'layers' => ['cuerpo', 'manga-corta', 'manga-larga', 'orilla-mangas']]);

    $response = guardPutSleeves($this, $shirt, ['Manga corta', 'Manga larga', 'Manga 3/4'])->assertUnprocessable();

    expect($response->json('errors.attributes.0'))->toContain('manga-3-4');
});

it('E-40 does not touch the values the product already admitted when a template lacks their layer', function () {
    $shirt = guardShirt();
    $shirt['template']->update(['layers' => ['cuerpo', 'manga-corta', 'orilla-mangas']]);

    guardPutSleeves($this, $shirt, ['Manga corta', 'Manga larga'])->assertRedirect();

    expect(guardAllowedNames($shirt['product']))->toBe(['Manga corta', 'Manga larga']);
});

it('E-40 accepts any value when the product has no template', function () {
    $shirt = guardShirt();
    $shirt['template']->delete();

    guardPutSleeves($this, $shirt, ['Manga corta', 'Manga larga', 'Manga tres cuartos'])->assertRedirect();

    expect(guardAllowedNames($shirt['product']))->toContain('Manga tres cuartos');
});

it('PRD-020 rejects adding a detail location whose layer is missing from the template naming the layer', function () {
    $shirt = guardShirt();
    $chest = DetailLocation::factory()->create(['name' => 'Pechera', 'svg_layer' => 'pechera']);

    $response = guardPutLocations($this, $shirt['product'], [$shirt['edge']->id, $chest->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('detail_location_ids');

    expect($response->json('errors.detail_location_ids.0'))->toContain('pechera')
        ->and($shirt['product']->detailLocations()->pluck('detail_locations.id')->all())->toBe([$shirt['edge']->id]);
});

it('PRD-020 accepts a detail location without layer and one already admitted even if a template lacks its layer', function () {
    $shirt = guardShirt();
    $plain = DetailLocation::factory()->create(['name' => 'Espalda', 'svg_layer' => null]);
    $shirt['template']->update(['layers' => ['cuerpo', 'manga-corta', 'manga-larga']]);

    guardPutLocations($this, $shirt['product'], [$shirt['edge']->id, $plain->id])->assertRedirect();

    expect($shirt['product']->detailLocations()->pluck('detail_locations.id')->sort()->values()->all())->toBe([$shirt['edge']->id, $plain->id]);
});

it('E-72 rejects changing the layer of a value that a product with a template lacking the new layer admits', function () {
    $shirt = guardShirt();
    $threeQuarters = $shirt['values']['Manga 3/4'];
    $shirt['product']->productAttributes()->firstOrFail()->allowedValues()->attach($threeQuarters->id);
    $shirt['template']->update(['layers' => ['cuerpo', 'manga-corta', 'manga-larga', 'manga-3-4', 'orilla-mangas']]);

    $response = $this->actingAs(guardCatalogManager())
        ->putJson("/catalog/values/{$threeQuarters->id}", ['name' => 'Manga 3/4', 'svg_layer' => 'manga-tres-cuartos'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('svg_layer');

    expect($response->json('errors.svg_layer.0'))->toContain('Camisa corporativa')->toContain('manga-tres-cuartos')
        ->and($threeQuarters->fresh()->svg_layer)->toBe('manga-3-4')
        ->and(AuditLog::query()->where('action', AuditAction::CatalogUpdated->value)->count())->toBe(0);
});

it('E-72 rejects changing the layer of a detail location with the same rule', function () {
    $shirt = guardShirt();

    $response = $this->actingAs(guardCatalogManager())
        ->putJson("/catalog/detail-locations/{$shirt['edge']->id}", ['name' => 'Orilla de mangas', 'svg_layer' => 'orilla-nueva'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('svg_layer');

    expect($response->json('errors.svg_layer.0'))->toContain('Camisa corporativa')
        ->and($shirt['edge']->fresh()->svg_layer)->toBe('orilla-mangas');
});

it('DEC-PRD-53 accepts the new layer when the template has it, the value is admitted by no product with templates, or the layer is cleared', function () {
    $shirt = guardShirt();
    $threeQuarters = $shirt['values']['Manga 3/4'];
    $unused = $shirt['values']['Manga tres cuartos'];
    $shirt['product']->productAttributes()->firstOrFail()->allowedValues()->attach($threeQuarters->id);
    $shirt['template']->update(['layers' => ['cuerpo', 'manga-corta', 'manga-larga', 'manga-3-4', 'manga-tres-cuartos', 'orilla-mangas']]);
    $actor = guardCatalogManager();

    // The template has the new layer.
    $this->actingAs($actor)->putJson("/catalog/values/{$threeQuarters->id}", ['name' => 'Manga 3/4', 'svg_layer' => 'manga-tres-cuartos'])->assertRedirect();
    // Admitted by no product: nothing to check.
    $this->actingAs($actor)->putJson("/catalog/values/{$unused->id}", ['name' => 'Manga tres cuartos', 'svg_layer' => 'otra-capa'])->assertRedirect();
    // Clearing is always allowed.
    $this->actingAs($actor)->putJson("/catalog/values/{$threeQuarters->id}", ['name' => 'Manga 3/4', 'svg_layer' => null])->assertRedirect();

    expect($threeQuarters->fresh()->svg_layer)->toBeNull()
        ->and($unused->fresh()->svg_layer)->toBe('otra-capa');
});

it('DEC-PRD-53 accepts a value admitted only by products without templates', function () {
    $shirt = guardShirt();
    $shirt['template']->delete();

    $this->actingAs(guardCatalogManager())
        ->putJson("/catalog/values/{$shirt['values']['Manga corta']->id}", ['name' => 'Manga corta', 'svg_layer' => 'manga-corta-nueva'])
        ->assertRedirect();

    expect($shirt['values']['Manga corta']->fresh()->svg_layer)->toBe('manga-corta-nueva');
});

it('DEC-PRD-53 accepts re-sending the unchanged layer on a rename even if a template lacks it', function () {
    $shirt = guardShirt();
    $shirt['template']->update(['layers' => ['cuerpo', 'manga-larga', 'orilla-mangas']]);
    $actor = guardCatalogManager();

    $this->actingAs($actor)->putJson("/catalog/values/{$shirt['values']['Manga corta']->id}", ['name' => 'Manga corta renombrada', 'svg_layer' => 'manga-corta'])->assertRedirect();
    $this->actingAs($actor)->putJson("/catalog/detail-locations/{$shirt['edge']->id}", ['name' => 'Orilla renombrada', 'svg_layer' => 'orilla-mangas'])->assertRedirect();

    expect($shirt['values']['Manga corta']->fresh()->name)->toBe('Manga corta renombrada')
        ->and($shirt['edge']->fresh()->name)->toBe('Orilla renombrada');
});

it('DEC-PRD-53 never runs the guard for a null layer on create and accepts a new layer on a value no product admits yet', function () {
    $shirt = guardShirt();
    $actor = guardCatalogManager();

    $this->actingAs($actor)->postJson("/catalog/attributes/{$shirt['sleeves']->id}/values", ['name' => 'Manga sin capa', 'svg_layer' => null])->assertRedirect();
    $this->actingAs($actor)->postJson("/catalog/attributes/{$shirt['sleeves']->id}/values", ['name' => 'Manga con capa', 'svg_layer' => 'manga-nueva'])->assertRedirect();
    $this->actingAs($actor)->postJson('/catalog/detail-locations', ['name' => 'Pechera nueva', 'svg_layer' => 'pechera-nueva'])->assertRedirect();

    expect(AttributeValue::query()->where('name', 'Manga con capa')->value('svg_layer'))->toBe('manga-nueva')
        ->and(DetailLocation::query()->where('name', 'Pechera nueva')->value('svg_layer'))->toBe('pechera-nueva');
});
