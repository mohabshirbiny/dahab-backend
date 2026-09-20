<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\Staff;
use App\Models\StaffMfa;
use App\Models\StaffPassword;
use App\Services\StaffMfaSessionStore;
use App\Support\RequestContext;
use App\Support\SessionDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * Completes a first-time (or forced re-) TOTP enrollment. The secret and the
 * recovery codes were generated at login and live only in the pending session;
 * nothing is persisted until the staff member proves their authenticator app
 * produces a valid code for that secret.
 */
final class EnrollStaffMfaAction
{
    public function __construct(
        private readonly CompleteStaffSignInAction $complete,
        private readonly RecordAuditLogAction $audit,
        private readonly StaffMfaSessionStore $mfaSessions,
        private readonly Google2FA $google2fa,
    ) {}

    /**
     * @return array{staff: Staff, session: SessionDto, recovery_codes: list<string>}
     */
    public function execute(string $sessionRef, string $code, RequestContext $ctx): array
    {
        return $this->mfaSessions->lock($sessionRef)->block(5, function () use ($sessionRef, $code, $ctx) {
            $pending = $this->mfaSessions->find($sessionRef, StaffMfaSessionStore::KIND_ENROLL);

            if ($pending === null) {
                throw AuthApiException::mfaInvalid();
            }

            $staff = Staff::query()->find($pending['staff_id']);
            $actorCtx = $ctx->withStaff($pending['staff_id']);

            if ($staff === null || ! $staff->is_active || $staff->activeFreeze()->exists()) {
                $this->mfaSessions->forget($sessionRef);
                $this->fail($actorCtx, 'account_unavailable');
            }

            if (! $this->google2fa->verifyKey($pending['secret'], $code)) {
                $this->fail($actorCtx, 'wrong_code');
            }

            DB::transaction(function () use ($staff, $pending, $actorCtx) {
                StaffMfa::query()->updateOrCreate(
                    ['staff_id' => $staff->staff_id],
                    [
                        'mfa_secret_encrypted' => $pending['secret'],
                        'enrolled_at' => now(),
                        'recovery_codes_hash' => array_map(fn (string $c) => Hash::make($c), $pending['recovery_codes']),
                    ],
                );

                StaffPassword::query()->whereKey($staff->staff_id)->update(['force_reenroll_mfa_at' => null]);

                $this->audit->execute(AuditEvent::STAFF_MFA_ENROLLED, 'success', [], 'staff', $staff->staff_id, $actorCtx);
            });

            $this->mfaSessions->forget($sessionRef);

            return [
                'staff' => $staff,
                'session' => $this->complete->execute($staff, $ctx, 'mfa_enrollment'),
                'recovery_codes' => $pending['recovery_codes'],
            ];
        });
    }

    private function fail(RequestContext $ctx, string $reason): never
    {
        $this->audit->execute(
            AuditEvent::STAFF_MFA_FAILED,
            'failure',
            ['reason' => $reason, 'stage' => 'enrollment'],
            'staff',
            $ctx->staffId,
            $ctx,
        );

        throw AuthApiException::mfaInvalid();
    }
}
