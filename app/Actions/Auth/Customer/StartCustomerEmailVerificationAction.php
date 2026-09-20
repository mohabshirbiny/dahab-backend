<?php

namespace App\Actions\Auth\Customer;

use App\Enums\AuthErrorCode;
use App\Enums\Governorate;
use App\Exceptions\AuthApiException;
use App\Models\Customer;
use App\Notifications\CustomerRegistrationEmailOtpNotification;
use App\Services\CustomerRegistrationSessionStore;
use App\Support\RequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Step 3 of registration: record the applicant's email + governorate on the
 * cached session and dispatch an email OTP. Requires the phone step has
 * completed. Re-checks email uniqueness against `customer` here — it is
 * re-checked again at submit time because a concurrent registration may
 * take the address between now and then.
 */
final class StartCustomerEmailVerificationAction
{
    public function __construct(
        private readonly CustomerRegistrationSessionStore $sessions,
    ) {}

    /**
     * @param  array{email: string, governorate: string}  $input
     * @return array{expires_at: Carbon, otp_expires_at: Carbon}
     */
    public function execute(string $ref, array $input, RequestContext $ctx): array
    {
        $lock = $this->sessions->lock($ref);

        if (! $lock->get()) {
            throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'Registration already in progress.');
        }

        try {
            $session = $this->sessions->find($ref);

            if ($session === null) {
                throw new AuthApiException(
                    AuthErrorCode::REGISTRATION_SESSION_INVALID,
                    410,
                    'This registration session is no longer valid. Start again.',
                );
            }

            if (($session['phone_verified'] ?? false) !== true) {
                throw new AuthApiException(
                    AuthErrorCode::REGISTRATION_PHONE_UNVERIFIED,
                    409,
                    'Verify the phone number before adding an email.',
                );
            }

            if (Customer::query()->where('email', $input['email'])->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['This email address is already registered.'],
                ]);
            }

            Governorate::from($input['governorate']);

            $code = $this->newOtpCode();
            $otpExpiresAt = now()->addSeconds((int) config('dahab-auth.otp.ttl_seconds'));

            $session['email'] = $input['email'];
            $session['governorate'] = $input['governorate'];
            $session['email_verified'] = false;
            $session['email_otp_hash'] = Hash::make($code);
            $session['email_otp_expires_at'] = $otpExpiresAt->toIso8601String();
            $session['email_otp_attempts'] = 0;

            $this->sessions->save($ref, $session);

            Notification::route('mail', $input['email'])
                ->notify(new CustomerRegistrationEmailOtpNotification($code, $session['preferred_lang'] ?? 'ar'));

            Log::info('auth.customer.registration_email_otp_sent', [
                'ip' => $ctx->ip,
                'device' => $ctx->deviceFingerprintHash,
            ]);

            return [
                'expires_at' => Carbon::parse($session['expires_at']),
                'otp_expires_at' => $otpExpiresAt,
            ];
        } finally {
            $lock->release();
        }
    }

    private function newOtpCode(): string
    {
        $length = (int) config('dahab-auth.otp.code_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
