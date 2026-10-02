<?php

namespace App\Support\BuyRequests;

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Models\Account;
use App\Models\BuyRequest;
use App\Models\LedgerTransaction;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Pricing\Money;
use Illuminate\Support\Facades\DB;

/**
 * A buyer's deposit on the ledger (spec 011 FR-003, FR-022; Part 2 §4).
 * Money never leaves the buyer: a hold moves it from their available to their
 * held account, a release moves it back. Both go through the money service in
 * the caller's transaction and name the request (and the order, for a staff
 * cancellation). The accounts are looked up in the `ledger` scope: a seller's
 * accept releases other buyers' deposits.
 */
final class DepositLedger
{
    public function __construct(private readonly PostLedgerEntryAction $post) {}

    /** The hold for a request whose id is chosen before its row exists (lt_request_fk is deferred). */
    public function hold(string $buyRequestId, string $listingId, string $buyerId, string $amount): LedgerTransaction
    {
        [$available, $held] = $this->accounts($buyerId);

        return $this->post->handle(new LedgerEntry(
            LedgerEventKind::DEPOSIT_HOLD,
            [new LedgerLine($available, '-'.$amount), new LedgerLine($held, $amount)],
            actorCustomerId: $buyerId,
            listingId: $listingId,
            buyRequestId: $buyRequestId,
        ));
    }

    /** Give a request's deposit back to its buyer. Exactly one actor. */
    public function release(BuyRequest $request, ?string $actorCustomerId, ?string $actorStaffId, ?string $orderId = null): LedgerTransaction
    {
        [$available, $held] = $this->accounts($request->buyer_id);
        $amount = Money::fixed4((string) $request->deposit_amount);

        return $this->post->handle(new LedgerEntry(
            LedgerEventKind::DEPOSIT_RELEASE,
            [new LedgerLine($held, '-'.$amount), new LedgerLine($available, $amount)],
            actorCustomerId: $actorCustomerId,
            actorStaffId: $actorStaffId,
            listingId: $request->listing_id,
            orderId: $orderId,
            buyRequestId: $request->buy_request_id,
        ));
    }

    /** What the customer can spend now (for the insufficient_funds details; the money service decides). */
    public function available(string $customerId): string
    {
        return DatabaseActor::ledger(fn () => (string) (DB::table('ledger_posting')
            ->where('account_id', Account::forCustomerKind($customerId, AccountKind::CUST_AVAILABLE))
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4)::text AS balance')->value('balance') ?? '0.0000'));
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
