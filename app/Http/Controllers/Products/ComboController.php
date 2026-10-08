<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\ActivateCombo;
use App\Actions\Products\CreateCombo;
use App\Actions\Products\DeactivateCombo;
use App\Actions\Products\UpdateCombo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreComboRequest;
use App\Http\Requests\Products\UpdateComboRequest;
use App\Models\Combo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    public function update(UpdateComboRequest $request, Combo $combo, UpdateCombo $updateCombo): RedirectResponse
    {
        $updateCombo->handle($combo, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El combo fue actualizado.']);

        return redirect("/combos/{$combo->id}");
    }

    public function activate(Request $request, Combo $combo, ActivateCombo $activateCombo): RedirectResponse
    {
        Gate::authorize('deactivate', $combo);

        $activateCombo->handle($combo, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El combo fue reactivado.']);

        return redirect()->back();
    }

    public function deactivate(Request $request, Combo $combo, DeactivateCombo $deactivateCombo): RedirectResponse
    {
        Gate::authorize('deactivate', $combo);

        $deactivateCombo->handle($combo, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El combo fue desactivado.']);

        return redirect()->back();
    }
}
