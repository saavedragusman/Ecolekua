<?php

namespace App\Support\Http;

/**
 * The only routes reachable without authentication (FND-026, design Decision 5).
 *
 * Every other route is protected by default through RequireAuthentication. A later
 * spec that needs a public route adds its name here with a comment citing that spec.
 */
final class PublicRoutes
{
    /** Route names declared public by a spec. Spec 001 / FND-026: login only. */
    public const NAMES = ['login', 'login.store'];

    public static function contains(?string $routeName): bool
    {
        return $routeName !== null && in_array($routeName, self::NAMES, true);
    }
}
