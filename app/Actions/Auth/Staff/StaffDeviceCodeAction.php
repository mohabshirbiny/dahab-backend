<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\AuthErrorCode;
use App\Exceptions\AuthApiException;
use App\Models\Staff;
use App\Notifications\CustomerLoginOtpNotification;
use App\Services\StaffDeviceCodeStore;
use App\Support\RequestContext;
use App\Support\SessionDto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * A staff sign-in from a device not yet known for them, held until the code sent by SMS to the
 * staff phone is typed (setting `auth.staff_new_device_sms`, docs/features/settings-page-and-relist.md).
 * The customer new-device code (Part 1 §2.3) is the model: the challenge lives in the cache, is
 * bound to the device that asked, honours `otp.*` (expiry, 5 tries, resend cooldown and hourly cap)
 * and the device becomes known only when the right code arrives.
 *
 * A founder reaches this only for their first device; a later new device of a founder still waits
 * for the other founder (FounderDeviceGate). Confirming a founder's first device alerts the founders.
 */
final class StaffDeviceCodeAction
{
    public function __construct(
        private readonly StaffDeviceCodeStore $challenges,
        private readonly CompleteStaffSignInAction $complete,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{device_code_required: true, challenge_id: string, phone_hint: string, expires_at: string, resend_available_at: string} */
    public function issue(Staff $staff, RequestContext $ctx): array
    {
        $code = $this->newCode();
        $codeExpiresAt = now()->addSeconds((int) config('dahab-auth.otp.ttl_seconds'));

        $challenge = $this->challenges->begin([
            'staff_id' => $staff->staff_id,
            'fingerprint_hash' => $ctx->deviceFingerprintHash,
            'code_hash' => Hash::make($code),
            'code_expires_at' => $codeExpiresAt->toIso8601String(),
            'verify_attempts' => 0,
            'sends' => 1,
            'last_sent_at' => now()->toIso8601String(),
        ]);

        $this->send($staff, $code, $ctx);

        return $this->answer($challenge['id'], $staff, $codeExpiresAt);
    }

    /** @return array{device_code_required: true, challenge_id: string, phone_hint: string, expires_at: string, resend_available_at: string} */
    public function resend(string $challengeId, RequestContext $ctx): array
    {
        return $this->locked($challengeId, function () use ($challengeId, $ctx) {
            $challenge = $this->challenge($challengeId, $ctx);

            $availableAt = $this->resendAvailableAt(Carbon::parse($challenge['last_sent_at']));
            if ($availableAt->isFuture()) {
                $wait = (int) ceil(now()->diffInSeconds($availableAt, true));
                throw new AuthApiException(
                    AuthErrorCode::TOO_MANY_REQUESTS,
                    429,
                    'Wait before asking for another code.',
                    ['resend_available_at' => $availableAt->toIso8601String()],
                    ['Retry-After' => (string) max($wait, 1)],
                );
            }

            if (($challenge['sends'] ?? 0) >= (int) config('dahab-auth.otp.max_sends_per_hour')) {
                throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'Too many codes sent. Try again later.');
            }

            $staff = $this->available($challengeId, $challenge, $ctx);
            $code = $this->newCode();
            $codeExpiresAt = now()->addSeconds((int) config('dahab-auth.otp.ttl_seconds'));

            $challenge['code_hash'] = Hash::make($code);
            $challenge['code_expires_at'] = $codeExpiresAt->toIso8601String();
            $challenge['verify_attempts'] = 0;
            $challenge['sends'] = ($challenge['sends'] ?? 0) + 1;
            $challenge['last_sent_at'] = now()->toIso8601String();
            $this->challenges->save($challengeId, $challenge);

            $this->send($staff, $code, $ctx);

            return $this->answer($challengeId, $staff, $codeExpiresAt);
        });
    }

