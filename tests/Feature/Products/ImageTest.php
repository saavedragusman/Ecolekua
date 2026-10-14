<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\DetailLocation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FictitiousImage;

/**
 * The three owners of an image (PRD-020): the product, the value of an attribute and the detail
 * location. Each entry is the fictitious owner, its URL prefix, the storage directory, the audit
 * action of a change and the permission that manages its image (E-35, E-42).
 *
 * @return array{model: Model, url: string, directory: string, action: AuditAction, manage: PermissionName, label: string}
 */
function imageOwner(string $kind): array
{
    return match ($kind) {
        'product' => (function (): array {
            $model = Product::factory()->create(['name' => 'Camisa corporativa']);

            return ['model' => $model, 'url' => "/products/{$model->id}/image", 'directory' => "catalog/products/{$model->id}",
                'action' => AuditAction::ProductUpdated, 'manage' => PermissionName::ProductsUpdate, 'label' => 'producto'];
        })(),
        'value' => (function (): array {
            $model = AttributeValue::factory()->create(['name' => 'ALG-OXF PIMA']);

            return ['model' => $model, 'url' => "/catalog/values/{$model->id}/image", 'directory' => "catalog/values/{$model->id}",
                'action' => AuditAction::CatalogUpdated, 'manage' => PermissionName::ProductsCatalog, 'label' => 'valor'];
        })(),
        'location' => (function (): array {
            $model = DetailLocation::factory()->create(['name' => 'Pechera']);

            return ['model' => $model, 'url' => "/catalog/detail-locations/{$model->id}/image", 'directory' => "catalog/locations/{$model->id}",
                'action' => AuditAction::CatalogUpdated, 'manage' => PermissionName::ProductsCatalog, 'label' => 'ubicación'];
        })(),
    };
}

function imageManager(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate, PermissionName::ProductsCatalog);
}

function uploadOf(string $bytes, string $name = 'foto.jpg', string $mime = 'image/jpeg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime);
}

/**
 * Uploads a fictitious JPG to the owner and returns the refreshed model.
 */
function uploadImage(mixed $test, array $owner, ?string $bytes = null): Model
{
    $test->actingAs(imageManager())
        ->post($owner['url'], ['image' => uploadOf($bytes ?? FictitiousImage::jpeg(120, 80))])
        ->assertRedirect();

    return $owner['model']->refresh();
}

/**
 * @return Collection<int, AuditLog>
 */
function imageAudits(AuditAction $action): Collection
{
    return AuditLog::query()->where('action', $action->value)->orderBy('id')->get();
}

beforeEach(fn () => Storage::fake('local'));

it('E-35 stores the uploaded image of the owner as two WebP variants on the private disk with ULID names', function (string $kind) {
    $owner = imageOwner($kind);

    $model = uploadImage($this, $owner);

    $pattern = '#^'.preg_quote($owner['directory'], '#').'/[0-9a-z]{26}\.webp$#';
    expect($model->image_display_path)->toMatch($pattern)
        ->and($model->image_thumb_path)->toMatch($pattern)
        ->and($model->image_display_path)->not->toBe($model->image_thumb_path)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2)
        ->and(getimagesizefromstring(Storage::disk('local')->get($model->image_display_path))[2])->toBe(IMAGETYPE_WEBP)
        ->and(getimagesizefromstring(Storage::disk('local')->get($model->image_thumb_path))[2])->toBe(IMAGETYPE_WEBP);
})->with(['product', 'value', 'location']);

it('E-35 audits the first upload with the stored path as the new image', function (string $kind) {
    $owner = imageOwner($kind);

    $model = uploadImage($this, $owner);

    $audit = imageAudits($owner['action'])->sole();
    expect($audit->entity_id)->toBe($model->id)
        ->and($audit->old_values)->toBe(['image' => null])
        ->and($audit->new_values)->toBe(['image' => $model->image_display_path]);
})->with(['product', 'value', 'location']);

