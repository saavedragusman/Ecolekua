<?php

namespace App\Actions\Users;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Authorization\EnsureAdministrationIsPreserved;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Auth\SessionInvalidator;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a user (FND-011): no physical delete, history stays intact, and every open
 * session is invalidated in the same transaction (FND-006, E-09). Nobody can deactivate
 * themselves (FND-021, E-26) and the last administrator cannot be deactivated (FND-020, E-25).
 */
class DeactivateUser
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly SessionInvalidator $sessions,
        private readonly EnsureAdministrationIsPreserved $administration,
    ) {}

    public function handle(User $user, ?User $actor, ?AuditOrigin $origin = null): User
    {
        if ($actor?->is($user)) {
            throw new BusinessRuleViolation('No puede desactivarse a sí mismo.');
        }

        return DB::transaction(function () use ($user, $actor, $origin): User {
            $this->administration->lock();

            if (! $user->is_active) {
                return $user;
            }

            $user->forceFill(['is_active' => false])->save();

            $this->administration->assert();

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
