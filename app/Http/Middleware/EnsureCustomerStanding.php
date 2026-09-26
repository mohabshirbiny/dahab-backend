<?php

namespace App\Http\Middleware;

use App\Enums\CustomerStatus;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `customer.gate:verified|trade` — the customer verified gate (spec 002
 * FR-030–FR-033, data-model §6). Reads the live `customer.status` on every
 * request, never a value cached with the token.
 *
 * | status                          | verified                  | trade                     |
 * |---------------------------------|---------------------------|---------------------------|
 * | active                          | pass                      | pass                      |
 * | suspended                       | pass (own-data reads)     | 403 account_suspended     |
 * | pending_verification / rejected | 403 verification_required | 403 verification_required |
 *
 * Routes open to unverified customers are listed in App\Http\CustomerRouteAccess.
 */
final class EnsureCustomerStanding
{
    public function handle(Request $request, Closure $next, string $level): Response
    {
        if (! in_array($level, ['verified', 'trade'], true)) {
            throw new InvalidArgumentException("Unknown customer gate level [{$level}].");
        }

        $customer = $request->user();

        if (! $customer instanceof Customer) {
            throw new AuthenticationException;
        }

        $status = Customer::query()->whereKey($customer->getKey())->value('status');
        $status = $status instanceof CustomerStatus ? $status : CustomerStatus::from((string) $status);

        match ($status) {
            CustomerStatus::ACTIVE => null,
            CustomerStatus::SUSPENDED => $level === 'trade' ? throw AuthApiException::accountSuspended() : null,
            CustomerStatus::PENDING_VERIFICATION, CustomerStatus::REJECTED => throw DomainApiException::verificationRequired(),
        };

        return $next($request);
    }
}
