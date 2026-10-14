<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\ActivateProduct;
use App\Actions\Products\DeactivateProduct;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Activation and deactivation of a product (PRD-013). Both are governed by `products.deactivate`
 * and authorize before the Action runs (body-less endpoints, as `CustomerStatusController`). They
 * go back to the page that sent them (list or detail) because those pages arrive in Phase 17.
 */
class ProductStatusController extends Controller
{
    public function activate(Request $request, Product $product, ActivateProduct $activateProduct): RedirectResponse
    {
        Gate::authorize('deactivate', $product);

        $activateProduct->handle($product, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El producto fue reactivado.']);

        return redirect()->back();
    }

    public function deactivate(Request $request, Product $product, DeactivateProduct $deactivateProduct): RedirectResponse
    {
        Gate::authorize('deactivate', $product);

        $deactivateProduct->handle($product, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El producto fue desactivado.']);

        return redirect()->back();
    }
}
