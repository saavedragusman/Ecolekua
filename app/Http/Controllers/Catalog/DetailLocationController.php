<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\ActivateCatalogItem;
use App\Actions\Products\CreateDetailLocation;
use App\Actions\Products\DeactivateCatalogItem;
use App\Actions\Products\UpdateDetailLocation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreDetailLocationRequest;
use App\Http\Requests\Catalog\UpdateDetailLocationRequest;
use App\Models\DetailLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Write endpoints of the detail location catalog (PRD-007). The page (`index`) ships with the UI
 * slice. Authorization is `CatalogPolicy::manage`; locations are never deleted.
 */
class DetailLocationController extends Controller
{
    public function store(StoreDetailLocationRequest $request, CreateDetailLocation $createDetailLocation): RedirectResponse
    {
        $createDetailLocation->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La ubicación de detalle fue creada.']);

        return redirect()->back();
    }

    public function update(UpdateDetailLocationRequest $request, DetailLocation $location, UpdateDetailLocation $updateDetailLocation): RedirectResponse
    {
        $updateDetailLocation->handle($location, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La ubicación de detalle fue actualizada.']);

        return redirect()->back();
    }

    public function activate(Request $request, DetailLocation $location, ActivateCatalogItem $activateCatalogItem): RedirectResponse
    {
        Gate::authorize('manage', $location);

        $activateCatalogItem->handle($location, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La ubicación de detalle fue reactivada.']);

        return redirect()->back();
    }

    public function deactivate(Request $request, DetailLocation $location, DeactivateCatalogItem $deactivateCatalogItem): RedirectResponse
    {
        Gate::authorize('manage', $location);

        $deactivateCatalogItem->handle($location, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La ubicación de detalle fue desactivada.']);

        return redirect()->back();
    }
}
