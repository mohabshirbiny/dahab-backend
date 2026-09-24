<?php

namespace App\Actions\Auth\Customer;

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use App\Support\RequestContext;
use App\Support\SessionDto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

final class LoginCustomerAction
{
    public function __construct(
        private readonly IssueTokenFamilyAction $issueTokens,
        private readonly RecordAuditLogAction $audit,
        private readonly AssertCustomerCanSignIn $gate,
        private readonly CustomerLoginChallengeAction $challenge,
    ) {}

    /**
     * Known device → a session. New device → a challenge; the session is
     * issued by VerifyCustomerLoginOtpAction once the SMS code is confirmed.
     *
     * @return array{customer: Customer, session: SessionDto}|array{challenge: array{challenge_id: string, expires_at: Carbon, resend_available_at: Carbon}}
     */
    public function execute(string $phone, string $password, RequestContext $ctx): array
    {
        $customer = Customer::query()->where('phone', $phone)->with('password')->first();

        if ($customer === null || $customer->password === null) {
            // Same shape as wrong-password; no audit row (no actor to attribute to).
            $this->refuse();
        }

        if (! Hash::check($password, $customer->password->password_hash)) {
            $this->audit->execute(
                AuditEvent::CUSTOMER_SIGN_IN_FAILED,
                'failure',
                ['phone' => $phone],
                'customer',
                $customer->customer_id,
                RequestContext::forCustomer(request(), $customer->customer_id, $ctx->deviceFingerprintHash),
            );
            $this->refuse();
        }

        if (Hash::needsRehash($customer->password->password_hash)) {
            $customer->password->update([
                'password_hash' => Hash::make($password),
                'password_changed_at' => now(),
            ]);
        }

        $this->gate->execute($customer);

        if ($ctx->deviceFingerprintHash !== null && $this->deviceIsTrusted($customer->customer_id, $ctx->deviceFingerprintHash)) {
            CustomerTrustedDevice::query()
                ->where('customer_id', $customer->customer_id)
                ->where('fingerprint_hash', $ctx->deviceFingerprintHash)
                ->update(['last_seen_at' => now()]);

            $session = $this->issueTokens->forCustomer($customer);

            $this->audit->execute(
                AuditEvent::CUSTOMER_SIGN_IN,
                'success',
                ['via' => 'known_device'],
                'customer',
                $customer->customer_id,
                RequestContext::forCustomer(request(), $customer->customer_id, $ctx->deviceFingerprintHash),
            );

            return ['customer' => $customer, 'session' => $session];
        }

        // A request with no X-Device-Id cannot be trusted later, so there is
        // nothing to challenge: refuse with the usual shape.
        if ($ctx->deviceFingerprintHash === null) {
            $this->audit->execute(
                AuditEvent::CUSTOMER_SIGN_IN_FAILED,
                'failure',
                ['reason' => 'missing_device_id', 'phone' => $phone],
                'customer',
                $customer->customer_id,
                RequestContext::forCustomer(request(), $customer->customer_id, null),
            );

            throw AuthApiException::invalidCredentials();
        }

        // New device (Part 1 §2.3): hold the session and send a code to the
        // customer's phone; POST /customer/auth/otp/verify releases it.
        return ['challenge' => $this->challenge->issue($customer, $ctx)];
    }

    private function deviceIsTrusted(string $customerId, string $fingerprintHash): bool
    {
        return CustomerTrustedDevice::query()
            ->where('customer_id', $customerId)
            ->where('fingerprint_hash', $fingerprintHash)
            ->exists();
    }

    private function refuse(): never
    {
        throw AuthApiException::invalidCredentials();
    }
}
