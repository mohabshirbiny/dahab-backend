<?php

namespace App\Http\Middleware;

use App\Support\DatabaseActor;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `db.elevate:bootstrap` — lets an unauthenticated customer auth route read
 * and create customer rows before any actor exists (registration uniqueness
 * checks, sign-in, new-device OTP; spec 003 research R4). Declared per route
 * in routes/api.php; ElevationTest pins the exact list and that it never sits
 * on an authenticated route. The frame ends with the request.
 */
final class ElevateDatabaseScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if ($scope !== 'bootstrap') {
            throw new InvalidArgumentException("Routes may only elevate to [bootstrap], not [{$scope}].");
        }

        return DatabaseActor::elevate('bootstrap', fn () => $next($request));
    }
}
