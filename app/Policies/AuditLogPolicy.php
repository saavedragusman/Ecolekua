<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * Audit records are immutable (FND-024): the only ability is reading them.
 */
class AuditLogPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AuditView);
    }
}
