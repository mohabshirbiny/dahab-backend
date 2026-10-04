<?php

namespace App\Actions\Finance;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\AdjustmentDirection;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\LedgerEventKind;
use App\Enums\WalletEvent;
use App\Exceptions\DomainApiException;
use App\Jobs\NotifyCustomerJob;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\WalletAdjustment;
use App\Notifications\WalletNotification;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adjust a wallet balance directly (spec 015 FR-005–FR-008, Clarification Q2;
 * Part 2 §9 "the narrowest, most sensitive money action"): one balanced entry
 * of kind `reversal` with no reversed entry — the customer's available
 * against `external_equity` — through the money service, which locks the
 * customer's account and refuses a debit beyond available. Never an edit of a
 * balance. Recorded in `wallet_adjustment` (a deferred check ties it to the
 * entry), audited with the reason, and the customer is told — without the
 * reason — after commit.
 */
final class AdjustWalletAction
{
    public function __construct(
        private readonly PostLedgerEntryAction $post,
        private readonly WithdrawalLedger $balances,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $staff, string $customerId, AdjustmentDirection $direction, string $amount, string $reason,
        ?RequestContext $ctx = null): WalletAdjustment
    {
        $amount = Money::fixed4($amount);

        return DB::transaction(function () use ($staff, $customerId, $direction, $amount, $reason, $ctx) {
            $customer = Customer::query()->whereKey($customerId)->firstOrFail();
            $status = $customer->status instanceof CustomerStatus ? $customer->status->value : (string) $customer->status;

            [$equity, $available] = DatabaseActor::ledger(fn () => [
                Account::internal(AccountKind::EXTERNAL_EQUITY),
                Account::forCustomerKind($customerId, AccountKind::CUST_AVAILABLE),
            ]);

            try {
                $txn = $this->post->handle(new LedgerEntry(
                    LedgerEventKind::REVERSAL,
                    [new LedgerLine($available, $direction->signed($amount)),
                        new LedgerLine($equity, $direction === AdjustmentDirection::CREDIT ? '-'.$amount : $amount)],
                    actorStaffId: $staff->staff_id,
                    memo: $reason,
                ));
            } catch (DomainApiException $e) {
                throw $e->errorCode === 'insufficient_funds' ? $this->balances->insufficient($customerId, $amount) : $e;
            }

            $adjustment = WalletAdjustment::query()->create([
                'customer_id' => $customerId,
                'direction' => $direction,
                'amount' => $amount,
                'reason' => $reason,
                'customer_status' => $status,
                'adjusted_by' => $staff->staff_id,
                'ledger_txn_id' => $txn->ledger_txn_id,
                'adjusted_at' => CarbonImmutable::now(),
            ]);

            $this->audit->execute(AuditEvent::WALLET_ADJUSTED, 'success',
                ['customer_id' => $customerId, 'customer_status' => $status, 'direction' => $direction->value,
                    'amount' => $amount, 'ledger_txn_id' => $txn->ledger_txn_id],
                'wallet_adjustment', $adjustment->adjustment_id, $ctx, actorStaffId: $staff->staff_id, reason: $reason);

            DB::afterCommit(fn () => NotifyCustomerJob::dispatch($customerId,
                new WalletNotification(WalletEvent::WALLET_ADJUSTED, $amount, $direction->value)));

            return $adjustment;
        });
    }
}
