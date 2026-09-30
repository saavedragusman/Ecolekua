<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Invalidates every open session of a user at once (FND-006, design Decision 6).
 *
 * Runs inside the caller's transaction (DeactivateUser, ResetUserPassword).
 */
class SessionInvalidator
{
    public function forUser(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
    }
}
