<?php

namespace App\Http\Middleware;

use App\Enums\TokenAbility;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `db.market` — the public market's database scope (spec 010 research R2):
 *
 *     public market → `market` row-level-security scope → listing → public Resource
 *
 * The scope is read-only and sees only live/reserved listings, their branch
 * options and their non-private media. It is declared only on the
 * `/market/*` routes (MarketScopeTest pins the list) and never on an
 * authenticated route.
 *
 * A customer access token is optional: when a valid one is sent, the
 * customer's id is kept on the request — never in the database frame — so
 * the response can mark the caller's own pieces (`is_mine`). A missing,
 * expired or wrong-kind token is simply an anonymous visitor.
 */
final class UseMarketScope
{
    public const VIEWER = 'market_viewer_id';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::VIEWER, $this->viewerId($request));

        return DatabaseActor::market(fn () => $next($request));
    }

    private function viewerId(Request $request): ?string
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        $customer = auth('customer')->user();

        return $customer instanceof Customer && $customer->tokenCan(TokenAbility::CUSTOMER_ACCESS->value)
            ? $customer->customer_id
            : null;
    }
}
