<?php

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\ActivatePriceScheme;
use App\Actions\Pricing\CreatePriceScheme;
use App\Actions\Pricing\DeactivatePriceScheme;
use App\Actions\Pricing\UpdatePriceScheme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StorePriceSchemeRequest;
use App\Http\Requests\Pricing\UpdatePriceSchemeRequest;
use App\Models\PriceScheme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Write endpoints of the price schemes (COT-001). Authorization is `PricingPolicy::manage`
 * (`pricing.manage`): the form requests authorize the writes with a body, the controller authorizes
 * the body-less activate and deactivate. The scheme pages ship with the pricing UI, so every write
 * goes back to the page that sent it.
 */
class PriceSchemeController extends Controller
{
    public function store(StorePriceSchemeRequest $request, CreatePriceScheme $createPriceScheme): RedirectResponse
    {
        $createPriceScheme->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El esquema de precio fue creado.']);

        return redirect()->back();
    }

    public function update(UpdatePriceSchemeRequest $request, PriceScheme $scheme, UpdatePriceScheme $updatePriceScheme): RedirectResponse
    {
        $updatePriceScheme->handle($scheme, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El esquema de precio fue actualizado.']);

        return redirect()->back();
    }

    public function activate(Request $request, PriceScheme $scheme, ActivatePriceScheme $activatePriceScheme): RedirectResponse
    {
        Gate::authorize('manage', $scheme);

        $activatePriceScheme->handle($scheme, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El esquema de precio fue reactivado.']);

        return redirect()->back();
    }

    public function deactivate(Request $request, PriceScheme $scheme, DeactivatePriceScheme $deactivatePriceScheme): RedirectResponse
    {
        Gate::authorize('manage', $scheme);

        $deactivatePriceScheme->handle($scheme, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El esquema de precio fue desactivado.']);

        return redirect()->back();
    }
}
