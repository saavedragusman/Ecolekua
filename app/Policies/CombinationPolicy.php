<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Combination;
use App\Models\User;

/**
 * Permission-only abilities for combinations (PRD-016, design Decision 8): they reuse the product
 * permissions, never role names. Viewing a combination is part of viewing its product, so it has no
 * ability of its own.
 */
class CombinationPolicy
{
    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::ProductsCreate);
    }

    public function update(User $actor, Combination $combination): bool
    {
        return $actor->hasPermission(PermissionName::ProductsUpdate);
    }

    /**
     * Governs both activating and deactivating, as for products.
     */
    public function deactivate(User $actor, Combination $combination): bool
    {
        return $actor->hasPermission(PermissionName::ProductsDeactivate);
    }

    public function delete(User $actor, Combination $combination): bool
    {
        return $actor->hasPermission(PermissionName::ProductsDelete);
    }
}
