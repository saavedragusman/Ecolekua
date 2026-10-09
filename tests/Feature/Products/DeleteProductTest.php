<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\Product;
use App\Models\StockMinimumOverride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function deleter(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsDelete);
}

/**
 * A user who can do everything with products except delete them.
 */
function nonDeleter(): User
{
    return userWithPermissions(
        PermissionName::ProductsView,
        PermissionName::ProductsCreate,
        PermissionName::ProductsUpdate,
        PermissionName::ProductsDeactivate,
    );
}

/**
 * The kit catalog plus an "Absorbente por talla" whose size is an axis (2XG, 3XG), a combination per
 * size and a combo whose component of that product admits only 2XG (E-61).
 *
 * @return array{catalog: array<string, mixed>, combo: Combo, two: Combination, three: Combination, product: Product}
 */
function deleteSizedAbsorbentCombo(): array
{
    $catalog = comboCatalog();
    $catalog['services'] = [];
    $product = Product::factory()->diapers()->create(['name' => 'Absorbente por talla']);
    resDeclare($catalog, $product, ['Talla' => ['axis', ['2XG', '3XG']]]);
    $catalog['products']['Absorbente por talla'] = $product;

    $two = resCombination($catalog, $product, 'ABS-2XG', ['Talla' => ['2XG']]);
    $three = resCombination($catalog, $product, 'ABS-3XG', ['Talla' => ['3XG']]);

    $combo = makeCombo($catalog, [
        'components' => [
            comboComponent($catalog, 'Absorbente por talla', 1, ['Talla' => ['2XG']]),
            comboComponent($catalog, 'Protector de cama', 1),
        ],
    ]);

    return ['catalog' => $catalog, 'combo' => $combo, 'two' => $two, 'three' => $three, 'product' => $product];
}

// --- Products (E-27, E-28, E-70) --------------------------------------------------------------

