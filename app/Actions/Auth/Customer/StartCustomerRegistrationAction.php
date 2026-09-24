<?php

namespace App\Actions\Auth\Customer;

use App\Notifications\CustomerRegistrationOtpNotification;
use App\Services\CustomerRegistrationSessionStore;
use App\Support\RequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Step 1 of the six-step registration: accept name, phone and password
 * (with a confirmation the FormRequest already checked), stash the hashed
 * credentials on an encrypted cache-backed session, and send a phone OTP.
 *
 * Writes nothing to the database. There is no customer row and no draft
 * row at the end of this step — only the opaque `registration_ref` that
 * the client hands back to every later step.
 *
 * The password is hashed here so the plaintext never sits in the cache
 * and never has to be re-sent at the final submit step (docs Part 2 §7).
 */
final class StartCustomerRegistrationAction
{
    public function __construct(
        private readonly CustomerRegistrationSessionStore $sessions,
    ) {}

    /**
     * @param  array{name: string, phone: string, password: string, preferred_lang?: string}  $input
     * @return array{ref: string, expires_at: Carbon, otp_expires_at: Carbon}
     */
    public function execute(array $input, RequestContext $ctx): array
    {
        $code = $this->newOtpCode();
        $otpExpiresAt = now()->addSeconds((int) config('dahab-auth.otp.ttl_seconds'));

        $session = $this->sessions->begin([
            'full_name' => $input['name'],
            'phone' => $input['phone'],
            'password_hash' => Hash::make($input['password']),
            'preferred_lang' => $input['preferred_lang'] ?? 'ar',
            'phone_verified' => false,
            'email' => null,
            'email_verified' => false,
            'governorate' => null,
            'identity_doc_kind' => null,
            'identity_front_ref' => null,
            'identity_back_ref' => null,
            'phone_otp_hash' => Hash::make($code),
            'phone_otp_expires_at' => $otpExpiresAt->toIso8601String(),
            'phone_otp_attempts' => 0,
            'email_otp_hash' => null,
            'email_otp_expires_at' => null,
            'email_otp_attempts' => 0,
            'device_fingerprint_hash' => $ctx->deviceFingerprintHash,
            'submitted_at' => null,
        ]);

        Notification::route('sms', $input['phone'])
            ->notify(new CustomerRegistrationOtpNotification($code, $input['preferred_lang'] ?? 'ar'));

        Log::info('auth.customer.registration_started', [
            'ip' => $ctx->ip,
            'device' => $ctx->deviceFingerprintHash,
        ]);

        return [
            'ref' => $session['ref'],
            'expires_at' => $session['expires_at'],
            'otp_expires_at' => $otpExpiresAt,
        ];
    }

    private function newOtpCode(): string
    {

        if(app()->environment('development', 'local')) {
            return '123456';
        }

        $length = (int) config('dahab-auth.otp.code_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
