<?php

namespace App\Actions\Dashboard;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The "Users and Verification" list. `status` matches the four dashboard
 * tabs (Waiting/Verified/Rejected/Suspended). The latest identity document
 * is eager-loaded so the UI can show doc_kind + submission date without a
 * per-row round trip.
 */
final class ListCustomersForVerificationAction
{
    /** @return LengthAwarePaginator<int, Customer> */
    public function handle(CustomerStatus $status, int $perPage): LengthAwarePaginator
    {
        return Customer::query()
            ->where('status', $status->value)
            ->with(['identityDocuments' => function ($q) {
                $q->orderByDesc('created_at')->limit(1);
            }])
            ->orderBy('created_at')
            ->paginate($perPage);
    }
}
