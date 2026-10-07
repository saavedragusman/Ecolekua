<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * Permission-only abilities for the catalog (PRD-016, design Decision 8): one policy shared by
 * categories, attributes, attribute values and detail locations (registered in AppServiceProvider).
 * Abilities never look at role names and there is no superadmin.
 *
 * `manage` is called with a model instance or with the model class (screens and creation), so it
 * takes an untyped subject that it ignores.
 */
class CatalogPolicy
{
    /**
     * Screens, create, edit, order, status, offered colors, images and layers.
     */
    public function manage(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::ProductsCatalog);
    }

    /**
     * Serving a value or location image.
     */
    public function viewAsset(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::ProductsView);
    }
}
