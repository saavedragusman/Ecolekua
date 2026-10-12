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

/**
 * Write endpoints of the category catalog (PRD-001). The page (`index`) ships with the UI slice.
 * Authorization is `CatalogPolicy::manage`; categories are never deleted.
 */
class CategoryController extends Controller
{
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
