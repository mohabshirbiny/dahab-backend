<?php

namespace App\Actions\Orders;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\LedgerEventKind;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Enums\SettingKey;
use App\Models\Account;
use App\Models\BuyRequest;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The buyer never paid (spec 012 US7, FR-018, research R13; Part 3 §10.1):
 * the balance sweep, as the system actor, one order per transaction, listing
 * locked first and the state and deadline re-checked under the lock (a
 * payment that won the race leaves nothing to do). The order becomes
 * `cancelled_buyer_nopay`; one balanced `deposit_forfeit` splits the deposit —
 * `deposit.seller_forfeit_share_pct` to the seller, half-up to the piastre,
 * the rest (with any residue) to `dahab_commission`, no VAT; the piece goes
 * back to the seller with a code, carrying that compensation.
 */
final class ForfeitDepositAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly PostLedgerEntryAction $post,
        private readonly Settings $settings,
        private readonly OpenSellerReturnAction $returns,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $system, string $orderId): ?Order
    {
        return DB::transaction(function () use ($system, $orderId) {
            $listingId = Order::query()->whereKey($orderId)->value('listing_id');
            if ($listingId === null) {
                return null;
            }
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);

            if ($order->state !== OrderState::AWAITING_BALANCE || $order->balance_due_deadline?->isFuture() !== false) {
                return null;
            }

            $request = BuyRequest::query()->whereKey($order->buy_request_id)->firstOrFail();
            $deposit = Money::fixed4((string) $request->deposit_amount);
            $raw = Money::percent($deposit, $this->settings->numeric(SettingKey::DEPOSIT_SELLER_FORFEIT_SHARE_PCT));
            $sellerShare = bcadd(bcadd($raw, '0.005', Money::SCALE), '0', 2).'00';
            $dahabShare = Money::fixed4(Money::sub($deposit, $sellerShare));

            $lines = DatabaseActor::ledger(fn () => array_values(array_filter([
                new LedgerLine(Account::forCustomerKind($order->buyer_id, AccountKind::CUST_HELD), '-'.$deposit),
                Money::cmp($sellerShare, '0') > 0 ? new LedgerLine(Account::forCustomerKind($order->seller_id, AccountKind::CUST_AVAILABLE), $sellerShare) : null,
                Money::cmp($dahabShare, '0') > 0 ? new LedgerLine(Account::internal(AccountKind::DAHAB_COMMISSION), $dahabShare) : null,
            ])));

            $txn = $this->post->handle(new LedgerEntry(
                LedgerEventKind::DEPOSIT_FORFEIT,
                $lines,
                actorStaffId: $system->staff_id,
                listingId: $order->listing_id,
                orderId: $order->order_id,
                buyRequestId: $order->buy_request_id,
            ));

            $this->moveOrder($order, OrderState::CANCELLED_BUYER_NOPAY, null, $system, OrderStateChange::NOTE_DID_NOT_PAY, [
                'forfeit_txn_id' => $txn->ledger_txn_id,
            ]);
            $opened = $this->returns->handle($order, $listing, $txn, null, $system, ListingStateChange::NOTE_BUYER_DID_NOT_PAY);

            $this->audit->execute(
                AuditEvent::ORDER_FORFEITED,
                'success',
                ['order_ref' => $order->order_ref, 'deposit' => $deposit, 'seller_share' => $sellerShare, 'dahab_share' => $dahabShare,
                    'forfeit_txn_id' => $txn->ledger_txn_id, 'buyer_id' => $order->buyer_id, 'seller_id' => $order->seller_id],
                'order',
                $order->order_id,
                null,
                actorStaffId: $system->staff_id,
                before: ['state' => OrderState::AWAITING_BALANCE->value],
            );

            $this->tellOrder($order->buyer_id, OrderEvent::FORFEITED, $order, $listing);
            $this->tellOrder($order->seller_id, OrderEvent::FORFEITED, $order, $listing);
            $this->tellOrder($order->seller_id, OrderEvent::RETURN_WAITING, $order, $listing,
                amount: $sellerShare, deadline: $opened['return']->return_deadline, code: $opened['code']);
            $this->flushOrderOutbox();

            return $order;
        });
    }
}
