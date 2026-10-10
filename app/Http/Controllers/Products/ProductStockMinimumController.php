<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\SyncStockMinimumOverrides;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SyncStockMinimumsRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Own minimum stock per article (PRD-009): `PUT /products/{product}/stock-minimums` replaces the
 * whole set. Authorization is `ProductPolicy::update`, checked by the form request. The editor lives
 * in the product edit page; the endpoint returns to the page that sent it.
 */
class ProductStockMinimumController extends Controller
{
    public function update(SyncStockMinimumsRequest $request, Product $product, SyncStockMinimumOverrides $syncStockMinimumOverrides): RedirectResponse
    {
        /** @var list<array{combination_id: int|string, size_value_id?: int|string|null, minimum: int|string}> $rows */
        $rows = $request->validated('overrides');

        $syncStockMinimumOverrides->handle($product, $rows, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El stock mínimo por artículo fue actualizado.']);

        return redirect()->back();
    }
}
