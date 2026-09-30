<?php

namespace App\Actions\Authorization;

use App\Enums\PermissionName;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Last-administrator protection (FND-020, E-25, design Decision 12): serialize, apply,
 * re-check, roll back. Call `lock()` as the first statement of the transaction of every Action
 * that can reduce administration, apply the change, then call `assert()` still inside the
 * transaction so a rejection rolls everything back.
 */
class EnsureAdministrationIsPreserved
{
    /**
     * Serializes concurrent operations that reduce administration by locking the two
     * administration permission rows (`SELECT ... FOR UPDATE`).
     */
    public function lock(): void
    {
        Permission::query()
            ->whereIn('name', $this->administrationPermissions())
            ->lockForUpdate()
            ->get();
    }

    /**
     * @throws BusinessRuleViolation when no active user holds both permissions through their roles
     */
    public function assert(): void
    {
        $administrators = User::query()->where('is_active', true);

        foreach ($this->administrationPermissions() as $permission) {
            $administrators->whereHas('roles.permissions', fn (Builder $query) => $query->where('permissions.name', $permission));
        }

        if (! $administrators->exists()) {
            // User-facing copy uses the catalog descriptions, never the technical permission names.
            throw new BusinessRuleViolation(sprintf(
                'La operación dejaría al sistema sin ningún usuario activo con los permisos «%s» y «%s».',
                PermissionName::UsersAssignRoles->description(),
                PermissionName::RolesManage->description(),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function administrationPermissions(): array
    {
        return [PermissionName::UsersAssignRoles->value, PermissionName::RolesManage->value];
    }
}
