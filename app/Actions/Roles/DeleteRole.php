<?php

namespace App\Actions\Roles;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Authorization\EnsureAdministrationIsPreserved;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a role (FND-016). The protected role and any role with users assigned are rejected
 * (E-22, E-23). Roles are not entities with history (their identity lives in the audit row), so
 * the delete is physical; the permission links go with the role. A role without users cannot
 * hold the last administrator, so the last-administrator check (design Decision 12) is defensive.
 */
class DeleteRole
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly EnsureAdministrationIsPreserved $administration,
    ) {}

    public function handle(Role $role, ?User $actor, ?AuditOrigin $origin = null): void
    {
        DB::transaction(function () use ($role, $actor, $origin): void {
            $this->administration->lock();

            if ($role->is_protected) {
                throw new BusinessRuleViolation('El rol Administrador no puede eliminarse.');
            }

            if ($role->users()->exists()) {
                throw new BusinessRuleViolation('El rol tiene usuarios asignados y no puede eliminarse.');
            }

            $old = [
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all(),
            ];

            $role->delete();

            $this->administration->assert();

            $this->audit->handle(AuditAction::RoleDeleted, $actor, $role, oldValues: $old, origin: $origin);
        });
    }
}
