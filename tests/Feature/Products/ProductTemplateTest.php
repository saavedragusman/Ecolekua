<?php

use App\Actions\Products\ListSelectionOptions;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\User;
use App\Support\Products\Selection\CatalogSnapshotLoader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FictitiousSvg;

/*
 * SVG templates of a product (PRD-020, DEC-PRD-29, DEC-PRD-49): upload with sanitizing and layer
 * detection (E-37..E-39), templates by gender (E-41), replacement, removal and serving (E-42). The
 * fixture is the corporate shirt of `resCatalog()` whose sleeve values and sleeve-edge location get a
 * layer, as in E-37.
 */

const TPL_ALL_LAYERS = ['cuerpo', 'manga-corta', 'manga-larga', 'orilla-mangas', 'sombras'];

function tplEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

/**
 * The corporate shirt of E-37: Manga corta (`manga-corta`), Manga larga (`manga-larga`) and the
 * admitted location Orilla de mangas (`orilla-mangas`).
 *
 * @return array<string, mixed>
 */
function tplCatalog(): array
{
    $catalog = resCatalog();
    $catalog['vals']['Manga corta']->update(['svg_layer' => 'manga-corta']);
    $catalog['vals']['Manga larga']->update(['svg_layer' => 'manga-larga']);
    $catalog['locations']['Orilla de mangas']->update(['svg_layer' => 'orilla-mangas']);

    return $catalog;
}

function tplFile(string $svg, string $name = 'camisa.svg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $svg)->mimeType('image/svg+xml');
}

/**
 * @param  array<string, mixed>  $extra
 */
function tplUpload(mixed $test, User $actor, Product $product, string $svg, array $extra = [], string $name = 'camisa.svg'): TestResponse
{
    return $test->actingAs($actor)->postJson("/products/{$product->id}/templates", ['template' => tplFile($svg, $name), ...$extra]);
}

function tplAudits(AuditAction $action): Collection
{
    return AuditLog::query()->where('action', $action->value)->orderBy('id')->get();
}

/**
 * Options of the shirt with every axis of the combination `$code` chosen (the order stage).
 *
 * @param  array<string, mixed>  $catalog
 * @return array<string, mixed>
 */
function tplOptions(array $catalog, string $code): array
{
    $axes = collect(resAxesOf($code))
        ->mapWithKeys(fn (string $name, string $attribute): array => [$catalog['attrs'][$attribute]->id => $catalog['vals'][$name]->id])
        ->all();

    return app(ListSelectionOptions::class)->handle(['product_id' => $catalog['camisa']->id, 'axes' => $axes])->toArray();
}

beforeEach(fn () => Storage::fake('local'));

it('E-37 stores the template with the five layers detected and the file on the private disk with a ULID name', function () {
    $catalog = tplCatalog();

    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(['sombras', ...array_slice(TPL_ALL_LAYERS, 0, 4)]))->assertRedirect();

    $template = ProductTemplate::query()->sole();
    expect($template->product_id)->toBe($catalog['camisa']->id)
        ->and($template->variant_value_id)->toBeNull()
        ->and($template->layers)->toBe(TPL_ALL_LAYERS)
        ->and($template->original_name)->toBe('camisa.svg')
        ->and($template->svg_path)->toMatch('#^catalog/templates/'.$catalog['camisa']->id.'/[0-9a-z]{26}\.svg$#')
        ->and(Storage::disk('local')->allFiles())->toBe([$template->svg_path]);
});

it('E-37 audits the upload with the file name, the variant and the layers', function () {
    $catalog = tplCatalog();

    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(TPL_ALL_LAYERS))->assertRedirect();

    $audit = tplAudits(AuditAction::ProductTemplateUploaded)->sole();
    expect($audit->entity_id)->toBe($catalog['camisa']->id)
        ->and($audit->old_values)->toBe([])
        ->and($audit->new_values)->toEqual(['file' => 'camisa.svg', 'variant' => null, 'layers' => TPL_ALL_LAYERS]);
});

it('E-38 rejects a template without cuerpo or without manga-larga naming the missing layers and stores nothing', function (array $layers, array $missing) {
    $catalog = tplCatalog();

    $response = tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template($layers))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('template');

    foreach ($missing as $layer) {
        expect($response->json('errors.template.0'))->toContain($layer);
    }

    expect(ProductTemplate::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(tplAudits(AuditAction::ProductTemplateUploaded))->toHaveCount(0);
})->with([
    'without cuerpo' => [['manga-corta', 'manga-larga', 'orilla-mangas'], ['cuerpo']],
    'without manga-larga' => [['cuerpo', 'manga-corta', 'orilla-mangas'], ['manga-larga']],
    'without several' => [['sombras'], ['cuerpo', 'manga-corta', 'manga-larga', 'orilla-mangas']],
]);

