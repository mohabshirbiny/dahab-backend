<?php

namespace App\Http\Middleware;

use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the current actor to a Postgres session variable so RLS
 * policies can read `dahab_current_customer_id()` / `dahab_current_staff_id()`.
 *
 * Uses `SET` (session-scoped) rather than `SET LOCAL` because we do not
 * wrap the whole request in a transaction here. Downstream Actions that
 * open transactions inherit the session variable; when the connection is
 * released back to the pool at end of request Laravel calls
 * `DiscardAll`-ish cleanup between requests.
 */
final class SetDatabaseActor
{
    public function handle(Request $request, Closure $next)
    {
        /** @var RequestContext|null $ctx */
        $ctx = $request->attributes->get('context');

        if ($ctx !== null) {
            DB::statement(
                "SELECT set_config('app.current_customer_id', ?, false), set_config('app.current_staff_id', ?, false)",
                [$ctx->customerId ?? '', $ctx->staffId ?? '']
            );
        }

        return $next($request);
    }
}
