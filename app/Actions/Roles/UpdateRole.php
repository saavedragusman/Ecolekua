<?php

namespace App\Actions\Roles;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Modifies the name and description of a role (FND-016). The protected role cannot be renamed
 * but its description can be edited (E-23). The audit row carries only the changed fields; when
 * nothing changed nothing is written.
 */
class UpdateRole
{
    private const FIELDS = ['name', 'description'];

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Role $role, array $data, ?User $actor, ?AuditOrigin $origin = null): Role
    {
        return DB::transaction(function () use ($role, $data, $actor, $origin): Role {
            $role->fill(Arr::only($data, self::FIELDS));

            if ($role->is_protected && $role->isDirty('name')) {
                throw new BusinessRuleViolation('El rol Administrador no puede renombrarse.');
            }

            $changed = array_keys($role->getDirty());

            if ($changed === []) {
                return $role;
            }

            $old = Arr::only($role->getOriginal(), $changed);
            $new = Arr::only($role->getAttributes(), $changed);

            $role->save();

            $this->audit->handle(AuditAction::RoleUpdated, $actor, $role, oldValues: $old, newValues: $new, origin: $origin);

            return $role;
        });
    }
}
