<?php

namespace App\Support\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Failed-login counter and lockout per normalized email (FND-003, DEC-017, DEC-018).
 *
 * Every method must run inside the login transaction: the row is read FOR UPDATE, so
 * concurrent attempts for the same email are serialized. The counter has no time window.
 */
class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    /**
     * Minutes left on the lock, or null when the email is not locked. An expired lock is
     * normalized here (counter back to 0), which is the DEC-017 reset when the lock ends.
     */
    public function lockedMinutesRemaining(string $normalizedEmail): ?int
    {
        $row = $this->lockedRow($normalizedEmail);

        if ($row->locked_until === null) {
            return null;
        }

        $remainingSeconds = Carbon::parse($row->locked_until, 'UTC')->getTimestamp() - now()->getTimestamp();

        if ($remainingSeconds <= 0) {
            DB::table('login_throttles')->where('email', $normalizedEmail)->update([
                'failed_attempts' => 0,
                'locked_until' => null,
                'updated_at' => now(),
            ]);

            return null;
        }

        return max(1, (int) ceil($remainingSeconds / 60));
    }

    /**
     * Counts one failed attempt. Returns true when this failure imposed the lock.
     */
    public function recordFailure(string $normalizedEmail): bool
    {
        $row = $this->lockedRow($normalizedEmail);
        $attempts = $row->failed_attempts + 1;
        $imposesLock = $attempts >= self::MAX_ATTEMPTS;

        DB::table('login_throttles')->where('email', $normalizedEmail)->update([
            'failed_attempts' => $attempts,
            // Whole-second timestamp so the remaining minutes are exact (no DB rounding).
            'locked_until' => $imposesLock
                ? Carbon::createFromTimestampUTC(now()->getTimestamp() + self::LOCK_MINUTES * 60)
                : null,
            'updated_at' => now(),
        ]);

        return $imposesLock;
    }

    /**
     * Successful login: the row is transient security state and is removed (FND-003).
     */
    public function clear(string $normalizedEmail): void
    {
        DB::table('login_throttles')->where('email', $normalizedEmail)->delete();
    }

    /**
     * Ensures the row exists and returns it locked FOR UPDATE.
     */
    private function lockedRow(string $normalizedEmail): object
    {
        DB::table('login_throttles')->insertOrIgnore([
            'email' => $normalizedEmail,
            'failed_attempts' => 0,
            'locked_until' => null,
            'updated_at' => now(),
        ]);

        return DB::table('login_throttles')->where('email', $normalizedEmail)->lockForUpdate()->first();
    }
}