it('E-38 asks only for cuerpo when nothing in the product carries a layer', function () {
    $product = Product::factory()->create();

    tplUpload($this, tplEditor(), $product, FictitiousSvg::template([]))->assertUnprocessable()->assertJsonValidationErrors('template');
    tplUpload($this, tplEditor(), $product, FictitiousSvg::template(['cuerpo']))->assertRedirect();

    expect(ProductTemplate::query()->sole()->layers)->toBe(['cuerpo']);
});

it('E-39 stores the template without the script and the event attribute and detects the layers on the sanitized file', function () {
    $catalog = tplCatalog();
    $hostile = '<script>alert(1)</script><script><g id="fantasma"/></script><rect width="3" height="3" onclick="alert(2)"/>';

    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(TPL_ALL_LAYERS, $hostile))->assertRedirect();

    $template = ProductTemplate::query()->sole();
    $stored = Storage::disk('local')->get($template->svg_path);
    expect($stored)->not->toContain('script')
        ->and($stored)->not->toContain('onclick')
        ->and($stored)->toContain('id="cuerpo"')
        ->and($template->layers)->toBe(TPL_ALL_LAYERS);
});

it('E-39 rejects files that are not a safe SVG without storing anything', function (string $bytes, string $name) {
    $product = Product::factory()->create();

    tplUpload($this, tplEditor(), $product, $bytes, name: $name)->assertUnprocessable()->assertJsonValidationErrors('template');

    expect(ProductTemplate::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'an entity declaration' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "b">]><svg xmlns="http://www.w3.org/2000/svg"><g id="cuerpo"/></svg>', 'xxe.svg'],
    'a PDF renamed .svg' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF", 'plano.svg'],
    'an html page' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body><g id="cuerpo"/></body></html>', 'pagina.svg'],
    'a file over 2 MB' => [fn () => FictitiousSvg::template(['cuerpo']).str_repeat(' ', 2 * 1024 * 1024), 'grande.svg'],
]);

it('PRD-020 requires a file', function () {
    $product = Product::factory()->create();

    $this->actingAs(tplEditor())->postJson("/products/{$product->id}/templates", [])->assertUnprocessable()->assertJsonValidationErrors('template');
});

it('E-41 stores one template per gender the product admits and the options return the one of the chosen gender', function () {
    $catalog = tplCatalog();
    $camisa = $catalog['camisa'];
    $svg = FictitiousSvg::template(TPL_ALL_LAYERS);

    tplUpload($this, tplEditor(), $camisa, $svg, ['variant_value_id' => $catalog['vals']['Caballero']->id], 'caballero.svg')->assertRedirect();
    tplUpload($this, tplEditor(), $camisa, $svg, ['variant_value_id' => $catalog['vals']['Dama']->id], 'dama.svg')->assertRedirect();

    $dama = ProductTemplate::query()->where('variant_value_id', $catalog['vals']['Dama']->id)->sole();
    $caballero = ProductTemplate::query()->where('variant_value_id', $catalog['vals']['Caballero']->id)->sole();

    $forDama = tplOptions($catalog, '110-1');
    $forMan = tplOptions($catalog, '110');

    expect($forDama['code'])->toBe('110-1')
        ->and($forDama['template']['id'])->toBe($dama->id)
        ->and($forDama['template']['layers'])->toBe(TPL_ALL_LAYERS)
        ->and($forDama['template']['url'])->toStartWith("/products/{$camisa->id}/templates/{$dama->id}")
        ->and($forMan['template']['id'])->toBe($caballero->id)
        ->and(tplAudits(AuditAction::ProductTemplateUploaded)->first()->new_values['variant'])->toBe('Caballero');
});

it('E-41 gives the general template when the product has no template for the chosen gender, and none without templates', function () {
    $catalog = tplCatalog();
    $camisa = $catalog['camisa'];

    expect(tplOptions($catalog, '110-1')['template'])->toBeNull();

    tplUpload($this, tplEditor(), $camisa, FictitiousSvg::template(TPL_ALL_LAYERS))->assertRedirect();
    $general = ProductTemplate::query()->sole();

    expect(tplOptions($catalog, '110-1')['template']['id'])->toBe($general->id)
        ->and(tplOptions($catalog, '110')['template']['id'])->toBe($general->id);
});

