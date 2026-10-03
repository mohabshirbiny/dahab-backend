<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Loads one customer with everything the Customer file shows (spec 007):
 * every identity document newest first, its reviewer and the suspender, and
 * (spec 013) the payout accounts with their checker — one query per relation,
 * however many rows. Not audited by itself.
 */
final class CustomerFileLoader
{
    public function load(string $customerId): Customer
    {
        return Customer::query()
            ->with([
                'identityDocuments' => fn ($q) => $q->orderByDesc('created_at'),
                'identityDocuments.reviewer:staff_id,full_name',
                'suspender:staff_id,full_name',
                'payoutAccounts' => fn ($q) => $q->orderByDesc('is_in_use')->orderByDesc('created_at'),
                'payoutAccounts.checkedBy:staff_id,full_name',
            ])
            ->findOrFail($customerId);
    }
}
