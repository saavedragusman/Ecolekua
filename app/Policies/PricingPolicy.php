<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * Permission-only abilities for pricing (COT-022, design Decision 6): one policy shared by price
 * schemes, combination prices, combo item prices and the commercial parameters (registered in
 * AppServiceProvider; exchange rates and price imports join in later phases). Abilities answer only
 * the permission: state rules are business rules of the Actions, role names are never consulted and
 * there is no superadmin. Pricing screens do not require `products.view` (DEC-COT-52).
 *
 * Both abilities are called with a model instance or with the model class (screens and creation),
 * so they take an untyped subject that they ignore.
 */
class PricingPolicy
{
    /**
     * Screens, export and import history.
     */
    public function view(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::PricingView);
    }

    /**
     * Schemes, assignment, prices, groups, combo prices, parameters, manual rate, upload, apply and discard.
     */
    public function manage(User $actor, mixed $subject = null): bool
    {
        return $actor->hasPermission(PermissionName::PricingManage);
    }
}
