<?php

use App\Models\CatalogCode;
use App\Models\Combo;
use App\Models\Product;
use App\Support\Products\Selection\CatalogSnapshotLoader;
use Illuminate\Support\Facades\DB;

// Design Decision 16 (D1, DT-02): a calculation resolves every row of every line, so it uses a loader that
// remembers what it already read. The default loader of 003 keeps reading every time.

/**
 * The queries a callback issues.
 *
 * @return list<array{query: string, bindings: array<int, mixed>}>
 */
function loaderQueries(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return array_map(fn (array $query) => ['query' => $query['query'], 'bindings' => $query['bindings']], $queries);
}

it('DT-02 memoized devuelve una instancia nueva y no cambia la del 003', function () {
    $loader = new CatalogSnapshotLoader;
    $memoized = $loader->memoized();

    expect($memoized)->toBeInstanceOf(CatalogSnapshotLoader::class)
        ->not->toBe($loader)
        ->and($loader->memoized())->not->toBe($memoized);
});

it('DT-02 el loader memoizado devuelve los mismos snapshots que el del 003', function () {
    $products = Product::factory()->count(2)->create();
    $ids = $products->pluck('id')->all();

    expect((new CatalogSnapshotLoader)->memoized()->forProducts($ids))->toEqual((new CatalogSnapshotLoader)->forProducts($ids));
});

it('DT-02 el loader memoizado no vuelve a consultar un producto ya leído y solo busca los que faltan', function () {
    [$first, $second] = Product::factory()->count(2)->create()->all();
    $loader = (new CatalogSnapshotLoader)->memoized();

    $initial = loaderQueries(fn () => $loader->forProducts([$first->id]));
    $repeated = loaderQueries(fn () => $loader->forProduct($first->id));
    $extended = loaderQueries(fn () => $loader->forProducts([$first->id, $second->id]));

    $productQuery = collect($extended)->first(fn (array $query) => str_contains($query['query'], 'from `products`'));

    expect($initial)->not->toBeEmpty()
        ->and($repeated)->toBe([])
        ->and(count($extended))->toBe(count($initial))
        ->and($productQuery['bindings'] ?? null)->toBe([$second->id])
        ->and(array_keys($loader->forProducts([$second->id, $first->id])))->toBe([$second->id, $first->id]);
});

it('DT-02 el loader memoizado recuerda también los productos que no existen', function () {
    $loader = (new CatalogSnapshotLoader)->memoized();

    expect($loader->forProducts([999_999]))->toBe([])
        ->and(loaderQueries(fn () => $loader->forProducts([999_999])))->toBe([]);
});

it('DT-02 el loader memoizado recuerda los combos y la paleta', function () {
    $combo = Combo::factory()->create();
    CatalogCode::factory()->forCombo($combo)->create();
    $loader = (new CatalogSnapshotLoader)->memoized();

    $combo1 = $loader->forCombo($combo->id);
    $palette = $loader->palette();

    expect($combo1?->id)->toBe($combo->id)
        ->and(loaderQueries(function () use ($loader, $combo) {
            $loader->forCombo($combo->id);
            $loader->palette();
        }))->toBe([])
        ->and($loader->palette())->toBe($palette);
});

it('DT-02 el loader del 003 sigue consultando en cada llamada', function () {
    $product = Product::factory()->create();
    $loader = new CatalogSnapshotLoader;

    $first = loaderQueries(fn () => $loader->forProducts([$product->id]));
    $second = loaderQueries(fn () => $loader->forProducts([$product->id]));

    expect($second)->not->toBeEmpty()
        ->and(count($second))->toBe(count($first));
});
