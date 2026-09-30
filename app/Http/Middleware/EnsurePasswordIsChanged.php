<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forced password change (FND-014, design Decision 8): while `must_change_password` is set,
 * only the change form, its submission and logout are reachable. Any other request is
 * redirected before a controller or policy runs, so it has no side effects and is not a
 * permission denial (it is not audited as one).
 */
class EnsurePasswordIsChanged
{
    /** Route names a user with a pending forced change may still use. */
    private const ALLOWED_ROUTES = ['password.edit', 'password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        Inertia::flash([
            'type' => 'error',
            'message' => 'Debe cambiar su contraseña temporal antes de continuar.',
        ]);

        return redirect()->route('password.edit');
    }
}
