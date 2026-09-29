<?php

namespace App\Actions\Users;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Auth\SessionInvalidator;
use Illuminate\Support\Facades\DB;

/**
 * Administrator reset (FND-014): the new password is temporary, so the user must change it at
 * the next login, and every open session of the target is invalidated (E-15). The audit row
 * carries no values at all (E-28). There is no self guard: a self-reset is allowed (DEC-020).
 */
class ResetUserPassword
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly SessionInvalidator $sessions,
    ) {}

    public function handle(User $user, string $newPassword, ?User $actor, ?AuditOrigin $origin = null): User
    {
        return DB::transaction(function () use ($user, $newPassword, $actor, $origin): User {
            // The `hashed` cast on User hashes the value; the plain text is never stored.
            $user->forceFill([
                'password' => $newPassword,
                'must_change_password' => true,
            ])->save();

            $this->sessions->forUser($user);

            $this->audit->handle(AuditAction::UserPasswordReset, $actor, $user, origin: $origin);

            return $user;
        });
    }
}
