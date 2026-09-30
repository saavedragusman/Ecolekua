<?php

namespace App\Actions\Roles;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Facades\DB;

/**
 * Creates a role (FND-016). New roles are never protected and start without permissions (DEC-013).
 */
class CreateRole
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?User $actor, ?AuditOrigin $origin = null): Role
    {
        return DB::transaction(function () use ($data, $actor, $origin): Role {
            $role = Role::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            $this->audit->handle(
                AuditAction::RoleCreated,
                $actor,
                $role,
                newValues: ['name' => $role->name, 'description' => $role->description],
                origin: $origin,
            );

            return $role;
        });
    }
}
