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

    /**
     * Governs both activating and deactivating (design "Routes and authorization").
     */
    public function deactivate(User $actor, User $user): bool
    {
        return $actor->hasPermission(PermissionName::UsersDeactivate);
    }

    /**
     * No self guard: an administrator may reset their own password (DEC-020).
     */
    public function resetPassword(User $actor, User $user): bool
    {
        return $actor->hasPermission(PermissionName::UsersResetPassword);
    }

    /**
     * The self-change guard (FND-021) is enforced in SyncUserRoles, not here (task 6.8).
     */
    public function assignRoles(User $actor, User $user): bool
    {
        return $actor->hasPermission(PermissionName::UsersAssignRoles);
    }
}
