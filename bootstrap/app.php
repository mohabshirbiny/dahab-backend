<?php

use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Http\Middleware\ElevateDatabaseScope;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnforceStaffPermission;
use App\Http\Middleware\EnsureCustomerStanding;
use App\Http\Middleware\EnsureStaffStanding;
use App\Http\Middleware\SetDatabaseActor;
use App\Http\Middleware\SetRequestContext;
use App\Http\Middleware\UseMarketScope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
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
            // The public market's read-only row-level-security scope (spec 010). Only on /market/*.
            'db.market' => UseMarketScope::class,
            // Idempotency-Key on state-creating POSTs (spec 007 research R2). List it last on a route.
            'idempotent' => EnforceIdempotency::class,
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

        // Spec 008 research R6: the deferred non-negative trigger (SQLSTATE
        // DH001) is the backstop behind PostLedgerEntryAction's own check.
        // Spec 009: the top-up guard trigger (SQLSTATE DH003) freezes final
        // states; reaching it means a lost race or an illegal move.
        $exceptions->map(function (QueryException $e) {
            return match ($e->errorInfo[0] ?? null) {
                'DH001' => DomainApiException::insufficientFunds(),
                'DH003' => DomainApiException::illegalTopUpTransition(),
                // Spec 010: the listing guard triggers (illegal move, unrecorded move, frozen columns).
                'DH004' => DomainApiException::illegalListingTransition(),
                // Spec 011: the buy-request guards; a second active request from the
                // same buyer hits the partial unique index. Spec 012: the order guard
                // and its deferred checks have their own SQLSTATE, DH006.
                'DH005' => DomainApiException::illegalBuyRequestTransition(),
                'DH006' => DomainApiException::illegalOrderTransition(),
                // Spec 013: the withdrawal and payout-account guards.
                'DH007' => DomainApiException::illegalWithdrawalTransition(),
                'DH008' => DomainApiException::illegalPayoutAccountTransition(),
                // Spec 014: the dispute and request-for-more-time guards; the unique
                // indexes answer a lost race with the same code the Action gives.
                'DH009' => DomainApiException::illegalDisputeTransition(),
                'DH010' => DomainApiException::illegalExtensionRequestTransition(),
                // Spec 016: the credit-note cap answers like the Action; any other DH012
                // (an invoice or credit note not matching its entry) is a bug — a 500.
                'DH012' => match (true) {
                    str_contains($e->getMessage(), 'credit_exceeds_invoice') => DomainApiException::creditExceedsInvoice(),
                    str_contains($e->getMessage(), 'invoice_not_creditable') => DomainApiException::invoiceNotCreditable(),
                    default => $e,
                },
                '23505' => match (true) {
                    str_contains($e->getMessage(), 'one_active_request_per_buyer_listing') => DomainApiException::alreadyInQueue(),
                    str_contains($e->getMessage(), 'dispute_one_per_party') => DomainApiException::disputeAlreadyRaised(),
                    str_contains($e->getMessage(), 'uq_dispute_one_unresolved') => DomainApiException::orderFrozen(),
                    str_contains($e->getMessage(), 'uq_extension_request_waiting') => DomainApiException::extensionRequestPending(),
                    default => $e,
                },
                default => $e,
            };
        });

        // Spec 011: a deferred trigger fires at COMMIT, and PDO raises it from
        // commit() as a bare PDOException — a lost race still answers 409.
        $exceptions->map(function (PDOException $e) {
            return match ($e->errorInfo[0] ?? null) {
                'DH001' => DomainApiException::insufficientFunds(),
                'DH004' => DomainApiException::illegalListingTransition(),
                'DH005' => DomainApiException::illegalBuyRequestTransition(),
                'DH006' => DomainApiException::illegalOrderTransition(),
                'DH007' => DomainApiException::illegalWithdrawalTransition(),
                'DH008' => DomainApiException::illegalPayoutAccountTransition(),
                'DH009' => DomainApiException::illegalDisputeTransition(),
                'DH010' => DomainApiException::illegalExtensionRequestTransition(),
                default => $e,
            };
        });

        $exceptions->render(function (DomainApiException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $body = ['message' => $e->getMessage(), 'code' => $e->errorCode];
            if ($e->details !== []) {
                $body['details'] = $e->details;
            }

            return response()->json($body, $e->statusCode);
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
