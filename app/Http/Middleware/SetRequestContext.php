<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\Staff;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;

final class SetRequestContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        $fingerprint = $this->fingerprint($request);

        if ($user instanceof Customer) {
            $ctx = RequestContext::forCustomer($request, $user->getKey(), $fingerprint);
        } elseif ($user instanceof Staff) {
            $ctx = RequestContext::forStaff($request, $user->getKey(), $fingerprint);
        } else {
            $ctx = RequestContext::anonymous($request, $fingerprint);
        }

        $request->attributes->set('context', $ctx);
        app()->instance(RequestContext::class, $ctx);

        return $next($request);
    }

    private function fingerprint(Request $request): ?string
    {
        $id = $request->header('X-Device-Id');
        if ($id === null) {
            return null;
        }
        $platform = $request->header('X-Device-Platform', 'web');

        return hash('sha256', $id.'|'.$platform);
    }
}
