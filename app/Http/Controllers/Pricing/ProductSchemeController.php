<?php

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\AssignPriceScheme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\AssignPriceSchemeRequest;
use App\Models\PriceScheme;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Scheme assignment (COT-002): the bulk route serves the pricing product list, the single-product
 * route its price page. Both are `pricing.manage` writes authorized by the form request and run the
 * same Action; the pages ship with the pricing UI, so each goes back to the page that sent it.
 */
class ProductSchemeController extends Controller
{
    public function bulk(AssignPriceSchemeRequest $request, AssignPriceScheme $assignPriceScheme): RedirectResponse
    {
        return $this->assign($request, $assignPriceScheme);
    }

    /**
     * The product is bound from the URL (404 when unknown); the form request reads it from the route.
     */
    public function update(AssignPriceSchemeRequest $request, Product $product, AssignPriceScheme $assignPriceScheme): RedirectResponse
    {
        return $this->assign($request, $assignPriceScheme);
    }

    private function assign(AssignPriceSchemeRequest $request, AssignPriceScheme $assignPriceScheme): RedirectResponse
    {
        $assignPriceScheme->handle(
            $request->validated('product_ids'),
            PriceScheme::query()->findOrFail($request->integer('price_scheme_id')),
            (bool) $request->validated('confirm_price_removal', false),
            $request->user(),
        );

        Inertia::flash(['type' => 'success', 'message' => 'El esquema de precio fue asignado.']);

        return redirect()->back();
    }
}