    /**
     * Wrong code → 401 `otp_invalid`, counted; after `otp.max_verify_attempts` the challenge is
     * void. Expired → 401 `otp_expired`. Right code → the device is known and the session issued.
     *
     * @return array{staff: Staff, session: SessionDto}
     */
    public function verify(string $challengeId, string $code, RequestContext $ctx): array
    {
        return $this->locked($challengeId, function () use ($challengeId, $code, $ctx) {
            $challenge = $this->challenge($challengeId, $ctx);
            $maxAttempts = (int) config('dahab-auth.otp.max_verify_attempts');

            if (($challenge['verify_attempts'] ?? 0) >= $maxAttempts) {
                $this->challenges->forget($challengeId);
                throw $this->invalid();
            }

            if (Carbon::parse($challenge['code_expires_at'])->isPast()) {
                throw new AuthApiException(AuthErrorCode::OTP_EXPIRED, 401, 'The sign-in code has expired.');
            }

            if (! Hash::check($code, $challenge['code_hash'])) {
                $challenge['verify_attempts'] = ($challenge['verify_attempts'] ?? 0) + 1;

                $this->audit->execute(
                    AuditEvent::STAFF_DEVICE_CODE_FAILED,
                    'failure',
                    ['attempts' => $challenge['verify_attempts']],
                    'staff',
                    $challenge['staff_id'],
                    $ctx->withStaff($challenge['staff_id']),
                );

                if ($challenge['verify_attempts'] >= $maxAttempts) {
                    $this->challenges->forget($challengeId);
                } else {
                    $this->challenges->save($challengeId, $challenge);
                }

                throw $this->invalid();
            }

            // The code is spent whatever happens next.
            $this->challenges->forget($challengeId);
            $staff = $this->available($challengeId, $challenge, $ctx);
            $firstFounderDevice = $staff->is_founder && ! $staff->deviceFingerprints()->exists();

            $session = $this->complete->execute($staff, $ctx, 'device_code');

            if ($firstFounderDevice) {
                $this->audit->execute(
                    AuditEvent::FOUNDER_DEVICE_TRUSTED_BY_SMS,
                    'success',
                    ['device_ref' => substr((string) $ctx->deviceFingerprintHash, 0, 8), 'platform' => (string) request()->header('X-Device-Platform', 'web')],
                    'staff',
                    $staff->staff_id,
                    $ctx->withStaff($staff->staff_id),
                );
            }

            return ['staff' => $staff, 'session' => $session];
        });
    }

    /** @return array<string, mixed> the challenge, refused when unknown, used, void or from another device */
    private function challenge(string $challengeId, RequestContext $ctx): array
    {
        $challenge = $this->challenges->find($challengeId);

        if ($challenge === null || $challenge['fingerprint_hash'] !== $ctx->deviceFingerprintHash) {
            throw $this->invalid();
        }

        return $challenge;
    }

    /** The account may have been disabled or frozen since the password step. */
    private function available(string $challengeId, array $challenge, RequestContext $ctx): Staff
    {
        $staff = Staff::query()->find($challenge['staff_id']);

        if ($staff === null || ! $staff->is_active || $staff->activeFreeze()->exists() || blank($staff->phone)) {
            $this->challenges->forget($challengeId);
            throw $this->invalid();
        }

        return $staff;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function locked(string $challengeId, callable $run): mixed
    {
        $lock = $this->challenges->lock($challengeId);

        if (! $lock->get()) {
            throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'A request for this code is already in progress.');
        }

        try {
            return $run();
        } finally {
            $lock->release();
        }
    }

    private function send(Staff $staff, string $code, RequestContext $ctx): void
    {
        // The customer's new-device wording fits staff too; staff texts are English.
        Notification::route('sms', $staff->phone)->notify(new CustomerLoginOtpNotification($code, 'en'));

        $this->audit->execute(
            AuditEvent::STAFF_DEVICE_CODE_SENT,
            'success',
            ['channel' => 'sms'],
            'staff',
            $staff->staff_id,
            $ctx->withStaff($staff->staff_id),
        );
    }

    /** @return array{device_code_required: true, challenge_id: string, phone_hint: string, expires_at: string, resend_available_at: string} */
    private function answer(string $challengeId, Staff $staff, Carbon $codeExpiresAt): array
    {
        return [
            'device_code_required' => true,
            'challenge_id' => $challengeId,
            'phone_hint' => self::phoneHint((string) $staff->phone),
            'expires_at' => $codeExpiresAt->toIso8601String(),
            'resend_available_at' => $this->resendAvailableAt(now())->toIso8601String(),
        ];
    }

    /** The last three digits only: "••• 123". */
    public static function phoneHint(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return '••• '.substr($digits, -3);
    }

    private function resendAvailableAt(Carbon $lastSentAt): Carbon
    {
        return $lastSentAt->copy()->addSeconds((int) config('dahab-auth.otp.send_cooldown_seconds'));
    }

    private function invalid(): AuthApiException
    {
        return new AuthApiException(AuthErrorCode::OTP_INVALID, 401, 'The sign-in code is incorrect.');
    }

    private function newCode(): string
    {
        // Same local-only fixed code as the customer codes.
        if (app()->environment('development', 'local')) {
            return '123456';
        }

        $length = (int) config('dahab-auth.otp.code_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
