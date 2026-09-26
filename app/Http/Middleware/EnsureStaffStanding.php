<?php

namespace App\Http\Middleware;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\AccountFreeze;
use App\Models\Staff;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * `staff.standing`: a freeze or a deactivation stops a staff member on
 * their very next request, not only at the next sign-in (spec 002 FR-056,
 * Part 1 §4.4 / §8). Runs after `auth:staff` and before `staff.permission`.
 *
 * - deactivated → the current token is revoked, 401 `unauthenticated`;
 * - open account freeze → 403 `account_frozen`, audited.
 *
 * Both are re-read from the database on each request. Sign-out routes do
 * not carry this middleware, so a frozen account can still sign out.
 */
final class EnsureStaffStanding
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user();

        if (! $staff instanceof Staff) {
            throw new AuthenticationException;
        }

        $isActive = Staff::query()->whereKey($staff->getKey())->value('is_active');

        if (! $isActive) {
            $token = $staff->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            throw new AuthenticationException;
        }

        $frozen = AccountFreeze::query()
            ->where('frozen_staff_id', $staff->getKey())
            ->whereNull('unfrozen_at')
            ->exists();

        if ($frozen) {
            $this->audit->execute(
                AuditEvent::STAFF_PERMISSION_DENIED,
                'denied',
                ['reason' => 'frozen', 'method' => $request->method(), 'path' => $request->path()],
                entityType: 'staff',
                entityId: $staff->getKey(),
                actorStaffId: $staff->getKey(),
            );

            throw AuthApiException::accountFrozen();
        }

        return $next($request);
    }
}
