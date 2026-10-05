<?php

namespace App\Support\Invoices;

use App\Enums\PartyRole;
use App\Enums\PieceCategory;
use App\Models\BuyRequest;
use App\Models\Listing;
use App\Models\Order;
use App\Support\Orders\SettlementFigures;
use App\Support\Pricing\Money;
use App\Support\Pricing\PricingRates;

/**
 * The settlement lines printed on a tax invoice (spec 016 research R3): a
 * snapshot taken at issue, so the document never follows a later change of
 * settings, rates or piece-type names. Figures come from the settlement just
 * posted; the gold value is the subtotal less the making charge, so the lines
 * always add up to the posted total.
 */
final class InvoiceLines
{
    /** @return array<string, mixed> */
    public function build(PartyRole $party, Order $order, Listing $listing, BuyRequest $request, SettlementFigures $f, PricingRates $rates): array
    {
        $listing->loadMissing('pieceType');
        $weight = $f->weight === '' ? null : $f->weight;
        $subtotal = $party === PartyRole::SELLER ? $f->sellerGross : $f->buyerTotal;

        $lines = [
            'category' => $listing->category->value,
            'karat' => $listing->karat_code,
            'karat_label' => $listing->karat_code === null ? null : $listing->karat_code.'K',
            'piece_type_id' => $listing->piece_type_id,
            'piece_type_en' => $listing->pieceType?->name_en,
            'piece_type_ar' => $listing->pieceType?->name_ar,
            'weight_g' => $weight,
            'unit_rate' => null,
            'gold_value' => null,
            'making_total' => null,
            'asking_price' => null,
            'subtotal' => $subtotal,
        ];

        if ($listing->category === PieceCategory::GOLD && $weight !== null) {
            $making = Money::round4(Money::mul((string) $listing->making_charge_per_g, $weight));
            $gold = Money::fixed4(Money::sub($subtotal, $making));
            $rate = $party === PartyRole::BUYER ? $request->locked_unit_rate : $order->locked_seller_unit_rate;
            $lines['unit_rate'] = $rate !== null ? Money::fixed4((string) $rate)
                : (Money::cmp($weight, '0') > 0 ? Money::round4(Money::div($gold, $weight)) : null);
            $lines['gold_value'] = $gold;
            $lines['making_total'] = $making;
        } else {
            // A fixed asking price (diamond / gold with diamond; a regrade's agreed price).
            $lines['asking_price'] = $subtotal;
        }

        if ($party === PartyRole::SELLER) {
            $lines['commission_pct'] = $listing->category === PieceCategory::GOLD ? $rates->goldPct : $rates->stonePct;
            $lines['commission_minimum'] = $rates->minimumEgp;
            $lines['paid_to_wallet'] = $f->proceeds;
        }

        return $lines;
    }
}
