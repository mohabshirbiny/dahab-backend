<?php

namespace App\Actions\Auth\Customer;

use App\Enums\AuthErrorCode;
use App\Enums\CustomerStatus;
use App\Exceptions\AuthApiException;
use App\Models\Customer;

/**
 * Lifecycle gate: only ACTIVE customers may open a session. A pending
 * applicant must wait for staff approval; a rejected customer must
 * re-register; a suspended customer gets the existing `account_suspended`
 * shape with its stored reason.
 *
 * Checked at password sign-in and again when a new-device OTP is verified,
 * because the status can change while a challenge is outstanding.
 */
final class AssertCustomerCanSignIn
{
    public function execute(Customer $customer): void
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
