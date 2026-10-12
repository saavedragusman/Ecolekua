<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\ActivateCatalogItem;
use App\Actions\Products\CreateCatalogAttribute;
use App\Actions\Products\DeactivateCatalogItem;
use App\Actions\Products\MoveCatalogItem;
use App\Actions\Products\UpdateCatalogAttribute;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\MoveCatalogItemRequest;
use App\Http\Requests\Catalog\StoreAttributeRequest;
use App\Http\Requests\Catalog\UpdateAttributeRequest;
use App\Models\CatalogAttribute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Write endpoints of the attribute catalog (PRD-002). The pages ship with the UI slice.
 * Authorization is `CatalogPolicy::manage`; attributes are never deleted.
 */
class AttributeController extends Controller
{
    public function store(StoreAttributeRequest $request, CreateCatalogAttribute $createAttribute): RedirectResponse
    {
        $createAttribute->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El atributo fue creado.']);

        return redirect()->back();
    }

    public function update(UpdateAttributeRequest $request, CatalogAttribute $attribute, UpdateCatalogAttribute $updateAttribute): RedirectResponse
    {
        $updateAttribute->handle($attribute, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El atributo fue actualizado.']);

        return redirect()->back();
    }

    public function move(MoveCatalogItemRequest $request, CatalogAttribute $attribute, MoveCatalogItem $moveCatalogItem): RedirectResponse
    {
        $moveCatalogItem->handle($attribute, $request->validated('direction'), $request->user());

        return redirect()->back();
    }

    public function activate(Request $request, CatalogAttribute $attribute, ActivateCatalogItem $activateCatalogItem): RedirectResponse
    {
        Gate::authorize('manage', $attribute);

        $activateCatalogItem->handle($attribute, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El atributo fue reactivado.']);

        return redirect()->back();
    }

    public function deactivate(Request $request, CatalogAttribute $attribute, DeactivateCatalogItem $deactivateCatalogItem): RedirectResponse
    {
        Gate::authorize('manage', $attribute);

        $deactivateCatalogItem->handle($attribute, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El atributo fue desactivado.']);

        return redirect()->back();
    }
}
