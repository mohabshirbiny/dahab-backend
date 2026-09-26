<?php

namespace App\Actions\Auth\Customer;

use App\Enums\CustomerStatus;
use App\Models\Customer;

/**
 * Lifecycle gate at sign-in. Since spec 002 (product-owner decision
 * 2026-09-26) verification does not gate authentication:
 *
 * - pending_verification / rejected customers sign in so they can see their
 *   status and finish (or redo) verification — every other action is
 *   refused by the `customer.gate` middleware with `verification_required`;
 * - suspended customers sign in to read their own data and wind down
 *   (Part 1 §2.2); trade actions are refused with `account_suspended`.
 *
 * Kept as the single place a future closed/banned status would be refused.
 * Checked at password sign-in and again when a new-device OTP is verified.
 */
final class AssertCustomerCanSignIn
{
    public function execute(Customer $customer): void
    {
        match ($customer->status) {
            CustomerStatus::ACTIVE,
            CustomerStatus::PENDING_VERIFICATION,
            CustomerStatus::REJECTED,
            CustomerStatus::SUSPENDED => null,
        };
    }
}