it('E-27 deletes a product without history with its attributes, details, customizations and combinations and audits the copy', function () {
    $catalog = resCatalog();
    $camisa = $catalog['camisa'];
    $camisa->load('category');
    $camisaId = $camisa->id;
    $category = $camisa->category;
    $combinationIds = $camisa->combinations()->pluck('id')->all();
    $actor = deleter();

    $this->actingAs($actor)
        ->delete("/products/{$camisaId}")
        ->assertRedirect('/products')
        ->assertInertiaFlash('message', 'El producto fue eliminado.');

    expect(Product::query()->whereKey($camisaId)->exists())->toBeFalse()
        ->and(DB::table('product_attributes')->where('product_id', $camisaId)->exists())->toBeFalse()
        ->and(DB::table('product_detail_locations')->where('product_id', $camisaId)->exists())->toBeFalse()
        ->and(DB::table('product_customizations')->where('product_id', $camisaId)->exists())->toBeFalse()
        ->and(Combination::query()->whereIn('id', $combinationIds)->exists())->toBeFalse()
        ->and(CatalogCode::query()->whereIn('combination_id', $combinationIds)->exists())->toBeFalse()
        ->and(DB::table('combination_values')->whereIn('combination_id', $combinationIds)->exists())->toBeFalse()
        ->and(Product::query()->where('name', 'Pantalón de trabajo')->exists())->toBeTrue()
        ->and($catalog['services']['Bordado pequeño']->fresh())->not->toBeNull();

    $audit = AuditLog::query()->where('action', AuditAction::ProductDeleted->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($camisa->getMorphClass())
        ->and($audit->entity_id)->toBe($camisaId)
        ->and($audit->new_values)->toBeEmpty()
        ->and($audit->old_values)->toMatchArray([
            'name' => 'Camisa corporativa',
            'category_name' => $category->name,
            'business_line' => $camisa->business_line->value,
            'supply_mode' => $camisa->supply_mode->value,
            'status' => 'active',
            'detail_locations' => ['Orilla de mangas', 'Pechera'],
            'customizations' => ['Bordado pequeño'],
            'template_files' => [],
            'stock_minimum_overrides' => [],
        ])
        ->and(array_column($audit->old_values['structure'], 'attribute'))->toBe(['Tela', 'Modelo', 'Manga', 'Género', 'Talla', 'Color'])
        ->and($audit->old_values['structure'][0])->toEqual(['attribute' => 'Tela', 'role' => 'axis', 'values' => ['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra']])
        ->and(array_column($audit->old_values['combinations'], 'code'))->toEqualCanonicalizing(['110', '110-1', '110-2', '110-4', '159-1'])
        ->and(collect($audit->old_values['combinations'])->firstWhere('code', '110-2'))->toMatchArray([
            'axes' => ['Tela' => ['ALG-OXF Pima'], 'Modelo' => ['Columbia especial'], 'Manga' => ['Manga larga'], 'Género' => ['Dama']],
            'restrictions' => ['Talla' => ['L', 'M', 'S', 'XL']],
            'included_customizations' => ['Vinil'],
        ]);
});

it('E-27 keeps the own minimums of the combinations in the audit copy and deletes them with the product', function () {
    $catalog = resCatalog();
    $gorra = $catalog['gorra'];
    StockMinimumOverride::query()->create(['combination_id' => $catalog['combos']['G-01']->id, 'size_value_id' => null, 'minimum' => 9]);

    $this->actingAs(deleter())->delete("/products/{$gorra->id}")->assertRedirect('/products');

    $audit = AuditLog::query()->where('action', AuditAction::ProductDeleted->value)->sole();

    expect($audit->old_values['stock_minimum_overrides'])->toBe([['code' => 'G-01', 'size' => null, 'minimum' => 9]])
        ->and(StockMinimumOverride::query()->count())->toBe(0);
});

it('E-27 deletes the stored images of the product only after the commit', function () {
    Storage::fake('local');
    $catalog = resCatalog();
    $pantalon = $catalog['pantalon'];
    $pantalon->forceFill(['image_display_path' => 'catalog/products/1/display.webp', 'image_thumb_path' => 'catalog/products/1/thumb.webp'])->save();
    Storage::disk('local')->put('catalog/products/1/display.webp', 'a');
    Storage::disk('local')->put('catalog/products/1/thumb.webp', 'b');

    $this->actingAs(deleter())->delete("/products/{$pantalon->id}")->assertRedirect('/products');

    expect(Storage::disk('local')->exists('catalog/products/1/display.webp'))->toBeFalse()
        ->and(Storage::disk('local')->exists('catalog/products/1/thumb.webp'))->toBeFalse();
});

it('E-28 rejects deleting a product that is a combo component, names the combo and suggests deactivating', function () {
    Storage::fake('local');
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $absorbent = $catalog['products']['Absorbente'];
    $absorbent->forceFill(['image_display_path' => 'catalog/products/9/display.webp'])->save();
    Storage::disk('local')->put('catalog/products/9/display.webp', 'a');

    $response = $this->actingAs(deleter())->deleteJson("/products/{$absorbent->id}")->assertUnprocessable();

    expect($response->json('message'))->toContain('«K-ORO (Kit Oro antiderrame)»')->toContain('desactivarlo')
        ->and(Product::query()->whereKey($absorbent->id)->exists())->toBeTrue()
        ->and(ComboComponent::query()->where('combo_id', $combo->id)->count())->toBe(3)
        ->and(Storage::disk('local')->exists('catalog/products/9/display.webp'))->toBeTrue()
        ->and(comboAudit(AuditAction::ProductDeleted))->toHaveCount(0);
});

it('E-28 shows the rejection as an error flash when the request is not JSON', function () {
    $catalog = comboCatalog();
    makeCombo($catalog);

    $this->actingAs(deleter())
        ->from('/products')
        ->delete("/products/{$catalog['products']['Absorbente']->id}")
        ->assertRedirect('/products')
        ->assertInertiaFlash('type', 'error');
});

it('E-70 (delete) rejects deleting a service that a product admits and a combination includes, naming both', function () {
    $catalog = resCatalog();
    $service = $catalog['services']['Bordado pequeño'];
    resCombination($catalog, $catalog['polo'], 'P-02', [], [], ['Bordado pequeño'], active: false);

    $response = $this->actingAs(deleter())->deleteJson("/products/{$service->id}")->assertUnprocessable();

    expect($response->json('message'))->toContain('«Camisa corporativa»')->toContain('«P-02 (Polo sin personalizado)»')
        ->and(Product::query()->whereKey($service->id)->exists())->toBeTrue()
        ->and(DB::table('product_customizations')->where('service_product_id', $service->id)->count())->toBe(1)
        ->and(DB::table('combination_customizations')->where('service_product_id', $service->id)->count())->toBe(1)
        ->and(comboAudit(AuditAction::ProductDeleted))->toHaveCount(0);
});

it('E-70 (delete) rejects a service admitted by a product only, and one included by a combination only', function (string $case) {
    $catalog = resCatalog();
    $service = $catalog['services'][$case === 'admitted' ? 'Bordado pequeño' : 'Estampado'];

    if ($case === 'included') {
        resCombination($catalog, $catalog['polo'], 'P-02', [], [], ['Estampado'], active: false);
    }

    $response = $this->actingAs(deleter())->deleteJson("/products/{$service->id}")->assertUnprocessable();

    expect($response->json('message'))->toContain($case === 'admitted' ? '«Camisa corporativa»' : '«P-02 (Polo sin personalizado)»')
        ->and(Product::query()->whereKey($service->id)->exists())->toBeTrue();
})->with(['admitted', 'included']);

it('E-70 (delete) deletes a service nobody uses', function () {
    $catalog = resCatalog();
    $service = $catalog['services']['Vinil'];
    DB::table('combination_customizations')->where('service_product_id', $service->id)->delete();

    $this->actingAs(deleter())->delete("/products/{$service->id}")->assertRedirect('/products');

    expect(Product::query()->whereKey($service->id)->exists())->toBeFalse();
});

// --- Combinations (E-61) ----------------------------------------------------------------------

it('E-61 rejects deleting the combination the component admits and deletes the one it does not', function () {
    $fixture = deleteSizedAbsorbentCombo();
    $product = $fixture['product'];
    $actor = deleter();

    $response = $this->actingAs($actor)->deleteJson("/products/{$product->id}/combinations/{$fixture['two']->id}")->assertUnprocessable();

    expect($response->json('message'))->toContain('«K-ORO (Kit Oro antiderrame)»')->toContain('desactivarla')
        ->and(Combination::query()->whereKey($fixture['two']->id)->exists())->toBeTrue()
        ->and(comboAudit(AuditAction::CombinationDeleted))->toHaveCount(0);

    $this->actingAs($actor)
        ->delete("/products/{$product->id}/combinations/{$fixture['three']->id}")
        ->assertRedirect("/products/{$product->id}")
        ->assertInertiaFlash('message', 'La combinación fue eliminada.');

    $audit = comboAudit(AuditAction::CombinationDeleted)->sole();

    expect(Combination::query()->whereKey($fixture['three']->id)->exists())->toBeFalse()
        ->and(CatalogCode::query()->where('code', 'ABS-3XG')->exists())->toBeFalse()
        ->and(DB::table('combination_values')->where('combination_id', $fixture['three']->id)->exists())->toBeFalse()
        ->and($audit->entity_id)->toBe($fixture['three']->id)
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->old_values)->toMatchArray([
            'code' => 'ABS-3XG',
            'axes' => ['Talla' => ['3XG']],
            'restrictions' => [],
            'included_customizations' => [],
            'stock_minimum_overrides' => [],
        ]);
});

