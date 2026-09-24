<?php

namespace App\Actions\Auth\Customer;

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\AuthErrorCode;
use App\Exceptions\AuthApiException;
use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use App\Services\CustomerLoginChallengeStore;
use App\Support\RequestContext;
use App\Support\SessionDto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Releases a sign-in held for a new device (Part 1 §2.3): checks the SMS code,
 * records the device as trusted and issues the session.
 *
 * Wrong code → 401 `otp_invalid` and the attempt is counted; after
 * `otp.max_verify_attempts` wrong codes the challenge is voided, so even the
 * right code then returns `otp_invalid`. An expired code → 401 `otp_expired`.
 */
final class VerifyCustomerLoginOtpAction
{
    public function __construct(
        private readonly CustomerLoginChallengeStore $challenges,
        private readonly AssertCustomerCanSignIn $gate,
        private readonly IssueTokenFamilyAction $issueTokens,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{customer: Customer, session: SessionDto} */
    public function execute(string $challengeId, string $code, RequestContext $ctx): array
    {
        $lock = $this->challenges->lock($challengeId);

        if (! $lock->get()) {
            throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'Verification already in progress.');
        }

        try {
            $challenge = $this->challenges->find($challengeId);

            // Unknown, consumed, voided, or presented from another device.
            if ($challenge === null || $challenge['fingerprint_hash'] !== $ctx->deviceFingerprintHash) {
                throw $this->invalid();
            }

            $customerId = $challenge['customer_id'];
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
                    AuditEvent::CUSTOMER_OTP_FAILED,
                    'failure',
                    ['purpose' => 'new_device_sign_in', 'attempts' => $challenge['verify_attempts']],
                    'customer',
                    $customerId,
                    RequestContext::forCustomer(request(), $customerId, $ctx->deviceFingerprintHash),
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

            $customer = Customer::query()->findOrFail($customerId);
            $this->gate->execute($customer);

            $session = DB::transaction(function () use ($customer, $ctx) {
                $device = CustomerTrustedDevice::query()->firstOrNew([
                    'customer_id' => $customer->customer_id,
                    'fingerprint_hash' => $ctx->deviceFingerprintHash,
                ]);
                $device->first_seen_at ??= now();
                $device->last_seen_at = now();
                $device->save();

                return $this->issueTokens->forCustomer($customer);
            });

            $auditCtx = RequestContext::forCustomer(request(), $customer->customer_id, $ctx->deviceFingerprintHash);
            $this->audit->execute(AuditEvent::CUSTOMER_OTP_VERIFIED, 'success', ['purpose' => 'new_device_sign_in'], 'customer', $customer->customer_id, $auditCtx);
            $this->audit->execute(AuditEvent::CUSTOMER_SIGN_IN, 'success', ['via' => 'new_device_otp'], 'customer', $customer->customer_id, $auditCtx);

            return ['customer' => $customer, 'session' => $session];
        } finally {
            $lock->release();
        }
    }

    private function invalid(): AuthApiException
    {
        return new AuthApiException(AuthErrorCode::OTP_INVALID, 401, 'The sign-in code is incorrect.');
    }
}
