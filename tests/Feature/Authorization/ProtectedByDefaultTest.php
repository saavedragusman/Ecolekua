<?php

use App\Support\Http\PublicRoutes;
use Illuminate\Support\Facades\Route;

it('E-30 every registered route except the declared public ones redirects a guest to login', function () {
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (PublicRoutes::contains($route->getName())) {
            continue;
        }

        $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $response = $this->call($method, '/'.ltrim($uri, '/'));

            expect($response->getStatusCode())
                ->toBe(302, "{$method} {$route->uri()} did not redirect a guest.")
                ->and($response->headers->get('Location'))
                ->toBe(route('login'), "{$method} {$route->uri()} redirected somewhere other than login.");

            $checked++;
        }
    }

    // The walk must not pass vacuously: at least `home` has to have been checked.
    expect($checked)->toBeGreaterThan(0);
});

it('E-30 the public route list is exactly the one spec 001 declares and both names are registered', function () {
    expect(PublicRoutes::NAMES)->toBe(['login', 'login.store']);

    foreach (PublicRoutes::NAMES as $name) {
        expect(Route::has($name))->toBeTrue("Public route [{$name}] is not registered.");
    }
});

it('E-30 contains() matches only declared names', function () {
    expect(PublicRoutes::contains('login'))->toBeTrue()
        ->and(PublicRoutes::contains('login.store'))->toBeTrue()
        ->and(PublicRoutes::contains('home'))->toBeFalse()
        ->and(PublicRoutes::contains(null))->toBeFalse();
});
