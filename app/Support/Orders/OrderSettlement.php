<?php

namespace App\Support\Orders;

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\InspectionOutcome;
use App\Enums\LedgerEventKind;
use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\BuyRequest;
use App\Models\InspectionResult;
use App\Models\LedgerTransaction;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Listings\ListingPricer;
use App\Support\Pricing\Money;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PriceCalculator;
use App\Support\Pricing\PricingContext;
use App\Support\Pricing\PricingRates;

/**
 * The settlement of an order at pay-balance (spec 012 FR-014–FR-017,
 * research R6–R7; Part 2 §7, Part 3 §3). Every figure is recomputed on the
 * IGI-measured weight with the per-gram rates locked earlier — the buyer's at
 * the request, the seller's at acceptance — and the commission, VAT and
 * minimum read live now. One balanced `balance_payment` transaction moves the
 * money through escrow to the seller and Dahab's three accounts.
 */
final class OrderSettlement
{
    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly PricingContext $context,
        private readonly PostLedgerEntryAction $post,
    ) {}

    /** The price a buyer is asked to accept after an adjustment (research R6, R11). */
    public function newPrice(Order $order, Listing $listing, BuyRequest $request, InspectionResult $result, ?PricingRates $rates = null): ?string
    {
        if ($result->outcome === InspectionOutcome::STONE_REGRADE) {
            return $order->proposed_price === null ? null : Money::round4((string) $order->proposed_price);
        }

        return $this->compute($order, $listing, $request, $result, $rates)->buyerTotal;
    }

    /**
     * `$rates` lets a list read the commission, VAT and minimum once for all its
     * rows; without it they are read live (a payment always reads them live).
     */
    public function compute(Order $order, Listing $listing, BuyRequest $request, ?InspectionResult $result, ?PricingRates $rates = null): SettlementFigures
    {
        $weight = $result?->measured_weight_g ?? $listing->stated_weight_g;
        $weight = $weight === null ? null : (string) $weight;

        $asking = $listing->asking_price === null ? null : (string) $listing->asking_price;
        if ($result?->outcome === InspectionOutcome::STONE_REGRADE && $order->proposed_price !== null) {
            $asking = (string) $order->proposed_price;
        }

        $breakdown = $this->calculator->lockedBreakdown(
            // Spec 018: a free relist is sold without commission, its VAT or the minimum (the spread stays).
            Piece::locked($listing->category, $weight, $listing->making_charge_per_g === null ? null : (string) $listing->making_charge_per_g, $asking, $listing->isFreeRelist()),
            $request->locked_unit_rate === null ? null : (string) $request->locked_unit_rate,
            $this->sellerRate($order, $listing),
            $rates ?? $this->context->rates(),
        );

        $deposit = Money::fixed4((string) $request->deposit_amount);
        $balance = Money::cmp($breakdown->buyerTotal, $deposit) > 0 ? Money::fixed4(Money::sub($breakdown->buyerTotal, $deposit)) : '0.0000';
        $excess = Money::cmp($deposit, $breakdown->buyerTotal) > 0 ? Money::fixed4(Money::sub($deposit, $breakdown->buyerTotal)) : '0.0000';

        return new SettlementFigures(
            weight: $weight === null ? '' : bcadd($weight, '0', 3),
            buyerTotal: $breakdown->buyerTotal,
            sellerGross: $breakdown->sellerGross,
            commission: $breakdown->commission,
            vat: $breakdown->vat,
            spread: $breakdown->spread,
            proceeds: $breakdown->sellerProceeds,
            deposit: $deposit,
            balance: $balance,
            excess: $excess,
        );
    }

    /**
     * Post the settlement (research R7). The buyer is the actor; the entry is
     * tied to the order, the listing and the request. Zero lines are left out
     * (`posting_nonzero`); a negative spread is a negative `dahab_spread` line.
     */
    public function post(Order $order, SettlementFigures $f): LedgerTransaction
    {
        if (Money::cmp($f->proceeds, '0') <= 0) {
            throw DomainApiException::settlementNotPossible();
        }

        $lines = DatabaseActor::ledger(function () use ($order, $f) {
            $buyerAvailable = Account::forCustomerKind($order->buyer_id, AccountKind::CUST_AVAILABLE);
            $escrow = Account::internal(AccountKind::ESCROW);

            $lines = [];
            if (Money::cmp($f->balance, '0') > 0) {
                $lines[] = new LedgerLine($buyerAvailable, '-'.$f->balance);
            }
            $lines[] = new LedgerLine(Account::forCustomerKind($order->buyer_id, AccountKind::CUST_HELD), '-'.$f->deposit);
            if (Money::cmp($f->excess, '0') > 0) {
                $lines[] = new LedgerLine($buyerAvailable, $f->excess);
            }
            $lines[] = new LedgerLine($escrow, $f->buyerTotal);
            $lines[] = new LedgerLine($escrow, '-'.$f->buyerTotal);
            $lines[] = new LedgerLine(Account::forCustomerKind($order->seller_id, AccountKind::CUST_AVAILABLE), $f->proceeds);
            foreach ([
                [AccountKind::DAHAB_COMMISSION, $f->commission],
                [AccountKind::VAT_PAYABLE, $f->vat],
                [AccountKind::DAHAB_SPREAD, $f->spread],
            ] as [$kind, $amount]) {
                if (Money::cmp($amount, '0') !== 0) {
                    $lines[] = new LedgerLine(Account::internal($kind), $amount);
                }
            }

            return $lines;
        });

        return $this->post->handle(new LedgerEntry(
            LedgerEventKind::BALANCE_PAYMENT,
            $lines,
            actorCustomerId: $order->buyer_id,
            listingId: $order->listing_id,
            orderId: $order->order_id,
            buyRequestId: $order->buy_request_id,
        ));
    }

    /**
     * The seller's locked rate: `sellers_get` (gold) or the mid (gold with
     * diamond) stored at acceptance. Orders accepted before spec 012 have none
     * and take today's figure (analysis U1, local data only); null for a pure
     * diamond.
     */
    private function sellerRate(Order $order, Listing $listing): ?string
    {
        if ($listing->category === PieceCategory::DIAMOND) {
            return null;
        }
        if ($order->locked_seller_unit_rate !== null) {
            return (string) $order->locked_seller_unit_rate;
        }

        $prices = app(ListingPricer::class)->karatPrices((int) $listing->karat_code)
            ?? throw DomainApiException::priceUnavailable();

        return $listing->category === PieceCategory::GOLD ? $prices->sellersGet : $prices->mid;
    }
}
