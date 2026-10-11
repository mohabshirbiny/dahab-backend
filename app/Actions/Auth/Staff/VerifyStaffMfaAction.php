<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\Staff;
use App\Services\StaffMfaSessionStore;
use App\Support\Auth\StaffTotpVerifier;
use App\Support\RequestContext;
use App\Support\SessionDto;

/**
 * Second step of a staff sign-in for an already-enrolled account: redeem the
 * `session_ref` from login with a TOTP code (or a one-time recovery code).
 * A founder on a device not yet known is held for the other founder's
 * approval instead of signed in (spec 021).
 */
final class VerifyStaffMfaAction
{
    public function __construct(
        private readonly CompleteStaffSignInAction $complete,
        private readonly RecordAuditLogAction $audit,
        private readonly StaffMfaSessionStore $mfaSessions,
        private readonly StaffTotpVerifier $totp,
        private readonly FounderDeviceGate $deviceGate,
    ) {}

    /**
     * @return array{staff: Staff, session: SessionDto}|array<string, mixed> the session, or the held answer (spec 021 R18)
     */
    public function execute(string $sessionRef, ?string $code, ?string $recoveryCode, RequestContext $ctx): array
    {
        return $this->mfaSessions->lock($sessionRef)->block(5, function () use ($sessionRef, $code, $recoveryCode, $ctx) {
            $pending = $this->mfaSessions->find($sessionRef, StaffMfaSessionStore::KIND_CHALLENGE);

            // Unknown, expired, already used, or an enrollment ref: one generic answer.
            if ($pending === null) {
                throw AuthApiException::mfaInvalid();
            }

            $staff = Staff::query()->with('mfa')->find($pending['staff_id']);
            $actorCtx = $ctx->withStaff($pending['staff_id']);

            // The account may have been disabled/frozen/de-enrolled since the password step.
            if ($staff === null || ! $staff->is_active || $staff->activeFreeze()->exists() || $staff->mfa === null) {
                $this->mfaSessions->forget($sessionRef);
                $this->fail($actorCtx, 'account_unavailable');
            }

            $method = $code !== null ? 'totp' : 'recovery_code';
            $valid = $code !== null
                ? $this->totp->verifyTotp($staff, $code)
                : $this->totp->redeemRecoveryCode($staff, (string) $recoveryCode);

            if (! $valid) {
                $this->fail($actorCtx, 'wrong_code', $method);
            }

            $this->mfaSessions->forget($sessionRef);

            $this->audit->execute(
                AuditEvent::STAFF_MFA_VERIFIED,
                'success',
                ['method' => $method],
                'staff',
                $staff->staff_id,
                $actorCtx,
            );

            $held = $this->deviceGate->hold($staff, $ctx);
            if ($held !== null) {
                return $held;
            }

            return ['staff' => $staff, 'session' => $this->complete->execute($staff, $ctx, 'mfa')];
        });
    }

    private function fail(RequestContext $ctx, string $reason, ?string $method = null): never
    {
        $this->audit->execute(
            AuditEvent::STAFF_MFA_FAILED,
            'failure',
            array_filter(['reason' => $reason, 'method' => $method]),
            'staff',
            $ctx->staffId,
            $ctx,
        );

        throw AuthApiException::mfaInvalid();
    }
}
