<?php

namespace App\Support\Withdrawals;

use App\Enums\CustomerStatus;
use App\Enums\PayoutAccountChangeKind;
use App\Enums\WithdrawalState;
use App\Models\Withdrawal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Before you release" (spec 013 Clarifications, research R8): facts the
 * platform already has, for a page of withdrawals, in a fixed number of
 * queries whatever the page size (no N+1). Read in the staff scope.
 * Open disputes and "weight short" are not built (no such modules).
 */
final class WithdrawalSignals
{
    /**
     * @param  Collection<int, Withdrawal>  $rows  with `customer` and `account` loaded
     * @return array<string, array<string, mixed>> keyed by withdrawal id
     */
    public function for(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $customers = $rows->pluck('customer_id')->unique()->values()->all();
        $accounts = $rows->pluck('payout_account_id')->unique()->values()->all();

        $released = DB::table('withdrawal')->whereIn('payout_account_id', $accounts)
            ->whereIn('state', [WithdrawalState::RELEASED->value, WithdrawalState::SETTLED->value])
            ->selectRaw('payout_account_id, count(*) AS n')->groupBy('payout_account_id')->pluck('n', 'payout_account_id');

        $inUse = DB::table('payout_account_change')->whereIn('customer_id', $customers)
            ->where('kind', PayoutAccountChangeKind::IN_USE->value)
            ->selectRaw('customer_id, count(*) AS n, max(created_at) AS last_at')->groupBy('customer_id')->get()->keyBy('customer_id');

        $sales = DB::table('order')->whereIn('seller_id', $customers)->whereIn('state', ['ready_to_collect', 'completed'])
            ->selectRaw('seller_id, count(*) AS n')->groupBy('seller_id')->pluck('n', 'seller_id');

        // The last top-up credit and the last trade (a deposit hold or a balance payment) that touched each customer's wallet.
        $money = DB::table('ledger_posting AS p')
            ->join('account AS a', 'a.account_id', '=', 'p.account_id')
            ->join('ledger_transaction AS t', 't.ledger_txn_id', '=', 'p.ledger_txn_id')
            ->whereIn('a.customer_id', $customers)
            ->whereIn('t.event_kind', ['topup', 'deposit_hold', 'balance_payment'])
            ->selectRaw("a.customer_id,
                max(t.created_at) FILTER (WHERE t.event_kind = 'topup') AS last_topup,
                max(t.created_at) FILTER (WHERE t.event_kind <> 'topup') AS last_trade")
            ->groupBy('a.customer_id')->get()->keyBy('customer_id');

        $recentDays = (int) config('dahab-withdrawals.recent_change_days');
        $out = [];

        foreach ($rows as $w) {
            $account = $w->account;
            $customer = $w->customer;
            $change = $inUse->get($w->customer_id);
            $m = $money->get($w->customer_id);
            $payouts = (int) ($released[$w->payout_account_id] ?? 0);
            $changedAt = ($change !== null && (int) $change->n > 1 && now()->subDays($recentDays)->lte($change->last_at))
                ? CarbonImmutable::parse($change->last_at)->toIso8601String() : null;

            $out[$w->withdrawal_id] = [
                'identity_verified' => (bool) $customer?->is_verified,
                'suspended' => $customer?->status === CustomerStatus::SUSPENDED,
                'account_added_at' => $account?->created_at?->toIso8601String(),
                'first_payout_to_account' => $payouts === 0 || ($payouts === 1 && $w->state === WithdrawalState::RELEASED),
                'payouts_to_account' => $payouts,
                'in_use_changed_at' => $changedAt,
                'completed_sales' => (int) ($sales[$w->customer_id] ?? 0),
                'topped_up_never_traded' => $m !== null && $m->last_topup !== null
                    && ($m->last_trade === null || $m->last_topup > $m->last_trade),
            ];
        }

        return $out;
    }
}
