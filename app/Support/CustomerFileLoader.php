<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Loads one customer with everything the Customer file shows (spec 007):
 * every identity document newest first, its reviewer and the suspender —
 * one query per relation, however many documents. Not audited by itself.
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
            ])
            ->findOrFail($customerId);
    }
}