it('E-41 gives the general template to an admitted gender without its own template while Caballero and Dama keep theirs (DEC-PRD-99)', function () {
    $catalog = tplCatalog();
    $camisa = $catalog['camisa'];
    $catalog['vals']['Unisex'] = AttributeValue::factory()->for($catalog['attrs']['Género'], 'catalogAttribute')->create(['name' => 'Unisex']);
    $camisa->productAttributes()->where('catalog_attribute_id', $catalog['attrs']['Género']->id)->sole()
        ->allowedValues()->attach($catalog['vals']['Unisex']->id);
    $unisexAxes = [...resAxesOf('110-1'), 'Género' => 'Unisex'];
    resCombination($catalog, $camisa, '110-U', array_map(fn (string $name): array => [$name], $unisexAxes));
    $svg = FictitiousSvg::template(TPL_ALL_LAYERS);

    tplUpload($this, tplEditor(), $camisa, $svg)->assertRedirect();
    tplUpload($this, tplEditor(), $camisa, $svg, ['variant_value_id' => $catalog['vals']['Caballero']->id], 'caballero.svg')->assertRedirect();
    tplUpload($this, tplEditor(), $camisa, $svg, ['variant_value_id' => $catalog['vals']['Dama']->id], 'dama.svg')->assertRedirect();

    $templateOf = fn (?string $gender): int => ProductTemplate::query()
        ->where('product_id', $camisa->id)
        ->where('variant_value_id', $gender === null ? null : $catalog['vals'][$gender]->id)->sole()->id;
    $unisex = app(ListSelectionOptions::class)->handle([
        'product_id' => $camisa->id,
        'axes' => collect($unisexAxes)->mapWithKeys(fn (string $name, string $attribute): array => [$catalog['attrs'][$attribute]->id => $catalog['vals'][$name]->id])->all(),
    ])->toArray();

    expect($unisex['code'])->toBe('110-U')
        ->and($unisex['template']['id'])->toBe($templateOf(null))
        ->and(tplOptions($catalog, '110-1')['template']['id'])->toBe($templateOf('Dama'))
        ->and(tplOptions($catalog, '110')['template']['id'])->toBe($templateOf('Caballero'));
});

it('E-41 rejects a template for a gender the product does not admit, for a value that is not a gender and for a product without the gender attribute', function () {
    $catalog = tplCatalog();
    $unisex = AttributeValue::factory()->for($catalog['attrs']['Género'], 'catalogAttribute')->create(['name' => 'Unisex']);
    $svg = FictitiousSvg::template(TPL_ALL_LAYERS);

    $attempts = [
        [$catalog['camisa'], $unisex->id],
        [$catalog['camisa'], $catalog['vals']['Manga corta']->id],
        [$catalog['camisa'], 999999],
        [$catalog['pantalon'], $catalog['vals']['Dama']->id],
    ];

    foreach ($attempts as [$product, $valueId]) {
        tplUpload($this, tplEditor(), $product, $svg, ['variant_value_id' => $valueId])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variant_value_id');
    }

    expect(ProductTemplate::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('E-41 replaces the template of the same variant and deletes the old file after the commit', function () {
    $catalog = tplCatalog();
    $camisa = $catalog['camisa'];
    $dama = ['variant_value_id' => $catalog['vals']['Dama']->id];

    tplUpload($this, tplEditor(), $camisa, FictitiousSvg::template(TPL_ALL_LAYERS), $dama, 'v1.svg')->assertRedirect();
    $first = ProductTemplate::query()->sole();

    tplUpload($this, tplEditor(), $camisa, FictitiousSvg::template([...TPL_ALL_LAYERS, 'extra']), $dama, 'v2.svg')->assertRedirect();
    $second = ProductTemplate::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->original_name)->toBe('v2.svg')
        ->and($second->layers)->toBe(['cuerpo', 'extra', 'manga-corta', 'manga-larga', 'orilla-mangas', 'sombras'])
        ->and($second->svg_path)->not->toBe($first->svg_path)
        ->and(Storage::disk('local')->allFiles())->toBe([$second->svg_path])
        ->and(tplAudits(AuditAction::ProductTemplateUploaded))->toHaveCount(2)
        ->and(tplAudits(AuditAction::ProductTemplateUploaded)->last()->old_values)
        ->toEqual(['file' => 'v1.svg', 'variant' => 'Dama', 'layers' => TPL_ALL_LAYERS]);
});

it('E-41 keeps the previous template and its file when a replacement is rejected', function () {
    $catalog = tplCatalog();

    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(TPL_ALL_LAYERS))->assertRedirect();
    $first = ProductTemplate::query()->sole();

    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(['cuerpo']))->assertUnprocessable();

    expect(ProductTemplate::query()->sole()->svg_path)->toBe($first->svg_path)
        ->and(Storage::disk('local')->allFiles())->toBe([$first->svg_path]);
});

