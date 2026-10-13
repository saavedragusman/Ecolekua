<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\RemoveProductImage;
use App\Actions\Products\SetProductImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductImageRequest;
use App\Models\Product;
use App\Support\Products\Assets\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Main image of a product (PRD-020, design Decision 18): upload and removal need `products.update`
 * (E-42); serving the stored variant needs `products.view`. Files live on the private disk and only
 * leave it through `show`.
 */
class ProductAssetController extends Controller
{
    public function store(StoreProductImageRequest $request, Product $product, SetProductImage $setImage): RedirectResponse
    {
        $setImage->handle($product, $request->validated('image'), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La imagen del producto fue guardada.']);

        return redirect()->back();
    }

    public function destroy(Request $request, Product $product, RemoveProductImage $removeImage): RedirectResponse
    {
        Gate::authorize('update', $product);

        $removeImage->handle($product, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La imagen del producto fue quitada.']);

        return redirect()->back();
    }

    public function show(Product $product, string $variant, ImageStore $images): StreamedResponse
    {
        Gate::authorize('view', $product);

        return $images->response($product, $variant);
    }
}
