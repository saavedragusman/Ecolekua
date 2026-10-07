<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\ActivateCatalogItem;
use App\Actions\Products\CreateAttributeValue;
use App\Actions\Products\DeactivateCatalogItem;
use App\Actions\Products\MoveCatalogItem;
use App\Actions\Products\UpdateAttributeValue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\MoveCatalogItemRequest;
use App\Http\Requests\Catalog\StoreAttributeValueRequest;
use App\Http\Requests\Catalog\UpdateAttributeValueRequest;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Write endpoints of the values of an attribute (PRD-002). Values are created under their
 * attribute and addressed by their own id afterwards; they are never deleted.
 */
class AttributeValueController extends Controller
{
    public function store(StoreAttributeValueRequest $request, CatalogAttribute $attribute, CreateAttributeValue $createValue): RedirectResponse
    {
        $createValue->handle($attribute, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El valor fue creado.']);

        return redirect()->back();
    }

    public function update(UpdateAttributeValueRequest $request, AttributeValue $value, UpdateAttributeValue $updateValue): RedirectResponse
    {
        $updateValue->handle($value, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El valor fue actualizado.']);

        return redirect()->back();
    }

    public function move(MoveCatalogItemRequest $request, AttributeValue $value, MoveCatalogItem $moveCatalogItem): RedirectResponse
    {
        $moveCatalogItem->handle($value, $request->validated('direction'), $request->user());

        return redirect()->back();
    }

    public function activate(Request $request, AttributeValue $value, ActivateCatalogItem $activateCatalogItem): RedirectResponse
    {
        Gate::authorize('manage', $value);

        $activateCatalogItem->handle($value, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El valor fue reactivado.']);

        return redirect()->back();
    }

    public function deactivate(Request $request, AttributeValue $value, DeactivateCatalogItem $deactivateCatalogItem): RedirectResponse
    {
        Gate::authorize('manage', $value);

        $deactivateCatalogItem->handle($value, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El valor fue desactivado.']);

        return redirect()->back();
    }
}
