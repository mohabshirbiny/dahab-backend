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
 * Step 2 of registration: prove the applicant controls the phone number.
 *
 * Still writes nothing to the database. On success it only flips
 * `phone_verified` on the cached session, which is what the later steps
 * require. Idempotent — a retried verify on an already-verified session
 * succeeds unchanged, so a duplicate submit is not an error.
 */
final class VerifyCustomerRegistrationOtpAction
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

            if (($session['phone_verified'] ?? false) === true) {
                return ['expires_at' => Carbon::parse($session['expires_at'])];
            }

            if (Carbon::parse($session['phone_otp_expires_at'])->isPast()) {
                throw new AuthApiException(AuthErrorCode::OTP_EXPIRED, 401, 'The verification code has expired.');
            }

            $maxAttempts = (int) config('dahab-auth.otp.max_verify_attempts');

            if (($session['phone_otp_attempts'] ?? 0) >= $maxAttempts) {
                $this->sessions->forget($ref);
                throw $this->sessionInvalid();
            }

            if (! Hash::check($otp, $session['phone_otp_hash'])) {
                $session['phone_otp_attempts'] = ($session['phone_otp_attempts'] ?? 0) + 1;
                $this->sessions->save($ref, $session);

                Log::info('auth.customer.registration_otp_failed', [
                    'channel' => 'sms',
                    'attempts' => $session['phone_otp_attempts'],
                    'ip' => $ctx->ip,
                ]);

                if ($session['phone_otp_attempts'] >= $maxAttempts) {
                    $this->sessions->forget($ref);
                    throw $this->sessionInvalid();
                }

                throw new AuthApiException(AuthErrorCode::OTP_INVALID, 401, 'The verification code is incorrect.');
            }

            $session['phone_verified'] = true;
            $session['phone_otp_hash'] = null;
            $session['phone_otp_attempts'] = 0;

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
