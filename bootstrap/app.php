<?php

use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Http\Middleware\ElevateDatabaseScope;
use App\Http\Middleware\EnforceStaffPermission;
use App\Http\Middleware\EnsureCustomerStanding;
use App\Http\Middleware\EnsureStaffStanding;
use App\Http\Middleware\SetDatabaseActor;
use App\Http\Middleware\SetRequestContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HandleCors::class);

        // API is Bearer-token only; no first-party SPA cookie flow. Do NOT
        // register statefulApi() — it enables session-cookie auth from
        // trusted domains and keeps a request authenticated after a bearer
        // logout via a lingering cookie.

        $middleware->throttleApi();

        $middleware->alias([
            'staff.permission' => EnforceStaffPermission::class,
            // Frozen/deactivated staff are stopped on every request (spec 002 FR-056).
            'staff.standing' => EnsureStaffStanding::class,
            // Customer verified gate, default-deny (spec 002 FR-030, App\Http\CustomerRouteAccess).
            'customer.gate' => EnsureCustomerStanding::class,
            // Row-level-security bootstrap for unauthenticated customer auth routes (spec 003).
            'db.elevate' => ElevateDatabaseScope::class,
            // Sanctum token abilities: `abilities:customer:access` (all listed)
            // and `ability:a,b` (any listed). Always placed after an `auth:<guard>`.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        $middleware->api(append: [
            SetRequestContext::class,
            SetDatabaseActor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'code' => 'validation_failed',
            ], 422);
        });

        $exceptions->render(function (AuthApiException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $payload = array_merge([
                'message' => $e->getMessage(),
                'code' => $e->errorCode->value,
            ], $e->extra);

            return response()->json($payload, $e->statusCode, $e->headers);
        });

        $exceptions->render(function (DomainApiException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->statusCode);
        });

        // A valid token of the right principal presented to an endpoint whose
        // ability it lacks (e.g. a refresh token on an access endpoint). Laravel
        // has already converted Sanctum's MissingAbilityException into an
        // AccessDeniedHttpException by the time renderers run.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if (! $e->getPrevious() instanceof MissingAbilityException) {
                return null;
            }

            return response()->json([
                'message' => 'This token cannot be used for this endpoint.',
                'code' => 'forbidden',
            ], 403);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'unauthenticated',
            ], 401);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            // Identity lockouts (`account_locked`) never reach this renderer: the
            // login limiters throw AuthApiException::accountLocked() themselves
            // (see AppServiceProvider::lockout()). Everything else is generic.
            return response()->json([
                'message' => 'Too many requests.',
                'code' => 'too_many_requests',
            ], 429)->withHeaders($e->getHeaders());
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            $payload = [
                'message' => $status >= 500 && ! config('app.debug')
                    ? 'Server error.'
                    : ($e->getMessage() ?: 'Server error.'),
                'code' => match (true) {
                    $status === 404 => 'not_found',
                    $status === 403 => 'forbidden',
                    $status === 405 => 'method_not_allowed',
                    $status === 429 => 'too_many_requests',
                    default => 'server_error',
                },
            ];

            if (config('app.debug') && $status >= 500) {
                $payload['exception'] = $e::class;
                $payload['file'] = $e->getFile().':'.$e->getLine();
            }

            return response()->json($payload, $status);
        });
    })->create();
