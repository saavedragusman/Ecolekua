<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\DeleteProduct;
use App\Actions\Products\UpdateProduct;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Models\Combination;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Products\ProductPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read pages and write endpoints of the product catalog (PRD-003, PRD-012, PRD-015). Authorization is
 * `ProductPolicy`: the form requests check it before any Action runs and the pages call
 * `Gate::authorize`. The form pages arrive in Phase 18.
 */
class ProductController extends Controller
{
    /**
     * Design Decision 20: `?status=active|inactive|all&q=…&category=…&line=…&mode=…`. The default and
     * any unknown status is `active`; a filter value that does not exist is ignored; the counts of
     * the three views use the same `q` and filters, whatever the selected view.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ['active', 'inactive', 'all'], true) ? $status : 'active';
        $term = $request->query('q');
        $term = is_string($term) ? mb_substr(trim($term), 0, 100) : '';
        $category = $request->query('category');
        $category = is_string($category) && ctype_digit($category) && ProductCategory::query()->whereKey((int) $category)->exists()
            ? (int) $category
            : null;
        $line = $request->query('line');
        $line = is_string($line) ? BusinessLine::tryFrom($line) : null;
        $mode = $request->query('mode');
        $mode = is_string($mode) ? SupplyMode::tryFrom($mode) : null;

        $filtered = Product::query()
            ->searchByNameOrCode($term)
            ->when($category !== null, fn (Builder $query) => $query->where('product_category_id', $category))
            ->when($line !== null, fn (Builder $query) => $query->where('business_line', $line?->value))
            ->when($mode !== null, fn (Builder $query) => $query->where('supply_mode', $mode?->value));

        $totals = (clone $filtered)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $active = (int) ($totals[CatalogStatus::Active->value] ?? 0);
        $inactive = (int) ($totals[CatalogStatus::Inactive->value] ?? 0);

        $page = $filtered
            ->when($status !== 'all', fn (Builder $query) => $query->withStatus(CatalogStatus::from($status)))
            ->with('category')
            ->withCount('combinations')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('products/Index', [
            'status' => $status,
            'q' => $term,
            'category' => $category,
            'line' => $line?->value,
            'mode' => $mode?->value,
            'counts' => ['active' => $active, 'inactive' => $inactive, 'all' => $active + $inactive],
            'filters' => ProductPresenter::filterOptions(),
            'products' => $page->through(fn (Product $product): array => ProductPresenter::listRow($product)),
        ]);
    }

    public function show(Product $product): Response
    {
        Gate::authorize('view', $product);

        return Inertia::render('products/Show', [
            'product' => ProductPresenter::detail($product),
            'can' => [
                'update' => Gate::allows('update', $product),
                'deactivate' => Gate::allows('deactivate', $product),
                'delete' => Gate::allows('delete', $product),
                'createCombination' => Gate::allows('create', Combination::class),
            ],
        ]);
    }

    public function store(StoreProductRequest $request, CreateProduct $createProduct): RedirectResponse
    {
        $product = $createProduct->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El producto fue registrado.']);

        return redirect("/products/{$product->id}");
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $updateProduct): RedirectResponse
    {
        $product = $updateProduct->handle($product, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El producto fue actualizado.']);

        return redirect("/products/{$product->id}");
    }

    /**
     * Restricted delete (PRD-014): a rejection is a `BusinessRuleViolation` rendered back with an
     * error flash (422 for JSON) and the product page offers "Desactivar".
     */
    public function destroy(Request $request, Product $product, DeleteProduct $deleteProduct): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $deleteProduct->handle($product, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El producto fue eliminado.']);

        return redirect('/products');
    }
}