it('E-61 rejects every combination of a product whose component restricts nothing', function () {
    $fixture = deleteSizedAbsorbentCombo();
    $catalog = $fixture['catalog'];
    $combo = makeCombo($catalog, [
        'name' => 'Kit abierto',
        'code' => 'K-OPEN',
        'components' => [comboComponent($catalog, 'Absorbente por talla', 1), comboComponent($catalog, 'Protector de cama')],
    ]);
    // The first combo admits only 2XG; this one admits both sizes, so the 3XG combination is part of a combo too.

    $response = $this->actingAs(deleter())
        ->deleteJson("/products/{$fixture['product']->id}/combinations/{$fixture['three']->id}")
        ->assertUnprocessable();

    expect($response->json('message'))->toContain('«K-OPEN (Kit abierto)»')->not->toContain('K-ORO')
        ->and(Combination::query()->whereKey($fixture['three']->id)->exists())->toBeTrue()
        ->and($combo->exists)->toBeTrue();
});

it('PRD-014 keeps the own minimums of a deleted combination in the audit copy', function () {
    $catalog = resCatalog();
    $combination = $catalog['combos']['G-01'];
    StockMinimumOverride::query()->create(['combination_id' => $combination->id, 'size_value_id' => null, 'minimum' => 4]);

    $this->actingAs(deleter())->delete("/products/{$catalog['gorra']->id}/combinations/{$combination->id}")->assertRedirect();

    $audit = comboAudit(AuditAction::CombinationDeleted)->sole();

    expect($audit->old_values['stock_minimum_overrides'])->toBe([['code' => 'G-01', 'size' => null, 'minimum' => 4]])
        ->and(StockMinimumOverride::query()->count())->toBe(0);
});

