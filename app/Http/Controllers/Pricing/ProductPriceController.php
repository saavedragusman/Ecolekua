<?php

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\SyncCombinationPrices;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\SyncCombinationPricesRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The price table of a product (COT-003). The page ships with the pricing UI, so the write goes back
 * to the page that sent it.
 */
class ProductPriceController extends Controller
{
    public function update(SyncCombinationPricesRequest $request, Product $product, SyncCombinationPrices $syncCombinationPrices): RedirectResponse
    {
        $syncCombinationPrices->handle($product, $request->validated('cells'), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Los precios fueron guardados.']);

        return redirect()->back();
    }
}
