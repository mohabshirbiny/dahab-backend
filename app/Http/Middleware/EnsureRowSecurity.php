<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainApiException;
use App\Support\Database\RowSecurityGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API request, first in the `api` group: while the database role can bypass
 * row-level security the API answers `503 database_isolation_off` and serves
 * nothing, because every customer read and write relies on that isolation (spec 003).
 */
final class EnsureRowSecurity
{
    public function __construct(private readonly RowSecurityGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->guard->bypassed()) {
            Log::critical('The database role bypasses row-level security; the API is stopped until it is replaced (php artisan db:check-isolation).');

            throw DomainApiException::databaseIsolationOff();
        }

        return $next($request);
    }
}
