<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\Staff;
use App\Services\StaffMfaSessionStore;
use App\Support\RequestContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

final class LoginStaffAction
{
    public function __construct(
        private readonly CompleteStaffSignInAction $complete,
        private readonly RecordAuditLogAction $audit,
        private readonly StaffMfaSessionStore $mfaSessions,
        private readonly Google2FA $google2fa,
    ) {}

    /**
     * One of three shapes, told apart by key:
     *  - `staff` + `session`: signed in;
     *  - `mfa_required` + `session_ref` + `expires_at`: TOTP challenge;
     *  - `mfa_enrollment_required` + `otpauth_url` + `recovery_codes` + `session_ref` + `expires_at`: first enrollment.
     *
     * @return array<string, mixed>
     */
    public function execute(string $email, string $password, RequestContext $ctx): array
    {
        $staff = Staff::query()->where('email', $email)->with(['password', 'mfa'])->first();

        // The system actor (spec 002 FR-061) has no password row, but refuse it
        // explicitly too: it must look exactly like an unknown account.
        if ($staff === null || $staff->is_system || $staff->password === null) {
            // No actor to attribute to: the audit action falls back to the app log.
            $this->fail($ctx, $email, 'unknown_account');
        }

        $actorCtx = $ctx->withStaff($staff->staff_id);

        if (! Hash::check($password, $staff->password->password_hash)) {
            $this->fail($actorCtx, $email, 'wrong_password', $staff);
        }

        // Only after the password checks out, so neither state can be probed
        // with a guessed email. A disabled account looks like a wrong password.
        if (! $staff->is_active) {
            $this->fail($actorCtx, $email, 'inactive', $staff);
        }

        if ($staff->activeFreeze()->exists()) {
            $this->audit->execute(AuditEvent::STAFF_SIGN_IN_FAILED, 'failure', ['reason' => 'frozen', 'email' => $email], 'staff', $staff->staff_id, $actorCtx);

            throw AuthApiException::accountFrozen();
        }

        if (Hash::needsRehash($staff->password->password_hash)) {
            $staff->password->update([
                'password_hash' => Hash::make($password),
                'password_changed_at' => now(),
            ]);
        }

        return match ($this->mfaStep($staff)) {
            'enroll' => $this->beginEnrollment($staff),
            'challenge' => $this->beginChallenge($staff),
            default => ['staff' => $staff, 'session' => $this->complete->execute($staff, $ctx, 'password')],
        };
    }

    /**
     * MFA is mandatory for founders and for anyone holding a role flagged
     * `requires_mfa` (spec 002 FR-040); anyone who enrolled voluntarily is
     * challenged too. A password reset that flags
     * `force_reenroll_mfa_at` invalidates an enrollment older than the flag.
     *
     * @return 'enroll'|'challenge'|null
     */
    private function mfaStep(Staff $staff): ?string
    {
        $mfa = $staff->mfa;
        $forced = $staff->password->force_reenroll_mfa_at;
        $enrolled = $mfa !== null && ($forced === null || $mfa->enrolled_at->gte($forced));

        if ($enrolled) {
            return 'challenge';
        }

        return config('dahab-auth.mfa_enforced') && $staff->requiresMfa() ? 'enroll' : null;
    }

    private function beginChallenge(Staff $staff): array
    {
        $session = $this->mfaSessions->begin($staff, StaffMfaSessionStore::KIND_CHALLENGE);

        return ['mfa_required' => true, 'session_ref' => $session['ref'], 'expires_at' => $session['expires_at']];
    }

    private function beginEnrollment(Staff $staff): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $recoveryCodes = $this->newRecoveryCodes();
        $session = $this->mfaSessions->begin($staff, StaffMfaSessionStore::KIND_ENROLL, [
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
        ]);

        return [
            'mfa_enrollment_required' => true,
            'otpauth_url' => $this->google2fa->getQRCodeUrl((string) config('app.name'), $staff->email, $secret),
            'recovery_codes' => $recoveryCodes,
            'session_ref' => $session['ref'],
            'expires_at' => $session['expires_at'],
        ];
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return collect(range(1, (int) config('dahab-auth.mfa.recovery_code_count')))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    private function fail(RequestContext $ctx, string $email, string $reason, ?Staff $staff = null): never
    {
        $this->audit->execute(
            AuditEvent::STAFF_SIGN_IN_FAILED,
            'failure',
            ['reason' => $reason, 'email' => $email],
            'staff',
            $staff?->staff_id,
            $ctx,
        );

        throw AuthApiException::invalidCredentials();
    }
}