it('PRD-014 answers 404 for a combination addressed under another product', function () {
    $catalog = resCatalog();

    $this->actingAs(deleter())->delete("/products/{$catalog['pantalon']->id}/combinations/{$catalog['combos']['G-01']->id}")->assertNotFound();

    expect(Combination::query()->whereKey($catalog['combos']['G-01']->id)->exists())->toBeTrue();
});

// --- Combos -----------------------------------------------------------------------------------

it('PRD-014 deletes a combo with its components and code and audits the copy', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);
    $actor = deleter();

    $this->actingAs($actor)
        ->delete("/combos/{$combo->id}")
        ->assertRedirect('/combos')
        ->assertInertiaFlash('message', 'El combo fue eliminado.');

    $audit = comboAudit(AuditAction::ComboDeleted)->sole();

    expect(Combo::query()->count())->toBe(0)
        ->and(ComboComponent::query()->count())->toBe(0)
        ->and(DB::table('combo_component_values')->count())->toBe(0)
        ->and(CatalogCode::query()->whereNotNull('combo_id')->count())->toBe(0)
        ->and(Product::query()->count())->toBe(6)
        ->and($audit->entity_id)->toBe($combo->id)
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->old_values)->toMatchArray(['code' => 'K-ORO', 'name' => 'Kit Oro antiderrame', 'portal_visible' => true])
        ->and(array_column($audit->old_values['components'], 'product'))->toBe(['Pañal antiderrame', 'Absorbente', 'Protector de cama'])
        ->and($audit->old_values['components'][0])->toEqual(['product' => 'Pañal antiderrame', 'quantity' => 2, 'values' => ['Talla' => ['3XG', '4XG', '5XG']]]);
});

it('PRD-014 frees the code and the name of a deleted combo', function () {
    $catalog = comboCatalog();
    $combo = makeCombo($catalog);

    $this->actingAs(deleter())->delete("/combos/{$combo->id}")->assertRedirect('/combos');

    expect(makeCombo($catalog)->name)->toBe('Kit Oro antiderrame');
});

// --- Permission (PRD-016, FND-022) ------------------------------------------------------------

it('PRD-014 requires products.delete on each endpoint and audits the denial', function (string $target) {
    $fixture = deleteSizedAbsorbentCombo();
    $free = $fixture['catalog']['products']['Camisa corporativa'];
    $uri = match ($target) {
        'product' => "/products/{$free->id}",
        'combination' => "/products/{$fixture['product']->id}/combinations/{$fixture['three']->id}",
        'combo' => "/combos/{$fixture['combo']->id}",
    };

    $this->actingAs(nonDeleter())->deleteJson($uri)->assertForbidden();

    expect(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->count())->toBe(1)
        ->and(Product::query()->whereKey($free->id)->exists())->toBeTrue()
        ->and(Combination::query()->whereKey($fixture['three']->id)->exists())->toBeTrue()
        ->and(Combo::query()->whereKey($fixture['combo']->id)->exists())->toBeTrue();
})->with(['product', 'combination', 'combo']);

it('PRD-014 redirects a guest to the login and answers 404 for unknown records', function () {
    $catalog = resCatalog();

    $this->delete("/products/{$catalog['pantalon']->id}")->assertRedirect('/login');
    $this->actingAs(deleter())->delete('/products/999999')->assertNotFound();
    $this->actingAs(deleter())->delete('/combos/999999')->assertNotFound();
});

// --- History (deferred) -----------------------------------------------------------------------

it('E-29 bloqueo por historial')->todo('se prueba en 004, 006 y 008');
