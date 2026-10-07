<?php

namespace App\Support\Withdrawals;

use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\PauseTrigger;
use App\Enums\SettingKey;
use App\Enums\WithdrawalState;
use App\Models\WithdrawalPause;
use App\Support\Pricing\Settings;

/**
 * The safety stop of spec 013, shared by a change of payout account and, since
 * spec 017 (research R3), a change of phone number or email: every withdrawal
 * not yet released is cancelled with its money back to available, and new
 * withdrawals pause for `withdrawal.account_change_pause_hours`.
 *
 * Runs inside the caller's transaction, after the caller locked the customer's
 * payout accounts (`lockPayoutAccounts()`, the lock order of spec 013 R6), so a
 * racing withdrawal either committed before (and is cancelled here) or waits
 * and meets the pause. Sends nothing: each caller tells the customer in its
 * own words.
 */
final class WithdrawalSafetyStop
{
    use WorksWithdrawals;

    /** The first lock of every path that may stop withdrawals (spec 013 R6). */
    public function lockPayoutAccounts(string $customerId): void
    {
        $this->lockAccounts($customerId);
    }

    /**
     * @return array{pause: ?WithdrawalPause, cancelled: list<string>}
     */
    public function apply(
        string $customerId,
        PauseTrigger $trigger,
        string $ledgerReason,
        ?string $accountId = null,
        ?string $actorCustomerId = null,
        ?string $actorStaffId = null,
    ): array {
        $this->requireTransaction();

        $ledger = app(WithdrawalLedger::class);
        $cancelled = [];
        foreach ($this->lockOpenWithdrawals($customerId) as $open) {
            $txn = $ledger->returnToAvailable($open, $actorCustomerId, $actorStaffId, $ledgerReason);
            $this->moveWithdrawal($open, WithdrawalState::CANCELLED, [
                'return_txn_id' => $txn->ledger_txn_id,
                'cancelled_by_change' => true,
            ]);
            $cancelled[] = $open->number();
        }

        $pause = null;
        $hours = app(Settings::class)->integer(SettingKey::WITHDRAWAL_ACCOUNT_CHANGE_PAUSE_HOURS);
        if ($hours > 0) {
            $pause = WithdrawalPause::query()->create([
                'customer_id' => $customerId,
                'opened_at' => now(),
                'pause_until' => now()->addHours($hours),
                'triggered_by_account' => $trigger === PauseTrigger::PAYOUT_ACCOUNT ? $accountId : null,
                'trigger_kind' => $trigger,
            ]);
        }

        return ['pause' => $pause, 'cancelled' => $cancelled];
    }
}
