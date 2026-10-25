# Promo details, an invoice for every sale, branch details and stone details

Owner notes, 2026-10-25 (seven screenshots). Status: built in all three projects, nothing committed.

## What changes

1. **Branch details.** A branch has an optional `contact_phone` and `map_url` (Dashboard → Branches). The public
   `GET /reference/branches` returns them with the weekly `hours`; the customer order's `branch` block carries them too.
   The app opens a branch sheet (address, hours by day, phone, map) from the sell form's branch list and from the order.
2. **Stone details.** A stone piece (diamond, gold with diamond) may state `stone_carat`, `stone_clarity`
   (`vvs|vs|si|i`) and `stone_cut` (`excellent|very_good|good`) — the values the sell form already asks for the
   price guide, now sent and stored. They and `certificate_kind` show on the seller's listing, the market piece (list
   and detail), the staff listing review and the order's piece block, so seller and buyer both see "No certificate" and
   what the stone is. Gold refuses them (`prohibited`).
3. **Certificate cost on the seller's listing.** Already in the API (`certificate_fee`); the app now shows the row.
4. **Gold value per gram** on the sell estimate — the quote's `rate_per_gram`, app only.
5. **An invoice for every sale.** A sale of no commission (free relist, a seller code that waives it, a market-maker
   purchase) now issues the seller's invoice `-S` too: commission 0, VAT 0, with what the commission would have been and
   why it is 0 (`lines.commission_waived`). `tax_invoice.net_amount` may be 0 for a seller invoice only; every settled
   order needs its seller invoice (DH012). A zero invoice is never credited (nothing is left on it).
6. **The seller's saving includes VAT.** The sell form and the seller's order show the commission saved as the whole
   commission the seller would have paid, VAT included (`saved_total_egp`), so "no commission" saves the full 300, not
   258. The order stores `commission_ordinary_total` at settlement.
7. **The buyer's discount, written out.** A buyer's order with a code carries `promo`: `{code, gives_spread,
   price_before_egp, discount_egp, total_egp, deposit_full_egp, deposit_discount_egp}`. For a gold buyer code (and a
   market maker) the discount is the spread handed back, so `total = price_before − discount`. `amount_due` is now the
   money that leaves the wallet (balance less the discount), as the ledger already moved it; `insufficient_funds`
   reports the same figure. The buyer's invoice `-B` shows the price, the discount with the code and the total paid; its
   net is the total paid (DH012: `final_buyer_total − spread_given_up`).
8. **Dashboard promo codes: View.** A panel that spells out a code's terms (side, product, what it does to the
   commission, the spread and the deposit, who it is tied to, limits, dates, cap, use this month, when it was made).

## Classification

Non-breaking: new optional request fields (branch, listing), new response fields. Potentially breaking: `amount_due`
on a buyer's order with a spread code is lower than before (the real amount; Flutter is the only consumer, updated);
the buyer invoice net of such an order is the total paid; a seller invoice may have net 0 (Dashboard invoice list shows
it as 0). Migration `2026_10_25_000030_offer_details_and_invoices`.

## Not done

- No backfill: sales settled before this keep no seller invoice when the commission was 0.
- Stone colour is not asked (the form never had it).
