<?php

namespace App\Actions\Users;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Auth\SessionInvalidator;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a user (FND-011): no physical delete, history stays intact, and every open
 * session is invalidated in the same transaction (FND-006, E-09). The self-deactivation and
 * last-administrator guards are added with the administrative protections (task 6.8).
 */
class DeactivateUser
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly SessionInvalidator $sessions,
    ) {}

    public function handle(User $user, ?User $actor, ?AuditOrigin $origin = null): User
    {
        return DB::transaction(function () use ($user, $actor, $origin): User {
            if (! $user->is_active) {
                return $user;
            }

            $user->forceFill(['is_active' => false])->save();

            $this->sessions->forUser($user);

            $this->audit->handle(
                AuditAction::UserDeactivated,
                $actor,
                $user,
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false],
                origin: $origin,
            );

            return $user;
        });
    }
}
