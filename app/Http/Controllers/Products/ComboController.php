<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\CreateCombo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreComboRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Write endpoints of the combos (PRD-010, PRD-012, PRD-013). Authorization is `ComboPolicy`: the
 * form requests check it for the writes with a body and the controller for the status endpoints,
 * always before any Action runs. The pages arrive in Phase 20; until then the writes redirect to the
 * combo path (`/combos/{id}`), whose named route `combos.show` is declared with its page (002
 * precedent, risk R-3), and the status endpoints go back to the page that sent them.
 */
class ComboController extends Controller
{
    public function store(StoreComboRequest $request, CreateCombo $createCombo): RedirectResponse
    {
        $combo = $createCombo->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El combo fue registrado.']);

        return redirect("/combos/{$combo->id}");
    }
}
