<?php

namespace App\Http;

use Illuminate\Routing\Route;

/**
 * The customer verified gate is default-deny (spec 002 FR-030/FR-031).
 *
 * An authenticated customer route must either appear in OPEN_ROUTES (an
 * unverified customer may call it) or carry `customer.gate:verified` /
 * `customer.gate:trade`. CustomerRouteGateTest fails the build otherwise,
 * so a new endpoint can never be reachable by an unverified customer by
 * accident.
 *
 * Open to unverified customers (pending_verification or rejected): sign-in/out
 * and session refresh, their own profile, and finishing verification (uploads,
 * identity documents — a rejected customer re-submits here for another
 * review). The public marketplace read needs no customer token at all.
 * Everything else, wallet top-up included, requires verification.
 *
 * Wishlist behavior is out of scope for spec 002: whether its routes go here
 * or behind a gate is decided when the wishlist feature is implemented.
 */
final class CustomerRouteAccess
{
    public const OPEN_ROUTES = [
        'api.v1.customer.auth.refresh',
        'api.v1.customer.auth.me',
        'api.v1.customer.auth.logout',
        'api.v1.customer.auth.logout-all',
        'api.v1.customer.me.uploads.store',
        'api.v1.customer.me.identity-documents.store',
    ];

    public const GATES = ['customer.gate:verified', 'customer.gate:trade'];

    /**
     * Routes authenticated as a customer that are neither open nor gated.
     *
     * @param  iterable<Route>  $routes
     * @return list<string> URIs
     */
    public static function ungated(iterable $routes): array
    {
        $ungated = [];

        foreach ($routes as $route) {
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');

            if (! in_array('auth:customer', $middleware, true)) {
                continue;
            }

            if (in_array($route->getName(), self::OPEN_ROUTES, true)) {
                continue;
            }

            if (array_intersect(self::GATES, $middleware) === []) {
                $ungated[] = $route->methods()[0].' '.$route->uri();
            }
        }

        return $ungated;
    }
}
