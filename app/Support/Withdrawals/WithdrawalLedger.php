<?php

namespace App\Support\Withdrawals;

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\Withdrawal;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Pricing\Money;
use Illuminate\Support\Facades\DB;

/**
 * A withdrawal's money on the ledger (spec 013 research R4). Three shapes,
 * all `event_kind = withdrawal`, all tied to the withdrawal, all through the
 * money service inside the caller's transaction:
 *
 *   hold     customer available −X, customer held +X      (the customer)
 *   release  customer held −X,      bank +X                (the releasing staff)
 *   return   customer held −X,      customer available +X  (customer cancel / account change, or staff reject)
 *
 * The bank's cash is −SUM(bank) (spec 008 R15), so a release lowers it by X.
 */
final class WithdrawalLedger
{
    public function __construct(private readonly PostLedgerEntryAction $post) {}

    /** The hold for a withdrawal whose id is chosen before its row exists (lt_withdrawal_fk is deferred). */
    public function hold(string $withdrawalId, int|string $number, string $customerId, string $amount): LedgerTransaction
    {
        [$available, $held] = $this->accounts($customerId);
        $amount = Money::fixed4($amount);

        try {
            return $this->post->handle(new LedgerEntry(
                LedgerEventKind::WITHDRAWAL,
                [new LedgerLine($available, '-'.$amount), new LedgerLine($held, $amount)],
                actorCustomerId: $customerId,
                memo: "Withdrawal WD-{$number} · held until a person releases it",
                withdrawalId: $withdrawalId,
            ));
        } catch (DomainApiException $e) {
            if ($e->errorCode !== 'insufficient_funds') {
                throw $e;
            }

            throw $this->insufficient($customerId, $amount);
        }
    }

    public function release(Withdrawal $withdrawal, string $staffId): LedgerTransaction
    {
        [, $held] = $this->accounts($withdrawal->customer_id);
        $amount = Money::fixed4((string) $withdrawal->amount);

        return $this->post->handle(new LedgerEntry(
            LedgerEventKind::WITHDRAWAL,
            [new LedgerLine($held, '-'.$amount), new LedgerLine(Account::internal(AccountKind::BANK), $amount)],
            actorStaffId: $staffId,
            memo: "Withdrawal {$withdrawal->number()} · sent to the bank",
            withdrawalId: $withdrawal->withdrawal_id,
        ));
    }

    /** Back to available. Exactly one actor. */
    public function returnToAvailable(Withdrawal $withdrawal, ?string $actorCustomerId, ?string $actorStaffId, string $why): LedgerTransaction
    {
        [$available, $held] = $this->accounts($withdrawal->customer_id);
        $amount = Money::fixed4((string) $withdrawal->amount);

        return $this->post->handle(new LedgerEntry(
            LedgerEventKind::WITHDRAWAL,
            [new LedgerLine($held, '-'.$amount), new LedgerLine($available, $amount)],
            actorCustomerId: $actorCustomerId,
            actorStaffId: $actorStaffId,
            memo: "Withdrawal {$withdrawal->number()} · returned to available ({$why})",
            withdrawalId: $withdrawal->withdrawal_id,
        ));
    }

    /** What the customer can withdraw now (for checks and the insufficient_funds details; the money service decides). */
    public function available(string $customerId): string
    {
        return DatabaseActor::ledger(fn () => (string) (DB::table('ledger_posting')
            ->where('account_id', Account::forCustomerKind($customerId, AccountKind::CUST_AVAILABLE))
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4)::text AS balance')->value('balance') ?? '0.0000'));
    }

    public function insufficient(string $customerId, string $amount): DomainApiException
    {
        $available = $this->available($customerId);
        $shortfall = bcsub(Money::fixed4($amount), $available, 4);

        return DomainApiException::insufficientFunds([
            'available' => $available,
            'shortfall' => bccomp($shortfall, '0', 4) > 0 ? $shortfall : '0.0000',
        ]);
    }

    /** @return array{0: string, 1: string} available, held */
    private function accounts(string $customerId): array
    {
        return DatabaseActor::ledger(fn () => [
            Account::forCustomerKind($customerId, AccountKind::CUST_AVAILABLE),
            Account::forCustomerKind($customerId, AccountKind::CUST_HELD),
        ]);
    }
}
