<?php

namespace App\Http\Middleware;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Founder-only routes (spec 021 research R13). Runs after `staff.standing`.
 *
 *  - `staff.founder`: `staff.is_founder` → through; anyone else → 403 `founder_only`.
 *  - `staff.founder_or:<permission>`: a founder, or a holder of that permission → through;
 *    anyone else → 403 `permission_denied`.
 *
 * Founder status is a flag no endpoint can change (spec 002 FR-043), never a role, so a role
 * edit can never let a non-founder approve a founder's device. Denials are audited.
 */
final class EnsureFounder
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();

        if (! $user instanceof Staff) {
            throw new AuthenticationException;
        }

        if ($user->is_founder || ($permission !== null && $user->can($permission))) {
            return $next($request);
        }

        $this->audit->execute(
            AuditEvent::STAFF_PERMISSION_DENIED,
            'denied',
            array_filter([
                'reason' => 'founder_only',
                'permission' => $permission,
                'method' => $request->method(),
                'path' => $request->path(),
            ]),
            entityType: 'permission',
            actorStaffId: $user->getKey(),
        );

        throw $permission === null ? DomainApiException::founderOnly() : AuthApiException::permissionDenied();
    }
}
