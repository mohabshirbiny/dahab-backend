<?php

namespace App\Support\Invoices;

use App\Enums\PartyRole;
use App\Jobs\RenderTaxDocumentJob;
use App\Models\BuyRequest;
use App\Models\Listing;
use App\Models\Order;
use App\Models\TaxInvoice;
use App\Support\Orders\SettlementFigures;
use App\Support\Pricing\Money;
use App\Support\Pricing\PricingRates;
use Illuminate\Support\Str;

/**
 * Issue an order's two tax invoices inside the pay-balance settlement
 * (spec 016 FR-001–FR-007, research R2–R5): the seller's for the commission
 * and VAT just posted, the buyer's for the price paid with VAT 0. Called in
 * the buyer's transaction and `order` scope, after the order moved to
 * ready to collect; the deferred DH012 check ties both to the settlement.
 *
 * The buyer's scope may insert the seller's invoice but cannot read it back
 * (row-level security applies the read policy to `INSERT … RETURNING`), so
 * ids are made here and the rows are written with a plain insert — never
 * returned or reloaded (analysis U1). Documents are generated after commit.
 * Spec 018: when the commission is zero (a free relist) only the buyer's invoice is issued.
 * Not final: a test replaces it to prove a failed issue rolls the payment back.
 */
class IssueTaxInvoices
{
    public function __construct(
        private readonly InvoiceLines $lines,
        private readonly IssuerDetails $issuer,
    ) {}

    /** @return array<string, array{id: string, number: string}> keyed by party role */
    public function issue(Order $order, Listing $listing, BuyRequest $request, SettlementFigures $f, PricingRates $rates): array
    {
        $issuer = $this->issuer->snapshot();
        $issued = [];

        foreach (PartyRole::cases() as $party) {
            $seller = $party === PartyRole::SELLER;
            // Spec 018 FR-021: a sale of no commission (a free relist) has no seller invoice —
            // nothing is charged to the seller, and the table refuses a zero net.
            if ($seller && Money::cmp($f->commission, '0') <= 0) {
                continue;
            }

            $net = $seller ? $f->commission : $f->buyerTotal;
            $vat = $seller ? $f->vat : '0.0000';
            $row = [
                'invoice_id' => (string) Str::uuid(),
                'invoice_no' => $order->order_ref.$party->suffix(),
                'order_id' => $order->order_id,
                'party_role' => $party->value,
                'customer_id' => $seller ? $order->seller_id : $order->buyer_id,
                'net_amount' => $net,
                'vat_amount' => $vat,
                'gross_amount' => Money::fixed4(Money::add($net, $vat)),
                'vat_rate' => $seller ? $rates->vatPct : '0',
                'lines' => json_encode($this->lines->build($party, $order, $listing, $request, $f, $rates), JSON_THROW_ON_ERROR),
                'issuer' => $issuer === null ? null : json_encode($issuer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ];

            TaxInvoice::query()->insert($row);
            RenderTaxDocumentJob::dispatch(RenderTaxDocumentJob::INVOICE, $row['invoice_id'])->afterCommit();
            $issued[$party->value] = ['id' => $row['invoice_id'], 'number' => $row['invoice_no']];
        }

        return $issued;
    }
}
