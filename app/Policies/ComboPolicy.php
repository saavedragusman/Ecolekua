<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Combo;
use App\Models\User;

/**
 * Permission-only abilities for combos (PRD-016, design Decision 8): they reuse the product
 * permissions, never role names, with the same mapping as `ProductPolicy`.
 */
class ComboPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::ProductsView);
    }

    public function view(User $actor, Combo $combo): bool
    {
        return $actor->hasPermission(PermissionName::ProductsView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::ProductsCreate);
    }

    public function update(User $actor, Combo $combo): bool
    {
        return $actor->hasPermission(PermissionName::ProductsUpdate);
    }

    /**
     * Governs both activating and deactivating, as for products.
     */
    public function deactivate(User $actor, Combo $combo): bool
    {
        return $actor->hasPermission(PermissionName::ProductsDeactivate);
    }

    public function delete(User $actor, Combo $combo): bool
    {
        return $actor->hasPermission(PermissionName::ProductsDelete);
    }
}
