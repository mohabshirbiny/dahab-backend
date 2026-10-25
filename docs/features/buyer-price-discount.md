# Buyer code: a discount off the price, and the code box on the piece page

Owner request, 2026-10-26. Built in all three projects.

## Rule

A buyer code (`kind: buyer`) does exactly one thing:

| What the buyer gets | Products | Paid by |
|---|---|---|
| The seller's price (the spread handed back) | gold | the spread (all of it) |
| A lower deposit (percent or amount) | diamond, gold with diamond | nothing (only the deposit is smaller) |
| **A percentage or an amount off the price** (new) | any | staff choose: **Dahab's commission** or, on gold, **the spread** |

The price discount never goes above what pays for it: the ordinary commission **net of VAT**, or the positive spread.
Both limits are read on the buyer's side of the sale (no seller waiver), so the figure the buyer is shown is the figure
they pay. A percentage is of the buyer's total, rounded to the piastre.

## Money

- The seller is unaffected: same commission, same invoice, same proceeds.
- At pay-balance, the discount returns to the buyer's available money inside the same `balance_payment` entry, and a
  line of its own takes it from `dahab_commission` or `dahab_spread` (the commission line still equals the seller's
  invoice, DH012). Stored on the order: `price_discount_egp`, `price_discount_funded_by`.
- The buyer's invoice is the total paid: `price_before_discount`, `promo_discount` (spread + price discount),
  `promo_code`, `total_paid`; DH012 checks `final_buyer_total − spread_given_up − price_discount_egp`.
- `amount_due`, `insufficient_funds`, the balance reminder and the "passed inspection" notice use the discounted figure.

## API

- `POST|PUT /dashboard/promo-codes`: `price_percent` | `price_amount_egp` + `discount_funded_by` (`commission|spread`);
  spread only for gold; not with a deposit discount or the seller's price; not on seller codes. List:
  `what_it_does.price {mode, percent, amount_egp, funded_by}`.
- `POST /customer/me/buy-requests/promo-preview` {listing_id, promo_code}: the same checks as sending the request,
  records nothing; answers `effect` (spread|price|deposit), `price_before_egp`, `discount_egp`, `total_egp`,
  `deposit_full_egp`, `deposit_egp`. Trade gate, `throttle:customer.promo`.
- The buyer's order `promo` gains `price_discount {percent, amount_egp}`.

Migration `2026_10_26_000020_buyer_price_discount` (new columns, `promo_code_kind_terms` rewritten, DH020 and DH012
functions replaced). Classification: non-breaking (new optional fields and endpoint).

## Apps

- Dashboard: the code form's buyer part asks *What the buyer gets* and *Paid for by*; the table and View say it.
- Customer app: a *Have a promo code?* card on the piece page, above *Send buy request* (like the seller's on the sell
  form): Apply shows the price, the discount, what you pay and the deposit; the applied code goes with the request.
  The old pop-up after the deposit terms is gone.

## Not done

- A credit note on the seller's invoice of a sale whose discount came from the commission can give back more than Dahab
  kept on that sale.
- The market-maker cost panel does not count buyer discounts.
