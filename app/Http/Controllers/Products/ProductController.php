<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\DeleteProduct;
use App\Actions\Products\UpdateProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Write endpoints of the product catalog (PRD-003, PRD-012). Authorization is `ProductPolicy`, checked
 * by the form requests before any Action runs. The list, detail and form pages arrive in Phases
 * 17-18; until then both writes redirect to the product path (`/products/{id}`), whose named route
 * `products.show` is declared with its page (002 precedent, risk R-3).
 */
class ProductController extends Controller
{
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
     * error flash (422 for JSON). Until the list page exists (Phase 17) it redirects to `/products`.
     */
    public function destroy(Request $request, Product $product, DeleteProduct $deleteProduct): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $deleteProduct->handle($product, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El producto fue eliminado.']);

        return redirect('/products');
    }
}
