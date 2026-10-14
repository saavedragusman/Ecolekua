<?php

namespace App\Http\Controllers\Products;

use App\Actions\Products\ActivateCombo;
use App\Actions\Products\CreateCombo;
use App\Actions\Products\DeactivateCombo;
use App\Actions\Products\DeleteCombo;
use App\Actions\Products\UpdateCombo;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreComboRequest;
use App\Http\Requests\Products\UpdateComboRequest;
use App\Models\Combo;
use App\Support\Products\ProductPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Write endpoints of the combos (PRD-010, PRD-012, PRD-013). Authorization is `ComboPolicy`: the
 * form requests check it for the writes with a body and the controller for the status endpoints,
 * always before any Action runs; the read pages call `Gate::authorize` (PRD-015, PRD-016). The writes
 * redirect to the combo page and the status endpoints go back to the page that sent them.
 */
class ComboController extends Controller
{
    /**
     * Design Decision 20: `?status=active|inactive|all&q=…`. The default and any unknown status is
     * `active`; the counts of the three views use the same `q`, whatever the selected view.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Combo::class);

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ['active', 'inactive', 'all'], true) ? $status : 'active';
        $term = $request->query('q');
        $term = is_string($term) ? mb_substr(trim($term), 0, 100) : '';

        $filtered = Combo::query()->searchByNameOrCode($term);

        $totals = (clone $filtered)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $active = (int) ($totals[CatalogStatus::Active->value] ?? 0);
        $inactive = (int) ($totals[CatalogStatus::Inactive->value] ?? 0);

        $page = $filtered
            ->when($status !== 'all', fn (Builder $query) => $query->withStatus(CatalogStatus::from($status)))
            ->with('catalogCode')
            ->withCount('components')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('combos/Index', [
            'status' => $status,
            'q' => $term,
            'counts' => ['active' => $active, 'inactive' => $inactive, 'all' => $active + $inactive],
            'can' => ['create' => Gate::allows('create', Combo::class)],
            'combos' => $page->through(fn (Combo $combo): array => ProductPresenter::comboRow($combo)),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Combo::class);

        return Inertia::render('combos/Create', [
            'products' => ProductPresenter::comboProducts(),
            'initial' => ProductPresenter::comboForm(null),
        ]);
    }

    public function show(Combo $combo): Response
    {
        Gate::authorize('view', $combo);

        return Inertia::render('combos/Show', [
            'combo' => ProductPresenter::comboDetail($combo),
            'can' => [
                'update' => Gate::allows('update', $combo),
                'deactivate' => Gate::allows('deactivate', $combo),
                'delete' => Gate::allows('delete', $combo),
            ],
        ]);
    }

    /**
     * An inactive combo stays editable, like a product (PRD-012).
     */
    public function edit(Combo $combo): Response
    {
        Gate::authorize('update', $combo);

        return Inertia::render('combos/Edit', [
            'combo' => ProductPresenter::comboSubject($combo),
            'products' => ProductPresenter::comboProducts($combo),
            'initial' => ProductPresenter::comboForm($combo),
        ]);
    }

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

    /**
     * Restricted delete (PRD-014): a rejection is a `BusinessRuleViolation` rendered back with an
     * error flash (422 for JSON). On success it goes back to the list.
     */
    public function destroy(Request $request, Combo $combo, DeleteCombo $deleteCombo): RedirectResponse
    {
        Gate::authorize('delete', $combo);

        $deleteCombo->handle($combo, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El combo fue eliminado.']);

        return redirect('/combos');
    }
}
