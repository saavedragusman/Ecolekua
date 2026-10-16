<?php

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\RecordManualExchangeRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StoreExchangeRateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The manual rate of today (COT-030). The settings page ships with the pricing UI, so the write goes
 * back to the page that sent it.
 */
class ExchangeRateController extends Controller
{
    public function store(StoreExchangeRateRequest $request, RecordManualExchangeRate $recordManualExchangeRate): RedirectResponse
    {
        $recordManualExchangeRate->handle($request->validated('value'), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La tasa del día fue registrada.']);

        return redirect()->back();
    }
}
