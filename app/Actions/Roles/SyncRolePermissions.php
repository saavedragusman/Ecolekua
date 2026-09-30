<?php

namespace App\Actions\Roles;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Authorization\EnsureAdministrationIsPreserved;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the permissions of a role (FND-016). Granted and revoked permissions are audited
 * separately by name; when nothing changes nothing is written. A new catalog permission is
 * never assigned automatically (FND-017), only through this Action. Removing users.assign_roles
 * or roles.manage from the last administrator's roles is rejected (FND-020, E-25). The submitted
 * set must also be coherent (DEC-022), otherwise a validation error on `permissions` is raised.
 */
class SyncRolePermissions
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly EnsureAdministrationIsPreserved $administration,
    ) {}

    /**
     * @param  array<int, int|string>  $permissionIds
     */
    public function handle(Role $role, array $permissionIds, ?User $actor, ?AuditOrigin $origin = null): Role
    {
        return DB::transaction(function () use ($role, $permissionIds, $actor, $origin): Role {
            $this->administration->lock();

            $wanted = Permission::query()->whereIn('id', Arr::wrap($permissionIds))->orderBy('name')->get();
            $current = $role->permissions()->orderBy('permissions.name')->get();

            $this->ensureCoherent($wanted->map(fn (Permission $permission): string => $permission->name)->values()->all());

            $granted = $wanted->reject(fn (Permission $permission): bool => $current->contains('id', $permission->id));
            $revoked = $current->reject(fn (Permission $permission): bool => $wanted->contains('id', $permission->id));

            if ($granted->isEmpty() && $revoked->isEmpty()) {
                return $role;
            }

            $role->permissions()->sync($wanted->modelKeys());

            $this->administration->assert();

            if ($granted->isNotEmpty()) {
                $this->audit->handle(AuditAction::RolePermissionsGranted, $actor, $role, newValues: ['permissions' => $granted->pluck('name')->values()->all()], origin: $origin);
            }

            if ($revoked->isNotEmpty()) {
                $this->audit->handle(AuditAction::RolePermissionsRevoked, $actor, $role, oldValues: ['permissions' => $revoked->pluck('name')->values()->all()], origin: $origin);
            }

            return $role;
        });
    }

    /**
     * DEC-022: `roles.manage` requires `roles.view`, and every `users.*` permission other than
     * `users.view` requires `users.view`. The whole submitted set is checked, so the role never
     * changes when the assignment is incoherent.
     *
     * @param  array<int, string>  $names
     *
     * @throws ValidationException
     */
    private function ensureCoherent(array $names): void
    {
        $has = fn (PermissionName $permission): bool => in_array($permission->value, $names, true);
        $messages = [];

        if ($has(PermissionName::RolesManage) && ! $has(PermissionName::RolesView)) {
            $messages[] = 'El permiso «Crear, modificar y eliminar roles y asignarles permisos» requiere también «Consultar roles y sus permisos».';
        }

        $usersDependents = [
            PermissionName::UsersCreate,
            PermissionName::UsersUpdate,
            PermissionName::UsersDeactivate,
            PermissionName::UsersResetPassword,
            PermissionName::UsersAssignRoles,
        ];

        if (! $has(PermissionName::UsersView) && array_filter($usersDependents, $has) !== []) {
            $messages[] = 'Los permisos de gestión de usuarios requieren también «Consultar listado y detalle de usuarios».';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['permissions' => $messages]);
        }
    }
}
