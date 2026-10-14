<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\SyncFabricOfferedColors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SyncOfferedColorsRequest;
use App\Models\AttributeValue;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Colors Ecolekua offers in each fabric value (PRD-002, DEC-PRD-32, E-46).
 */
class FabricOfferedColorController extends Controller
{
    public function update(SyncOfferedColorsRequest $request, AttributeValue $value, SyncFabricOfferedColors $syncColors): RedirectResponse
    {
        /** @var list<int> $colorIds */
        $colorIds = $request->validated('color_ids');

        $syncColors->handle($value, $colorIds, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Los colores ofrecidos de la tela fueron actualizados.']);

        return redirect()->back();
    }
}
