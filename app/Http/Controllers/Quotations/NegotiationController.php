<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Quotations\SetNegotiatedPrice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\NegotiatedPriceRequest;
use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Negotiation of a draft quotation (COT-012): the negotiated price of a line that requires an advisor.
 */
class NegotiationController extends Controller
{
    public function price(NegotiatedPriceRequest $request, Quotation $quotation, QuotationLine $line, SetNegotiatedPrice $setNegotiatedPrice): RedirectResponse
    {
        $setNegotiatedPrice->handle($line, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => "Se registró el precio negociado de la línea {$line->position}."]);

        return redirect()->back();
    }
}
