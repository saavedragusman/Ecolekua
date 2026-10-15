<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Quotations\CreateQuotation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\StoreQuotationRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Quotations of the ERP (COT-013). The editor page ships with the quotation UI, so a new draft redirects to
 * the path where it will be edited.
 */
class QuotationController extends Controller
{
    /** Notice when the customer's advisor could not be taken (DEC-COT-63, E-74). */
    public const ADVISOR_UNAVAILABLE_NOTICE = 'asesora del cliente no disponible';

    public function store(StoreQuotationRequest $request, CreateQuotation $createQuotation): RedirectResponse
    {
        $created = $createQuotation->handle($request->validated(), $request->user());

        Inertia::flash(array_filter([
            'type' => 'success',
            'message' => "Se creó la cotización {$created->quotation->displayNumber()}.",
            'notice' => $created->customerAdvisorUnavailable ? self::ADVISOR_UNAVAILABLE_NOTICE : null,
        ]));

        return redirect("/quotations/{$created->quotation->id}/edit");
    }
}
