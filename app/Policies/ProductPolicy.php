<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Product;
use App\Models\User;

/**
 * Permission-only abilities for products (PRD-016, design Decision 8): one permission each, never
 * role names, no superadmin. Combinations and combos get their own policies in later phases.
 */
class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::ProductsView);
    }

    public function view(User $actor, Product $product): bool
    {
        return $actor->hasPermission(PermissionName::ProductsView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::ProductsCreate);
    }

    /**
     * Includes structure, details, customizations, stock parameters, main image and templates.
     */
    public function update(User $actor, Product $product): bool
    {
        return $actor->hasPermission(PermissionName::ProductsUpdate);
    }

    /**
     * Governs both activating and deactivating (spec section 4 note, as `customers.deactivate`).
     */
    public function deactivate(User $actor, Product $product): bool
    {
        return $actor->hasPermission(PermissionName::ProductsDeactivate);
    }

    public function delete(User $actor, Product $product): bool
    {
        return $actor->hasPermission(PermissionName::ProductsDelete);
    }
}
