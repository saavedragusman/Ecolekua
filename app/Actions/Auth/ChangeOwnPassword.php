<?php

namespace App\Actions\Auth;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Own password change (FND-015). Also completes a forced change (FND-014): the same
 * operation clears `must_change_password`. The audit row carries no password data (E-28).
 */
class ChangeOwnPassword
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(User $user, string $newPassword): void
    {
        DB::transaction(function () use ($user, $newPassword): void {
            // The `hashed` cast on User hashes the value; the plain text is never stored.
            $user->update([
                'password' => $newPassword,
                'must_change_password' => false,
            ]);

            $this->audit->handle(AuditAction::PasswordChanged, $user, $user);
        });
    }
}
