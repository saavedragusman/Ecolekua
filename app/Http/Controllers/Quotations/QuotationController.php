<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\UpdateQuotationDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\StoreQuotationRequest;
use App\Http\Requests\Quotations\UpdateQuotationRequest;
use App\Models\Quotation;
use App\Support\Quotations\RecipientSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Quotations of the ERP (COT-013). The editor page ships with the quotation UI, so a new draft redirects to
 * the path where it will be edited, and a saved draft goes back to the page that sent it.
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

    public function update(UpdateQuotationRequest $request, Quotation $quotation, UpdateQuotationDraft $updateQuotationDraft): RedirectResponse
    {
        $updateQuotationDraft->handle($quotation, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Se guardó el borrador.']);

        return redirect()->back();
    }

    /**
     * Active customers for the recipient search of the editor (COT-013).
     */
    public function recipients(Request $request): JsonResponse
    {
        Gate::authorize('create', Quotation::class);

        return new JsonResponse(['data' => RecipientSearch::find((string) $request->query('q', ''))]);
    }
}
