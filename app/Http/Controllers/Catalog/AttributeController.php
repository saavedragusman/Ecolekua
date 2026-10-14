<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\ActivateCatalogItem;
use App\Actions\Products\CreateCatalogAttribute;
use App\Actions\Products\DeactivateCatalogItem;
use App\Actions\Products\MoveCatalogItem;
use App\Actions\Products\UpdateCatalogAttribute;
use App\Enums\AttributeSpecialUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\MoveCatalogItemRequest;
use App\Http\Requests\Catalog\StoreAttributeRequest;
use App\Http\Requests\Catalog\UpdateAttributeRequest;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Support\Products\ProductPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pages and write endpoints of the attribute catalog (PRD-002). Authorization is
 * `CatalogPolicy::manage` (`products.catalog`); attributes are never deleted.
 */
class AttributeController extends Controller
{
    /**
     * Every attribute in display order, inactive ones included, with its value count. `options`
     * carries the enum labels for the create and edit form.
     */
    public function index(): Response
    {
        Gate::authorize('manage', CatalogAttribute::class);

        return Inertia::render('catalog/Attributes', [
            'attributes' => CatalogAttribute::query()
                ->withCount('values')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(ProductPresenter::attribute(...))
                ->all(),
            'options' => [
                'presentations' => ProductPresenter::presentationOptions(),
                'special_uses' => ProductPresenter::specialUseOptions(),
            ],
            'can' => ['manage' => Gate::allows('manage', CatalogAttribute::class)],
        ]);
    }

    /**
     * One attribute with its values in order. On the fabric attribute each value also lists its
     * offered colors and the page receives the active palette to choose from (E-46).
     */
    public function show(CatalogAttribute $attribute): Response
    {
        Gate::authorize('manage', $attribute);

        $isFabric = $attribute->special_use === AttributeSpecialUse::Fabric;

        $attribute->loadCount('values');
        $values = $attribute->values()->orderBy('id')->with($isFabric ? 'offeredColors' : [])->get();

        return Inertia::render('catalog/AttributeShow', [
            'attribute' => ProductPresenter::attribute($attribute),
            'values' => $values->map(fn (AttributeValue $value): array => ProductPresenter::value($value, $isFabric))->all(),
            'palette' => $isFabric ? ProductPresenter::palette() : [],
            'can' => ['manage' => Gate::allows('manage', $attribute)],
        ]);
    }

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
