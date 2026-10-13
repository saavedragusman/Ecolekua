<?php

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\CreatePriceScheme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StorePriceSchemeRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Write endpoints of the price schemes (COT-001). Authorization is `PricingPolicy::manage`
 * (`pricing.manage`): the form requests authorize the writes with a body. The scheme pages ship with
 * the pricing UI, so every write goes back to the page that sent it.
 */
class PriceSchemeController extends Controller
{
    public function store(StorePriceSchemeRequest $request, CreatePriceScheme $createPriceScheme): RedirectResponse
    {
        $createPriceScheme->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El esquema de precio fue creado.']);

        return redirect()->back();
    }
}
