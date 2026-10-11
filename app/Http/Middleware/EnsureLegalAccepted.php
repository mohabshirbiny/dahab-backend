<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Support\Legal\LegalAcceptanceRequirement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `customer.legal` — the required-acceptance gate (spec 019 FR-025, Terms §13).
 *
 * While a customer owes an acceptance of the live `terms` or `privacy`, every state-changing
 * request on the signed-in customer API is refused `409 legal_acceptance_required` with what is
 * owed. Reads pass (the app must be able to show the documents), and so do the acceptance
 * itself and signing out (those routes live outside this group). There is no other allow-list.
 *
 * Not a permission and not account standing: it is checked after authentication and before the
 * verified gate, for every state, so a pending or suspended customer meets it too.
 */
final class EnsureLegalAccepted
{
    public const ACCEPT_ROUTE = 'api.v1.customer.me.legal-acceptances.store';

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $request->route()?->getName() === self::ACCEPT_ROUTE) {
            return $next($request);
        }

        $customer = $request->user();
        if ($customer instanceof Customer) {
            $required = LegalAcceptanceRequirement::for($customer->customer_id);
            if ($required !== []) {
                throw DomainApiException::legalAcceptanceRequired($required);
            }
        }

        return $next($request);
    }
}
