<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;

/**
 * Permission-only abilities (design Decision 8, CLI-015): one permission each, never role names,
 * no superadmin. `customers.portfolio` is not an ability: it only makes a user an eligible advisor
 * (User::isEligibleAdvisor()).
 */
class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::CustomersView);
    }

    public function view(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::CustomersCreate);
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersUpdate);
    }

    /**
     * Governs both activating and deactivating (spec section 4 note, as `users.deactivate`).
     */
    public function deactivate(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersDeactivate);
    }

    public function delete(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersDelete);
    }

    public function assign(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersAssign);
    }
}
