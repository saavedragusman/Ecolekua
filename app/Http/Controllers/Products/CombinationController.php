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
use App\Support\Products\ProductPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Write endpoints of the combinations of a product (PRD-005, PRD-012, PRD-013). Authorization is
 * `CombinationPolicy`: the form requests check it for the writes with a body and the controller for
 * the status endpoints, always before any Action runs. Routes are nested with scoped bindings, so a
 * combination of another product is a 404. The editor pages (`create`, `edit`) are the forms of
 * these writes; the writes redirect to the product path (`/products/{id}`) and the status endpoints
 * go back to the page that sent them.
 */
class CombinationController extends Controller
{
    /**
     * Registration form (PRD-005). `?from={combination}` duplicates a combination of this product:
     * it pre-fills axes, restrictions, description and included services, never the code (DT-01).
     * Anything else in `from` is ignored. The pre-filled axes can overlap the original; the backend
     * rejects the save and names it.
     */
    public function create(Request $request, Product $product): Response
    {
        Gate::authorize('create', Combination::class);

        $from = $request->query('from');
        $source = is_string($from) && ctype_digit($from)
            ? $product->combinations()->with('catalogCode')->find((int) $from)
            : null;

        $options = ProductPresenter::combinationOptions($product);
        $initial = ProductPresenter::combinationForm($source, withCode: false);
        // A new combination can only include active services: a deactivated one is not carried over.
        $initial['included_customization_ids'] = array_values(array_intersect(
            $initial['included_customization_ids'],
            array_column($options['services'], 'value'),
        ));

        return Inertia::render('products/combinations/Create', [
            'product' => ProductPresenter::combinationSubject($product),
            ...$options,
            'initial' => $initial,
        ]);
    }

    /**
     * Edit form (PRD-005, PRD-012). An inactive combination stays editable.
     */
    public function edit(Product $product, Combination $combination): Response
    {
        Gate::authorize('update', $combination);

        return Inertia::render('products/combinations/Edit', [
            'product' => ProductPresenter::combinationSubject($product),
            ...ProductPresenter::combinationOptions($product, $combination),
            'combination' => ['id' => $combination->id, ...ProductPresenter::combinationForm($combination)],
        ]);
    }

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