it('E-35 rejects a PDF renamed to .jpg, stores nothing and audits nothing', function (string $kind) {
    $owner = imageOwner($kind);
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";

    $this->actingAs(imageManager())
        ->postJson($owner['url'], ['image' => uploadOf($pdf, 'plano.jpg', 'application/pdf')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect($owner['model']->refresh()->image_display_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(imageAudits($owner['action']))->toHaveCount(0);
})->with(['product', 'value', 'location']);

it('E-35 rejects a file over 2 MB', function (string $kind) {
    $owner = imageOwner($kind);
    $oversize = UploadedFile::fake()->create('grande.jpg', 2049, 'image/jpeg');

    $this->actingAs(imageManager())
        ->postJson($owner['url'], ['image' => $oversize])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect($owner['model']->refresh()->image_display_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['product', 'value', 'location']);

it('E-35 rejects an image whose long side is over 1920 px with a message that names the limit', function () {
    $owner = imageOwner('product');

    $response = $this->actingAs(imageManager())
        ->postJson($owner['url'], ['image' => uploadOf(FictitiousImage::png(1921, 40), 'ancha.png', 'image/png')])
        ->assertUnprocessable();

    expect($response->json('errors.image.0'))->toContain('1920')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('E-35 rejects a request without a file', function () {
    $owner = imageOwner('value');

    $this->actingAs(imageManager())->postJson($owner['url'], [])->assertUnprocessable()->assertJsonValidationErrors('image');
});

it('E-35 replaces the image: audits the old and new paths and deletes the old files after the commit', function (string $kind) {
    $owner = imageOwner($kind);
    $first = uploadImage($this, $owner);
    $oldDisplay = $first->image_display_path;
    $oldThumb = $first->image_thumb_path;

    $second = uploadImage($this, $owner, FictitiousImage::png(60, 60));

    // `DB::afterCommit()` callbacks run under RefreshDatabase: the old files are gone after the request.
    expect($second->image_display_path)->not->toBe($oldDisplay)
        ->and(Storage::disk('local')->exists($oldDisplay))->toBeFalse()
        ->and(Storage::disk('local')->exists($oldThumb))->toBeFalse()
        ->and(Storage::disk('local')->exists($second->image_display_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);

    $audits = imageAudits($owner['action']);
    expect($audits)->toHaveCount(2)
        ->and($audits[1]->old_values)->toBe(['image' => $oldDisplay])
        ->and($audits[1]->new_values)->toBe(['image' => $second->image_display_path]);
})->with(['product', 'value', 'location']);

it('E-35 removes the image: clears the paths, deletes the files after the commit and audits the removal', function (string $kind) {
    $owner = imageOwner($kind);
    $model = uploadImage($this, $owner);
    $display = $model->image_display_path;

    $this->actingAs(imageManager())->delete($owner['url'])->assertRedirect();

    $model->refresh();
    expect($model->image_display_path)->toBeNull()
        ->and($model->image_thumb_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $audit = imageAudits($owner['action'])->last();
    expect($audit->old_values)->toBe(['image' => $display])
        ->and($audit->new_values)->toBe(['image' => null]);
})->with(['product', 'value', 'location']);

it('E-35 removing an owner without image changes nothing and audits nothing', function (string $kind) {
    $owner = imageOwner($kind);

    $this->actingAs(imageManager())->delete($owner['url'])->assertRedirect();

    expect(imageAudits($owner['action']))->toHaveCount(0);
})->with(['product', 'value', 'location']);

it('E-35 deletes the newly written files when the transaction fails and keeps the previous image', function (string $kind) {
    $owner = imageOwner($kind);
    $model = uploadImage($this, $owner);
    $previous = [$model->image_display_path, $model->image_thumb_path];

    $this->mock(RecordAuditEvent::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(new RuntimeException('audit down')));

    $this->actingAs(imageManager())
        ->postJson($owner['url'], ['image' => uploadOf(FictitiousImage::png(50, 50), 'otra.png', 'image/png')])
        ->assertServerError();

    expect($model->refresh()->image_display_path)->toBe($previous[0])
        ->and(Storage::disk('local')->allFiles())->toEqualCanonicalizing($previous);
})->with(['product', 'value', 'location']);

it('E-42 answers 403 to a user without the permission that manages the image', function (string $kind) {
    $owner = imageOwner($kind);
    $permissions = array_filter(
        [PermissionName::ProductsView, PermissionName::ProductsUpdate, PermissionName::ProductsCatalog],
        fn (PermissionName $permission): bool => $permission !== $owner['manage'],
    );
    $actor = userWithPermissions(...$permissions);

    $this->actingAs($actor)->postJson($owner['url'], ['image' => uploadOf(FictitiousImage::jpeg())])->assertForbidden();
    $this->actingAs($actor)->deleteJson($owner['url'])->assertForbidden();

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($owner['model']->refresh()->image_display_path)->toBeNull();
})->with([
    'product without products.update' => ['product'],
    'value without products.catalog' => ['value'],
    'location without products.catalog' => ['location'],
]);

it('E-42 does not let a catalog manager remove the image of a product', function () {
    $owner = imageOwner('product');
    $model = uploadImage($this, $owner);

    $catalogOnly = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCatalog);
    $this->actingAs($catalogOnly)->deleteJson($owner['url'])->assertForbidden();

    expect($model->refresh()->image_display_path)->not->toBeNull();
});

it('serves the stored variants to a user with products.view with nosniff, a private cache and the stored content type', function (string $kind, string $variant) {
    $owner = imageOwner($kind);
    $model = uploadImage($this, $owner);
    $path = $variant === 'display' ? $model->image_display_path : $model->image_thumb_path;

    $response = $this->actingAs(userWithPermissions(PermissionName::ProductsView))->get("{$owner['url']}/{$variant}");

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/webp');
    expect($response->headers->getCacheControlDirective('max-age'))->toBe('86400')
        ->and($response->headers->hasCacheControlDirective('private'))->toBeTrue()
        ->and($response->streamedContent())->toBe(Storage::disk('local')->get($path));
})->with([
    'product display' => ['product', 'display'],
    'product thumb' => ['product', 'thumb'],
    'value display' => ['value', 'display'],
    'value thumb' => ['value', 'thumb'],
    'location display' => ['location', 'display'],
    'location thumb' => ['location', 'thumb'],
]);

it('E-42 answers 403 when serving an image to a user without products.view', function (string $kind) {
    $owner = imageOwner($kind);
    uploadImage($this, $owner);

    $withoutView = userWithPermissions(PermissionName::ProductsUpdate, PermissionName::ProductsCatalog);

    $this->actingAs($withoutView)->get("{$owner['url']}/display")->assertForbidden();
})->with(['product', 'value', 'location']);

it('answers 404 for an unknown variant and for an owner without image', function (string $kind) {
    $owner = imageOwner($kind);
    $viewer = userWithPermissions(PermissionName::ProductsView);

    $this->actingAs($viewer)->get("{$owner['url']}/display")->assertNotFound();

    uploadImage($this, $owner);

    $this->actingAs($viewer)->get("{$owner['url']}/original")->assertNotFound();
    $this->actingAs($viewer)->get("{$owner['url']}/display")->assertOk();
})->with(['product', 'value', 'location']);

it('exposes the image URLs, never the storage paths, in the product page props', function () {
    $owner = imageOwner('product');
    $model = uploadImage($this, $owner);
    $version = pathinfo($model->image_display_path, PATHINFO_FILENAME);

    $this->actingAs(userWithPermissions(PermissionName::ProductsView))
        ->get("/products/{$model->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.image.display', "/products/{$model->id}/image/display?v={$version}")
            ->where('product.image.thumb', fn ($thumb) => str_starts_with($thumb, "/products/{$model->id}/image/thumb?v="))
            ->where('product.images.has_main', true));
});

it('exposes a null image in the product page props when the product has none', function () {
    $product = Product::factory()->create();

    $this->actingAs(userWithPermissions(PermissionName::ProductsView))
        ->get("/products/{$product->id}")
        ->assertInertia(fn (Assert $page) => $page->where('product.image', null));
});

it('exposes the image URLs of values and locations in the catalog pages', function () {
    $value = imageOwner('value');
    $location = imageOwner('location');
    uploadImage($this, $value);
    uploadImage($this, $location);

    $this->actingAs(imageManager())
        ->get("/catalog/attributes/{$value['model']->catalog_attribute_id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('values.0.image.display', fn ($url) => str_starts_with($url, "/catalog/values/{$value['model']->id}/image/display?v="))
            ->where('values.0.image.thumb', fn ($url) => str_starts_with($url, "/catalog/values/{$value['model']->id}/image/thumb?v=")));

    $this->actingAs(imageManager())
        ->get('/catalog/detail-locations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('locations.0.image.display', fn ($url) => str_starts_with($url, "/catalog/detail-locations/{$location['model']->id}/image/display?v=")));
});
