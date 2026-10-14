<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\RemoveProductImage;
use App\Actions\Products\RemoveProductTemplate;
use App\Actions\Products\SetProductImage;
use App\Actions\Products\UploadProductTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductImageRequest;
use App\Http\Requests\Products\StoreProductTemplateRequest;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Support\Products\Assets\ImageStore;
use App\Support\Products\Assets\TemplateStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Main image and SVG templates of a product (PRD-020, design Decision 18): upload and removal need
 * `products.update` (E-42); serving a stored file needs `products.view`. Files live on the private
 * disk and only leave it through `show` and `showTemplate`.
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

    public function storeTemplate(StoreProductTemplateRequest $request, Product $product, UploadProductTemplate $upload): RedirectResponse
    {
        $variant = $request->validated('variant_value_id');

        $upload->handle($product, $request->validated('template'), $variant === null ? null : (int) $variant, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La plantilla fue guardada.']);

        return redirect()->back();
    }

    public function destroyTemplate(Request $request, Product $product, ProductTemplate $template, RemoveProductTemplate $remove): RedirectResponse
    {
        Gate::authorize('update', $product);

        $remove->handle($product, $template, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La plantilla fue quitada.']);

        return redirect()->back();
    }

    public function showTemplate(Product $product, ProductTemplate $template, TemplateStore $templates): StreamedResponse
    {
        Gate::authorize('view', $product);

        return $templates->response($template);
    }
}
