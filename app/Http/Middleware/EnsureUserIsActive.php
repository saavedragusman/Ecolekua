<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request active check (FND-006, design Decision 6): re-reads the authenticated user
 * and, when it no longer exists or was deactivated, ends the session and sends the visitor
 * to login. It also covers the in-memory guard and any race with a deactivation.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $current = $user->fresh();

        if ($current === null || ! $current->is_active) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        // Downstream code sees the freshly read attributes, not the session-cached ones.
        Auth::setUser($current);

        return $next($request);
    }
}