it('N-5 removes a template, deletes its file after the commit and audits it', function () {
    $catalog = tplCatalog();
    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(TPL_ALL_LAYERS))->assertRedirect();
    $template = ProductTemplate::query()->sole();

    $this->actingAs(tplEditor())->deleteJson("/products/{$catalog['camisa']->id}/templates/{$template->id}")->assertRedirect();

    $audit = tplAudits(AuditAction::ProductTemplateRemoved)->sole();
    expect(ProductTemplate::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($audit->entity_id)->toBe($catalog['camisa']->id)
        ->and($audit->old_values)->toEqual(['file' => 'camisa.svg', 'variant' => null, 'layers' => TPL_ALL_LAYERS])
        ->and($audit->new_values)->toBe([]);
});

it('N-5 answers 404 when the template belongs to another product', function () {
    $catalog = tplCatalog();
    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(TPL_ALL_LAYERS))->assertRedirect();
    $template = ProductTemplate::query()->sole();

    $this->actingAs(tplEditor())->deleteJson("/products/{$catalog['pantalon']->id}/templates/{$template->id}")->assertNotFound();
    $this->actingAs(tplEditor())->get("/products/{$catalog['pantalon']->id}/templates/{$template->id}")->assertNotFound();

    expect(ProductTemplate::query()->count())->toBe(1);
});

it('E-42 (template) answers 403 to a user without products.update on upload and removal and stores nothing', function () {
    $catalog = tplCatalog();
    $viewer = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCatalog, PermissionName::ProductsCreate);
    $template = ProductTemplate::factory()->for($catalog['camisa'])->create();

    tplUpload($this, $viewer, $catalog['camisa'], FictitiousSvg::template(TPL_ALL_LAYERS))->assertForbidden();
    $this->actingAs($viewer)->deleteJson("/products/{$catalog['camisa']->id}/templates/{$template->id}")->assertForbidden();

    expect(ProductTemplate::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(tplAudits(AuditAction::ProductTemplateUploaded))->toHaveCount(0)
        ->and(tplAudits(AuditAction::ProductTemplateRemoved))->toHaveCount(0);
});

it('E-42 (template) serves the stored SVG to products.view with nosniff, the SVG policy and a private cache', function () {
    $catalog = tplCatalog();
    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(TPL_ALL_LAYERS))->assertRedirect();
    $template = ProductTemplate::query()->sole();

    $response = $this->actingAs(userWithPermissions(PermissionName::ProductsView))->get("/products/{$catalog['camisa']->id}/templates/{$template->id}");

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:");
    expect($response->headers->getCacheControlDirective('max-age'))->toBe('86400')
        ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue()
        ->and($response->streamedContent())->toBe(Storage::disk('local')->get($template->svg_path));
});

it('E-42 (template) answers 403 when serving a template without products.view', function () {
    $catalog = tplCatalog();
    tplUpload($this, tplEditor(), $catalog['camisa'], FictitiousSvg::template(TPL_ALL_LAYERS))->assertRedirect();
    $template = ProductTemplate::query()->sole();

    $this->actingAs(userWithPermissions(PermissionName::ProductsUpdate))->get("/products/{$catalog['camisa']->id}/templates/{$template->id}")->assertForbidden();
});

it('PRD-020 loads the templates of a product in one extra query', function () {
    $catalog = tplCatalog();
    ProductTemplate::factory()->for($catalog['camisa'])->create(['layers' => ['cuerpo']]);

    $snapshot = app(CatalogSnapshotLoader::class)->forProduct($catalog['camisa']->id);

    expect($snapshot->templates)->toHaveCount(1)
        ->and($snapshot->templates[0]->layers)->toBe(['cuerpo'])
        ->and($snapshot->templates[0]->variantValueId)->toBeNull();
});
