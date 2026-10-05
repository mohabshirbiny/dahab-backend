<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Support\BuyRequests\DepositLedger;
use App\Support\DatabaseActor;
use App\Support\Invoices\IssueTaxInvoices;
use App\Support\Orders\CollectionCodes;
use App\Support\Orders\DeadlinePolicy;
use App\Support\Orders\OrderSettlement;
use App\Support\Pricing\Money;
use App\Support\Pricing\PricingContext;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The buyer pays the balance — the settlement (spec 012 US6, FR-014–FR-016,
 * research R16; Part 2 §7, Part 3 §3). From the wallet's available balance
 * only, in full, before the deadline. One transaction in the audited `order`
 * scope, listing locked first: the figures recomputed on the IGI weight, one
 * balanced `balance_payment` through escrow to the seller and Dahab, the
 * order ready to collect, the listing sold, a collection code issued. The
 * seller is told they were paid; the buyer gets the code. Spec 016: the same
 * transaction issues both tax invoices (seller `-S`, buyer `-B`); their PDFs
 * are generated after commit.
 */
final class PayBalanceAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly OrderSettlement $settlement,
        private readonly CollectionCodes $codes,
        private readonly DeadlinePolicy $deadlines,
        private readonly DepositLedger $deposits,
        private readonly RecordAuditLogAction $audit,
        private readonly PricingContext $pricing,
        private readonly IssueTaxInvoices $invoices,
    ) {}

    public function handle(Customer $buyer, string $orderId, ?RequestContext $ctx = null): Order
    {
        $order = Order::query()->findOrFail($orderId);
        if ($order->buyer_id !== $buyer->customer_id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        return DatabaseActor::order(fn () => DB::transaction(function () use ($buyer, $order, $ctx) {
            $listing = $this->lockListing($order->listing_id);
            $order = $this->lockOrder($order->order_id);
            $this->assertNotFrozen($order);

            if ($order->state !== OrderState::AWAITING_BALANCE || $listing->state !== ListingState::SETTLING) {
                throw DomainApiException::illegalOrderTransition();
            }
            if ($order->balance_due_deadline === null || $order->balance_due_deadline->isPast()) {
                throw DomainApiException::balanceDeadlinePassed();
            }

            $request = BuyRequest::query()->whereKey($order->buy_request_id)->firstOrFail();
            // Read the commission and VAT settings once: the invoice records the VAT rate the settlement used.
            $rates = $this->pricing->rates();
            $figures = $this->settlement->compute($order, $listing, $request, $order->latestInspection(), $rates);

            try {
                $txn = $this->settlement->post($order, $figures);
            } catch (DomainApiException $e) {
                if ($e->errorCode !== 'insufficient_funds') {
                    throw $e;
                }
                $available = $this->deposits->available($buyer->customer_id);
                throw DomainApiException::insufficientFunds([
                    'amount_due' => $figures->balance,
                    'available' => Money::fixed4($available),
                    'shortfall' => Money::fixed4(Money::sub($figures->balance, $available)),
                ]);
            }

            $this->moveOrder($order, OrderState::READY_TO_COLLECT, $buyer, null, null, $figures->columns() + [
                'settlement_txn_id' => $txn->ledger_txn_id,
                'collect_deadline' => $this->deadlines->collectWindow(),
            ]);
            $listing = $this->moveListing($listing, ListingState::SOLD, $buyer, null, ListingStateChange::NOTE_BALANCE_PAID);

            $invoices = $this->invoices->issue($order, $listing, $request, $figures, $rates);

            $code = $this->codes->issue();
            OrderCollection::query()->create([
                'order_id' => $order->order_id,
                'code_hash' => $this->codes->hash($code),
                'code_encrypted' => $code,
            ]);

            $this->audit->execute(
                AuditEvent::ORDER_PAID,
                'success',
                ['order_ref' => $order->order_ref, 'state' => OrderState::READY_TO_COLLECT->value,
                    'buyer_total' => $figures->buyerTotal, 'balance' => $figures->balance, 'seller_proceeds' => $figures->proceeds,
                    'settlement_txn_id' => $txn->ledger_txn_id, 'seller_id' => $order->seller_id,
                    'invoice_numbers' => array_column($invoices, 'number')],
                'order',
                $order->order_id,
                $ctx,
                actorCustomerId: $buyer->customer_id,
                before: ['state' => OrderState::AWAITING_BALANCE->value],
            );

            $this->tellOrder($order->seller_id, OrderEvent::PAID, $order, $listing, amount: $figures->proceeds);
            $this->tellOrder($order->buyer_id, OrderEvent::COLLECTION_CODE, $order, $listing,
                deadline: $order->collect_deadline, code: $code);
            $this->flushOrderOutbox();

            return $order;
        }));
    }
}
