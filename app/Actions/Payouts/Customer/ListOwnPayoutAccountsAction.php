<?php

namespace App\Actions\Payouts\Customer;

use App\Enums\PayoutAccountState;
use App\Enums\WithdrawalState;
use App\Models\PayoutAccount;
use App\Models\PayoutAccountChange;
use App\Models\Withdrawal;
use App\Models\WithdrawalPause;
use Illuminate\Support\Collection;

/**
 * Bank accounts (spec 013 FR-002): the customer's accounts (not removed),
 * the open pause and the recent changes. Runs in the customer's own scope:
 * row-level security returns only their rows.
 */
final class ListOwnPayoutAccountsAction
{
    public const RECENT = 20;

    /** @return array{accounts: Collection<int, PayoutAccount>, pause: ?WithdrawalPause, changes: Collection<int, PayoutAccountChange>, open_by_account: array<string, int>} */
    public function handle(string $customerId): array
    {
        $accounts = PayoutAccount::query()->where('customer_id', $customerId)
            ->where('state', '<>', PayoutAccountState::REMOVED->value)
            ->orderByDesc('is_in_use')->orderBy('created_at')->get();

        $open = Withdrawal::query()->where('customer_id', $customerId)
            ->whereIn('state', WithdrawalState::openValues())
            ->selectRaw('payout_account_id, count(*) AS n')->groupBy('payout_account_id')
            ->pluck('n', 'payout_account_id')->map(fn ($n) => (int) $n)->all();

        $changes = PayoutAccountChange::query()->with('account')->where('customer_id', $customerId)
            ->orderByDesc('change_id')->limit(self::RECENT)->get();

        return [
            'accounts' => $accounts,
            'pause' => WithdrawalPause::openFor($customerId),
            'changes' => $changes,
            'open_by_account' => $open,
        ];
    }
}
