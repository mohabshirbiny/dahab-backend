<?php

namespace App\Actions\Auth\Customer;

use App\Enums\AuthErrorCode;
use App\Exceptions\AuthApiException;
use App\Services\CustomerRegistrationSessionStore;
use App\Support\RequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Step 4 of registration: prove control of the email address by verifying the
 * mailed OTP. Same idempotent, attempt-limited shape as the phone step.
 */
final class VerifyCustomerEmailOtpAction
{
    public function __construct(
        private readonly CustomerRegistrationSessionStore $sessions,
    ) {}

    /** @return array{expires_at: Carbon} */
    public function execute(string $ref, string $otp, RequestContext $ctx): array
    {
        $lock = $this->sessions->lock($ref);

        if (! $lock->get()) {
            throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'Verification already in progress.');
        }

        try {
            $session = $this->sessions->find($ref);

            if ($session === null) {
                throw $this->sessionInvalid();
            }

            if (($session['phone_verified'] ?? false) !== true) {
                throw new AuthApiException(
                    AuthErrorCode::REGISTRATION_PHONE_UNVERIFIED,
                    409,
                    'Verify the phone number first.',
                );
            }

            if (empty($session['email']) || empty($session['email_otp_hash'])) {
                throw new AuthApiException(
                    AuthErrorCode::REGISTRATION_EMAIL_UNVERIFIED,
                    409,
                    'Submit the email step before verifying the email OTP.',
                );
            }

            if (($session['email_verified'] ?? false) === true) {
                return ['expires_at' => Carbon::parse($session['expires_at'])];
            }

            if (Carbon::parse($session['email_otp_expires_at'])->isPast()) {
                throw new AuthApiException(AuthErrorCode::OTP_EXPIRED, 401, 'The verification code has expired.');
            }

            $maxAttempts = (int) config('dahab-auth.otp.max_verify_attempts');

            if (($session['email_otp_attempts'] ?? 0) >= $maxAttempts) {
                $this->sessions->forget($ref);
                throw $this->sessionInvalid();
            }

            if (! Hash::check($otp, $session['email_otp_hash'])) {
                $session['email_otp_attempts'] = ($session['email_otp_attempts'] ?? 0) + 1;
                $this->sessions->save($ref, $session);

                Log::info('auth.customer.registration_otp_failed', [
                    'channel' => 'email',
                    'attempts' => $session['email_otp_attempts'],
                    'ip' => $ctx->ip,
                ]);

                if ($session['email_otp_attempts'] >= $maxAttempts) {
                    $this->sessions->forget($ref);
                    throw $this->sessionInvalid();
                }

                throw new AuthApiException(AuthErrorCode::OTP_INVALID, 401, 'The verification code is incorrect.');
            }

            $session['email_verified'] = true;
            $session['email_otp_hash'] = null;
            $session['email_otp_attempts'] = 0;

            $this->sessions->save($ref, $session);

            return ['expires_at' => Carbon::parse($session['expires_at'])];
        } finally {
            $lock->release();
        }
    }

    private function sessionInvalid(): AuthApiException
    {
        return new AuthApiException(
            AuthErrorCode::REGISTRATION_SESSION_INVALID,
            410,
            'This registration session is no longer valid. Start again.',
        );
    }
}
