<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\Staff;
use App\Models\StaffMfa;
use App\Services\StaffMfaSessionStore;
use App\Support\RequestContext;
use App\Support\SessionDto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Second step of a staff sign-in for an already-enrolled account: redeem the
 * `session_ref` from login with a TOTP code (or a one-time recovery code).
 */
final class VerifyStaffMfaAction
{
    public function __construct(
        private readonly CompleteStaffSignInAction $complete,
        private readonly RecordAuditLogAction $audit,
        private readonly StaffMfaSessionStore $mfaSessions,
        private readonly Google2FA $google2fa,
    ) {}

    /**
     * @return array{staff: Staff, session: SessionDto}
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
                ? $this->verifyTotp($staff, $code)
                : $this->redeemRecoveryCode($staff, (string) $recoveryCode);

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

            return ['staff' => $staff, 'session' => $this->complete->execute($staff, $ctx, 'mfa')];
        });
    }

    /** A code is good once: the last accepted time-step is remembered and never accepted again. */
    private function verifyTotp(Staff $staff, string $code): bool
    {
        $replayKey = 'staff-mfa-last-timestep:'.$staff->staff_id;

        // The 0 seed matters: with a null "old" step the library answers `true`
        // instead of the matched time-step, and there would be nothing to remember.
        $timestep = $this->google2fa->verifyKeyNewer(
            $staff->mfa->mfa_secret_encrypted,
            $code,
            (int) Cache::get($replayKey, 0),
        );

        if (! is_int($timestep)) {
            return false;
        }

        Cache::put($replayKey, $timestep, now()->addMinutes(5));

        return true;
    }

    private function redeemRecoveryCode(Staff $staff, string $recoveryCode): bool
    {
        $recoveryCode = Str::lower(trim($recoveryCode));

        return DB::transaction(function () use ($staff, $recoveryCode) {
            $mfa = StaffMfa::query()->whereKey($staff->staff_id)->lockForUpdate()->firstOrFail();
            $hashes = $mfa->recovery_codes_hash ?? [];

            foreach ($hashes as $i => $hash) {
                if (Hash::check($recoveryCode, $hash)) {
                    unset($hashes[$i]);
                    $mfa->update(['recovery_codes_hash' => array_values($hashes)]);

                    return true;
                }
            }

            return false;
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
