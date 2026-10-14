<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\ActivateCatalogItem;
use App\Actions\Products\CreateCategory;
use App\Actions\Products\DeactivateCatalogItem;
use App\Actions\Products\MoveCatalogItem;
use App\Actions\Products\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\MoveCatalogItemRequest;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page and write endpoints of the category catalog (PRD-001). Authorization is
 * `CatalogPolicy::manage` (`products.catalog`; `products.view` alone does not open the page, spec
 * §8); categories are never deleted.
 */
class CategoryController extends Controller
{
    /**
     * Every category in display order, inactive ones included. `can.manage` mirrors the policy so the
     * page can hide controls; the writes authorize again.
     */
    public function index(): Response
    {
        Gate::authorize('manage', ProductCategory::class);

        return Inertia::render('catalog/Categories', [
            'categories' => ProductCategory::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ProductCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'status' => $category->status->value,
                    'status_label' => $category->status->label(),
                    'sort_order' => $category->sort_order,
                ])
                ->all(),
            'can' => ['manage' => Gate::allows('manage', ProductCategory::class)],
        ]);
    }

    public function store(StoreCategoryRequest $request, CreateCategory $createCategory): RedirectResponse
    {
        $createCategory->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La categoría fue creada.']);

        return redirect()->back();
    }

    public function update(UpdateCategoryRequest $request, ProductCategory $category, UpdateCategory $updateCategory): RedirectResponse
    {
        $updateCategory->handle($category, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La categoría fue actualizada.']);

        return redirect()->back();
    }

    public function move(MoveCatalogItemRequest $request, ProductCategory $category, MoveCatalogItem $moveCatalogItem): RedirectResponse
    {
        $moveCatalogItem->handle($category, $request->validated('direction'), $request->user());

        return redirect()->back();
    }

    public function activate(Request $request, ProductCategory $category, ActivateCatalogItem $activateCatalogItem): RedirectResponse
    {
        Gate::authorize('manage', $category);

        $activateCatalogItem->handle($category, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La categoría fue reactivada.']);

        return redirect()->back();
    }

    public function deactivate(Request $request, ProductCategory $category, DeactivateCatalogItem $deactivateCatalogItem): RedirectResponse
    {
        Gate::authorize('manage', $category);

        $deactivateCatalogItem->handle($category, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'La categoría fue desactivada.']);

        return redirect()->back();
    }
}
