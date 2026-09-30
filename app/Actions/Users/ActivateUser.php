<?php

namespace App\Actions\Users;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Facades\DB;

/**
 * Reactivates a user (FND-011). An active user must hold at least one role (FND-018), so a
 * user without roles cannot be activated.
 */
class ActivateUser
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(User $user, ?User $actor, ?AuditOrigin $origin = null): User
    {
        return DB::transaction(function () use ($user, $actor, $origin): User {
            if ($user->is_active) {
                return $user;
            }

            if (! $user->roles()->exists()) {
                throw new BusinessRuleViolation('Un usuario activo debe tener al menos un rol.');
            }

            $user->forceFill(['is_active' => true])->save();

            $this->audit->handle(
                AuditAction::UserActivated,
                $actor,
                $user,
                oldValues: ['is_active' => false],
                newValues: ['is_active' => true],
                origin: $origin,
            );

            return $user;
        });
    }
}
