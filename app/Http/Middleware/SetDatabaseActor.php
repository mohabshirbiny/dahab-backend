<?php

namespace App\Http\Middleware;

use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request's actor for PostgreSQL row-level security (spec 003).
 *
 * Runs after authentication (route middleware priority puts `auth:*` first).
 * A customer principal binds the `customer` scope — the database then only
 * returns that customer's rows; a staff principal binds the `staff` scope
 * (Clarification Q1: staff see all customers, their permissions decide what
 * they may do); an anonymous request binds nothing, so customer tables are
 * closed to it unless a route declares `db.elevate:bootstrap`.
 *
 * The frame is popped in `finally`, so the identity never outlives the
 * request on a reused connection (FR-010).
 */
final class SetDatabaseActor
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var RequestContext|null $ctx */
        $ctx = $request->attributes->get('context');

        match (true) {
            $ctx?->customerId !== null => DatabaseActor::push('customer', customerId: $ctx->customerId),
            $ctx?->staffId !== null => DatabaseActor::push('staff', staffId: $ctx->staffId),
            default => DatabaseActor::push(''),
        };

        try {
            return $next($request);
        } finally {
            DatabaseActor::pop();
        }
    }
}
