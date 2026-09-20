<?php

namespace App\Actions\Auth\Customer;

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\AuthErrorCode;
use App\Enums\CustomerStatus;
use App\Exceptions\AuthApiException;
use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use App\Support\RequestContext;
use App\Support\SessionDto;
use Illuminate\Support\Facades\Hash;

final class LoginCustomerAction
{
    public function __construct(
        private readonly IssueTokenFamilyAction $issueTokens,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @return array{customer: Customer, session: SessionDto}|array{otp_required: true}
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

        // Lifecycle gate: only ACTIVE customers may open a session. A pending
        // applicant must wait for staff approval; a rejected customer must
        // re-register; a suspended customer gets the existing `account_suspended`
        // shape with its stored reason.
        $this->assertLifecycleAllowsLogin($customer);

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

        // MVP scope (US1): device unknown => refuse; US3 wires the OTP branch.
        $this->audit->execute(
            AuditEvent::CUSTOMER_SIGN_IN_FAILED,
            'failure',
            ['reason' => 'unknown_device', 'phone' => $phone],
            'customer',
            $customer->customer_id,
            RequestContext::forCustomer(request(), $customer->customer_id, $ctx->deviceFingerprintHash),
        );

        throw AuthApiException::invalidCredentials();
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

    private function assertLifecycleAllowsLogin(Customer $customer): void
    {
        match ($customer->status) {
            CustomerStatus::ACTIVE => null,
            CustomerStatus::PENDING_VERIFICATION => throw new AuthApiException(
                AuthErrorCode::ACCOUNT_PENDING_VERIFICATION,
                403,
                'This account is waiting for verification.',
            ),
            CustomerStatus::REJECTED => throw new AuthApiException(
                AuthErrorCode::ACCOUNT_REJECTED,
                403,
                'This account was rejected during verification.',
            ),
            CustomerStatus::SUSPENDED => throw AuthApiException::accountSuspended(
                $customer->suspended_reason?->value,
            ),
        };
    }
}
