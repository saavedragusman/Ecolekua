<?php

namespace App\Http\Middleware;

use App\Support\Http\PublicRoutes;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protected by default (FND-026, design Decision 5): appended to the web group, it requires
 * an authenticated user on every route unless the route name is declared in PublicRoutes.
 *
 * Extending Authenticate keeps Laravel's middleware priority: this runs before
 * SubstituteBindings, so a guest never triggers a model lookup.
 */
class RequireAuthentication extends Authenticate
{
    /**
     * @param  string  ...$guards
     */
    public function handle($request, Closure $next, ...$guards): Response
    {
        if (PublicRoutes::contains($request->route()?->getName())) {
            return $next($request);
        }

        return parent::handle($request, $next, ...$guards);
    }
}
