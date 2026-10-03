<?php

namespace App\Actions\Withdrawals\Customer;

use App\Enums\WithdrawalState;
use App\Models\Withdrawal;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Illuminate\Support\Collection;

/**
 * The customer's own withdrawals, newest first, keyset pages (spec 013
 * US4/US5). Row-level security returns only their rows.
 */
final class ListOwnWithdrawalsAction
{
    /** @return array{rows: Collection<int, Withdrawal>, next_cursor: string|null} */
    public function handle(string $customerId, ?string $group, ?ListingCursor $cursor, int $perPage): array
    {
        $query = Withdrawal::query()->with('account')->where('withdrawal.customer_id', $customerId)
            ->when($group === 'open', fn ($q) => $q->whereIn('withdrawal.state', WithdrawalState::openValues()))
            ->when($group === 'closed', fn ($q) => $q->whereNotIn('withdrawal.state', WithdrawalState::openValues()));

        return KeysetPage::byTime($query, 'withdrawal', 'requested_at', 'withdrawal_id', true, $cursor, $perPage);
    }
}
