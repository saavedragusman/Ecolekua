<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Products\ListSelectionOptions;
use App\Actions\Quotations\CalculateQuotation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\CalculateQuotationRequest;
use App\Http\Requests\Quotations\SelectionOptionsRequest;
use App\Support\Quotations\Calculation\CalculationInput;
use Illuminate\Http\JsonResponse;

/**
 * The editor's live calculation and selection steps as JSON (COT-009, PRD-019). Neither writes anything:
 * the screen shows only what these return, it never computes prices (AGENTS.md §7.1).
 */
class CalculationController extends Controller
{
    public function calculate(CalculateQuotationRequest $request, CalculateQuotation $calculateQuotation): JsonResponse
    {
        return new JsonResponse($calculateQuotation->handle(CalculationInput::fromArray($request->validated()))->toArray());
    }

    public function selectionOptions(SelectionOptionsRequest $request, ListSelectionOptions $listSelectionOptions): JsonResponse
    {
        return new JsonResponse($listSelectionOptions->handle($request->validated())->toArray());
    }
}
