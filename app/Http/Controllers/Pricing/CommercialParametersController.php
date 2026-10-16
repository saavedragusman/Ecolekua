<?php

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\UpdateCommercialParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\UpdateCommercialParametersRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The commercial parameters (COT-006). The settings page ships with the pricing UI, so the write goes
 * back to the page that sent it.
 */
class CommercialParametersController extends Controller
{
    public function update(UpdateCommercialParametersRequest $request, UpdateCommercialParameters $updateCommercialParameters): RedirectResponse
    {
        $updateCommercialParameters->handle(
            $request->validated('advance_percentage'),
            (int) $request->validated('validity_days'),
            $request->user(),
        );

        Inertia::flash(['type' => 'success', 'message' => 'Los parámetros comerciales fueron guardados.']);

        return redirect()->back();
    }
}
