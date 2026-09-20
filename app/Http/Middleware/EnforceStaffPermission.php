<?php

namespace App\Http\Middleware;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\Staff;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for dashboard endpoints: `staff.permission:customer.suspend`.
 *
 * The decision is Spatie's (`$staff->can()` → Gate → HasRoles); this only
 * turns a denial into the documented `403 permission_denied` envelope and
 * audit-logs it (spec FR-S-010, FR-X-005). Anything that is not a Staff
 * principal is refused, so a route mistakenly left on a generic guard still
 * cannot be reached by a Customer.
 */
final class EnforceStaffPermission
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        if ($user instanceof Staff && $user->can($permission)) {
            return $next($request);
        }

        if ($user instanceof Staff) {
            $this->audit->execute(
                AuditEvent::STAFF_PERMISSION_DENIED,
                'denied',
                [
                    'permission' => $permission,
                    'method' => $request->method(),
                    'path' => $request->path(),
                ],
                entityType: 'permission',
                actorStaffId: $user->getKey(),
            );
        }

        throw AuthApiException::permissionDenied();
    }
}
