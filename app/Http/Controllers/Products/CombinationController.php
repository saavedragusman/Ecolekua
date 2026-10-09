<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\ActivateCombination;
use App\Actions\Products\CreateCombination;
use App\Actions\Products\DeactivateCombination;
use App\Actions\Products\DeleteCombination;
use App\Actions\Products\UpdateCombination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreCombinationRequest;
use App\Http\Requests\Products\UpdateCombinationRequest;
use App\Models\Combination;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Write endpoints of the combinations of a product (PRD-005, PRD-012, PRD-013). Authorization is
 * `CombinationPolicy`: the form requests check it for the writes with a body and the controller for
 * the status endpoints, always before any Action runs. Routes are nested with scoped bindings, so a
 * combination of another product is a 404. The editor pages arrive in Phase 19; until then the
 * writes redirect to the product path (`/products/{id}`), whose named route `products.show` is
 * declared with its page (002 precedent, risk R-3), and the status endpoints go back to the page
 * that sent them.
 */
class CombinationController extends Controller
{
    public function store(StoreCombinationRequest $request, Product $product, CreateCombination $createCombination): RedirectResponse
    {
        $createCombination->handle($product, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La combinación fue registrada.']);

        return redirect("/products/{$product->id}");
    }

    public function update(UpdateCombinationRequest $request, Product $product, Combination $combination, UpdateCombination $updateCombination): RedirectResponse
    {
        $updateCombination->handle($combination, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La combinación fue actualizada.']);

        return redirect("/products/{$product->id}");
    }

    public function activate(Request $request, Product $product, Combination $combination, ActivateCombination $activateCombination): RedirectResponse
    {
        Gate::authorize('deactivate', $combination);

        $activateCombination->handle($combination, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La combinación fue reactivada.']);

        return redirect()->back();
    }

    public function deactivate(Request $request, Product $product, Combination $combination, DeactivateCombination $deactivateCombination): RedirectResponse
    {
        Gate::authorize('deactivate', $combination);

        $deactivateCombination->handle($combination, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La combinación fue desactivada.']);

        return redirect()->back();
    }

    /**
     * Restricted delete (PRD-014, E-61): a rejection is a `BusinessRuleViolation` rendered back with
     * an error flash (422 for JSON). Success goes to the product path, like the other writes.
     */
    public function destroy(Request $request, Product $product, Combination $combination, DeleteCombination $deleteCombination): RedirectResponse
    {
        Gate::authorize('delete', $combination);

        $deleteCombination->handle($combination, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La combinación fue eliminada.']);

        return redirect("/products/{$product->id}");
    }
}
