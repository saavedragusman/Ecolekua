<?php

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\SyncComboItemPrices;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\SyncComboItemPricesRequest;
use App\Models\Combo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The component prices of a combo (COT-005). The page ships with the pricing UI, so the write goes back
 * to the page that sent it.
 */
class ComboPriceController extends Controller
{
    public function update(SyncComboItemPricesRequest $request, Combo $combo, SyncComboItemPrices $syncComboItemPrices): RedirectResponse
    {
        $syncComboItemPrices->handle($combo, $request->validated('prices'), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Los precios del combo fueron guardados.']);

        return redirect()->back();
    }
}
