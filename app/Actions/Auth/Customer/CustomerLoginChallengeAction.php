<?php

namespace App\Actions\Auth\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\AuthErrorCode;
use App\Exceptions\AuthApiException;
use App\Models\Customer;
use App\Notifications\CustomerLoginOtpNotification;
use App\Services\CustomerLoginChallengeStore;
use App\Support\RequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Opens and re-sends the new-device sign-in challenge (Part 1 §2.3).
 *
 * `issue()` runs after a correct phone + password from an untrusted device:
 * it holds the session, texts a code to the customer's phone and returns the
 * opaque `challenge_id`. `resend()` sends a fresh code on the same challenge,
 * honouring `otp.send_cooldown_seconds` and `otp.max_sends_per_hour`.
 *
 * The challenge is bound to the device fingerprint that asked for it; it can
 * only be verified or resent from that same device.
 */
final class CustomerLoginChallengeAction
{
    public function __construct(
        private readonly CustomerLoginChallengeStore $challenges,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{challenge_id: string, expires_at: Carbon, resend_available_at: Carbon} */
    public function issue(Customer $customer, RequestContext $ctx): array
    {
        $code = $this->newOtpCode();
        $codeExpiresAt = now()->addSeconds((int) config('dahab-auth.otp.ttl_seconds'));

        $challenge = $this->challenges->begin([
            'customer_id' => $customer->customer_id,
            'fingerprint_hash' => $ctx->deviceFingerprintHash,
            'code_hash' => Hash::make($code),
            'code_expires_at' => $codeExpiresAt->toIso8601String(),
            'verify_attempts' => 0,
            'sends' => 1,
            'last_sent_at' => now()->toIso8601String(),
        ]);

        $this->send($customer, $code, $ctx);

        return [
            'challenge_id' => $challenge['id'],
            'expires_at' => $codeExpiresAt,
            'resend_available_at' => $this->resendAvailableAt(now()),
        ];
    }

    /** @return array{challenge_id: string, expires_at: Carbon, resend_available_at: Carbon} */
    public function resend(string $challengeId, RequestContext $ctx): array
    {
        $lock = $this->challenges->lock($challengeId);

        if (! $lock->get()) {
            throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'A request for this code is already in progress.');
        }

        try {
            $challenge = $this->challenges->find($challengeId);

            if ($challenge === null || $challenge['fingerprint_hash'] !== $ctx->deviceFingerprintHash) {
                throw new AuthApiException(AuthErrorCode::OTP_INVALID, 401, 'This sign-in code is no longer valid. Sign in again.');
            }

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

            $customer = Customer::query()->findOrFail($challenge['customer_id']);
            $code = $this->newOtpCode();
            $codeExpiresAt = now()->addSeconds((int) config('dahab-auth.otp.ttl_seconds'));

            $challenge['code_hash'] = Hash::make($code);
            $challenge['code_expires_at'] = $codeExpiresAt->toIso8601String();
            $challenge['verify_attempts'] = 0;
            $challenge['sends'] = ($challenge['sends'] ?? 0) + 1;
            $challenge['last_sent_at'] = now()->toIso8601String();
            $this->challenges->save($challengeId, $challenge);

            $this->send($customer, $code, $ctx);

            return [
                'challenge_id' => $challengeId,
                'expires_at' => $codeExpiresAt,
                'resend_available_at' => $this->resendAvailableAt(now()),
            ];
        } finally {
            $lock->release();
        }
    }

    private function send(Customer $customer, string $code, RequestContext $ctx): void
    {
        Notification::route('sms', $customer->phone)
            ->notify(new CustomerLoginOtpNotification($code, $customer->preferred_lang ?? 'ar'));

        $this->audit->execute(
            AuditEvent::CUSTOMER_OTP_SENT,
            'success',
            ['channel' => 'sms', 'purpose' => 'new_device_sign_in'],
            'customer',
            $customer->customer_id,
            RequestContext::forCustomer(request(), $customer->customer_id, $ctx->deviceFingerprintHash),
        );
    }

    private function resendAvailableAt(Carbon $lastSentAt): Carbon
    {
        return $lastSentAt->copy()->addSeconds((int) config('dahab-auth.otp.send_cooldown_seconds'));
    }

    private function newOtpCode(): string
    {
        // Same local-only fixed code as the registration OTP actions.
        if (app()->environment('development', 'local')) {
            return '123456';
        }

        $length = (int) config('dahab-auth.otp.code_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
