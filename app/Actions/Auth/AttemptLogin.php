<?php

namespace App\Actions\Auth;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Support\Auth\LoginThrottle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Login flow (design Data Flow): throttle check, credentials, counters and audit in one
 * transaction. `remember` is never used: there is no remember-me (design Decision 6).
 */
class AttemptLogin
{
    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $normalizedEmail, string $password): LoginResult
    {
        return DB::transaction(function () use ($normalizedEmail, $password): LoginResult {
            $minutes = $this->throttle->lockedMinutesRemaining($normalizedEmail);

            if ($minutes !== null) {
                $this->audit->handle(
                    AuditAction::LoginFailed,
                    null,
                    context: ['reason' => 'locked'],
                    actorEmail: $normalizedEmail,
                );

                return LoginResult::locked($minutes);
            }

            $authenticated = Auth::attempt(
                ['email' => $normalizedEmail, 'password' => $password, 'is_active' => true],
                remember: false,
            );

            if (! $authenticated) {
                $this->audit->handle(AuditAction::LoginFailed, null, actorEmail: $normalizedEmail);

                if ($this->throttle->recordFailure($normalizedEmail)) {
                    $this->audit->handle(AuditAction::Lockout, null, actorEmail: $normalizedEmail);
                }

                return LoginResult::failed();
            }

            $this->throttle->clear($normalizedEmail);
            request()->session()->regenerate();
            $this->audit->handle(AuditAction::LoginSucceeded, Auth::user());

            return LoginResult::succeeded();
        });
    }
}
