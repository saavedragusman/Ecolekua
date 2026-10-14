<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\RemoveCatalogImage;
use App\Actions\Products\SetCatalogImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCatalogImageRequest;
use App\Models\AttributeValue;
use App\Models\DetailLocation;
use App\Support\Products\Assets\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images of attribute values and detail locations (PRD-020, design Decision 18): upload and removal
 * are `CatalogPolicy::manage` (`products.catalog`, E-42); serving a stored variant is
 * `CatalogPolicy::viewAsset` (`products.view`).
 */
class CatalogAssetController extends Controller
{
    public function storeValue(StoreCatalogImageRequest $request, AttributeValue $value, SetCatalogImage $setImage): RedirectResponse
    {
        $setImage->handle($value, $request->validated('image'), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La imagen del valor fue guardada.']);

        return redirect()->back();
    }

    public function destroyValue(Request $request, AttributeValue $value, RemoveCatalogImage $removeImage): RedirectResponse
    {
        Gate::authorize('manage', $value);

        $removeImage->handle($value, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La imagen del valor fue quitada.']);

        return redirect()->back();
    }

    public function showValue(AttributeValue $value, string $variant, ImageStore $images): StreamedResponse
    {
        Gate::authorize('viewAsset', $value);

        return $images->response($value, $variant);
    }

    public function storeLocation(StoreCatalogImageRequest $request, DetailLocation $location, SetCatalogImage $setImage): RedirectResponse
    {
        $setImage->handle($location, $request->validated('image'), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La imagen de la ubicación fue guardada.']);

        return redirect()->back();
    }

    public function destroyLocation(Request $request, DetailLocation $location, RemoveCatalogImage $removeImage): RedirectResponse
    {
        Gate::authorize('manage', $location);

        $removeImage->handle($location, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La imagen de la ubicación fue quitada.']);

        return redirect()->back();
    }

    public function showLocation(DetailLocation $location, string $variant, ImageStore $images): StreamedResponse
    {
        Gate::authorize('viewAsset', $location);

        return $images->response($location, $variant);
    }
}
