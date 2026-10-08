<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\SyncProductAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SyncProductAttributesRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Structure endpoint of a product (PRD-004): `PUT /products/{product}/attributes` replaces the
 * attributes, roles, axis order and allowed values in one request. Authorization is
 * `ProductPolicy::update`, checked by the form request. The structure page arrives in Phase 14;
 * the endpoint returns to the page that sent it.
 */
class ProductStructureController extends Controller
{
    public function update(SyncProductAttributesRequest $request, Product $product, SyncProductAttributes $syncProductAttributes): RedirectResponse
    {
        /** @var list<array{attribute_id: int|string, role: string, allowed_value_ids?: list<int|string>|null}> $entries */
        $entries = $request->validated('attributes');

        $syncProductAttributes->handle($product, $entries, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La estructura del producto fue actualizada.']);

        return redirect()->back();
    }
}
