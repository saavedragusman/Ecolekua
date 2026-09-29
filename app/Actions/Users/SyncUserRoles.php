<?php

namespace App\Actions\Users;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the roles of a user (FND-010). An active user must keep at least one role (FND-018).
 * Added and removed roles are audited separately, with their ids and names. The self-change and
 * last-administrator guards are added with the administrative protections (task 6.8).
 */
class SyncUserRoles
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<int, int|string>  $roleIds
     */
    public function handle(User $user, array $roleIds, ?User $actor, ?AuditOrigin $origin = null): User
    {
        return DB::transaction(function () use ($user, $roleIds, $actor, $origin): User {
            $wanted = Role::query()->whereIn('id', Arr::wrap($roleIds))->orderBy('id')->get();

            if ($user->is_active && $wanted->isEmpty()) {
                throw new BusinessRuleViolation('Un usuario activo debe tener al menos un rol.');
            }

            $current = $user->roles()->orderBy('roles.id')->get();

            $added = $wanted->reject(fn (Role $role): bool => $current->contains('id', $role->id));
            $removed = $current->reject(fn (Role $role): bool => $wanted->contains('id', $role->id));

            if ($added->isEmpty() && $removed->isEmpty()) {
                return $user;
            }

            $user->roles()->sync($wanted->modelKeys());

            if ($added->isNotEmpty()) {
                $this->audit->handle(AuditAction::UserRolesAssigned, $actor, $user, newValues: ['roles' => $this->describe($added)], origin: $origin);
            }

            if ($removed->isNotEmpty()) {
                $this->audit->handle(AuditAction::UserRolesRemoved, $actor, $user, oldValues: ['roles' => $this->describe($removed)], origin: $origin);
            }

            return $user;
        });
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return list<array{id: int, name: string}>
     */
    private function describe(Collection $roles): array
    {
        return array_values($roles->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->all());
    }
}
