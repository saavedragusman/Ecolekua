<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * Permission-only abilities (design Decision 4): never role names, no superadmin. There is no
 * self-action guard on `update` (DEC-020); the FND-021 prohibitions live in their Actions.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::UsersView);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->hasPermission(PermissionName::UsersView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::UsersCreate);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasPermission(PermissionName::UsersUpdate);
    }
}
