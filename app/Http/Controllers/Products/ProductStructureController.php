<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\SyncProductAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SyncProductAttributesRequest;
use App\Models\Product;
use App\Support\Products\ProductPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Structure endpoint of a product (PRD-004): `PUT /products/{product}/attributes` replaces the
 * attributes, roles, axis order and allowed values in one request. Authorization is
 * `ProductPolicy::update`, checked by the form request. The endpoint returns to the page that
 * sent it, which is `GET /products/{product}/structure`.
 */
class ProductStructureController extends Controller
{
    /**
     * Structure editor (PRD-004). An inactive product stays editable. The page receives the current
     * structure ready to be sent back, the attributes it can declare and whether combinations
     * exist (DEC-PRD-41); the backend still decides what a save may change.
     */
    public function edit(Product $product): Response
    {
        Gate::authorize('update', $product);

        return Inertia::render('products/Structure', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $product->status->value,
                'status_label' => $product->status->label(),
                'has_combinations' => $product->combinations()->exists(),
            ],
            'structure' => ProductPresenter::structureEntries($product),
            'catalog' => ProductPresenter::structureCatalog($product),
        ]);
    }

    public function update(SyncProductAttributesRequest $request, Product $product, SyncProductAttributes $syncProductAttributes): RedirectResponse
    {
        /** @var list<array{attribute_id: int|string, role: string, allowed_value_ids?: list<int|string>|null}> $entries */
        $entries = $request->validated('attributes');

        $syncProductAttributes->handle($product, $entries, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La estructura del producto fue actualizada.']);

        return redirect()->back();
    }
}
