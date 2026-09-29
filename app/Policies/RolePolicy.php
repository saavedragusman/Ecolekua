<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Role;
use App\Models\User;

/**
 * Permission-only abilities (design Decision 4): never role names, no superadmin. The rules
 * about the protected role and about roles with users live in the Actions (FND-016).
 */
class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::RolesView);
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->hasPermission(PermissionName::RolesView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::RolesManage);
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->hasPermission(PermissionName::RolesManage);
    }

    public function delete(User $actor, Role $role): bool
    {
        return $actor->hasPermission(PermissionName::RolesManage);
    }
}
