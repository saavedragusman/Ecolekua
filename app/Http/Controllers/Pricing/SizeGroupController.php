<?php

namespace App\Http\Controllers\Pricing;

use App\Actions\Pricing\PreviewSizeGroups;
use App\Actions\Pricing\SyncSizeGroups;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\SizeGroupsRequest;
use App\Models\Combination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The size groups of a combination (COT-004), saved in two steps (design Decision 10): the preview
 * answers JSON with what the change deletes and a confirmation token, and the PUT saves it. The
 * pricing page ships with the UI, so the PUT goes back to the page that sent it.
 */
class SizeGroupController extends Controller
{
    public function preview(SizeGroupsRequest $request, Combination $combination, PreviewSizeGroups $previewSizeGroups): JsonResponse
    {
        return response()->json($previewSizeGroups->handle($combination, $request->groups()));
    }

    public function update(SizeGroupsRequest $request, Combination $combination, SyncSizeGroups $syncSizeGroups): RedirectResponse
    {
        $syncSizeGroups->handle($combination, $request->groups(), $request->confirmToken(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Los grupos de talla fueron guardados.']);

        return redirect()->back();
    }
}
