# VAT-inclusive commission (testing fixes, package C)

> File: `docs/features/vat-inclusive-commission.md` · Branch: `feature/vat-inclusive-commission` in dahab-backend, dahab-dashboard, dahab-flutter (branched from `feature/wallet-statement-pdf`)
> Status: done · Date: 2026-10-14

## Goal

Testing issue #8: VAT was added on top of the commission (`vat = 14 % × commission`), so a 20 % rate cost the
seller 22.8 %, while the app already said "Commission, VAT included". **Decision: the commission rate includes
VAT.** The seller pays exactly the rate; VAT is carved out of it. Testing issue #15: on the buyer's invoice, the
gold price difference is shown as Dahab's commission (display only).

## Impact Summary

```
Backend:      YES (pricing, waivers, settlement, credit spending, invoices)
Database:     NO  (no migration; stored fields keep their meaning)
API:          YES (same fields, new values; additive fields)
Dashboard:    YES (labels; invoice detail)
Customer App: YES (invoice screen; every other figure is the backend's)
Auth:         NO
Permissions:  NO
```

## The rule (item 12)

One helper, `App\Support\Pricing\VatSplit::fromInclusive($total, $vatPct)`:

```
total = max(rate × commission base, minimum)           -- what the seller pays, VAT included (minimum EGP 200 is VAT-inclusive)
vat   = round_half_up_4dp(total × vat_pct / 100)       -- owner's decision 2026-10-23 (was total × vat_pct / (100 + vat_pct))
net   = total − vat                                     -- the residue stays inside the total
commission (posted to dahab_commission) = net
vat (posted to vat_payable)             = vat
seller proceeds                         = seller gross − total
```

Fields keep their meaning so the ledger, the invoices and the DH012 reconciliation keep their shape:
`commission` = net, `vat` = vat, `commission + vat = total`.

**Waivers** (free relist, market maker, spec 023 credits and codes) are compared on the VAT-inclusive totals, and
only the winner is split; a waived sale stays 0 / 0. A percent credit takes its percentage off the total; an amount
credit is EGP off the total; the minimum floor compares totals. The figures stored on the order stay **net**
(`commission_ordinary_amount`, `commission_amount`, `commission_given_up`, and `commission_floor_egp` = the net of
the VAT-inclusive minimum), because the database checks `commission_amount + commission_given_up =
commission_ordinary_amount` and `commission_amount ≥ commission_floor_egp`.

**VAT is the rate of the total (owner's decision, 2026-10-23).** 5 % of 39,000 = 1,950 = commission 1,677 + VAT 273
(VAT = 14 % × 1,950). `vat_pct` is the Dashboard setting, never a constant. This is the owner's rule, not the
statutory carve-out (1,950 × 14 / 114 = 239.47); the effective VAT on the net is vat_pct / (100 − vat_pct) — have the
accountant confirm before production. Applies to every quote and settlement after the change; nothing posted is
rewritten (orders settled before keep their split).

**Credit notes** give back the invoice's own VAT share (`CreditNoteSplit`): VAT = gross × invoice VAT / invoice gross,
rounded half-up to 4 dp; the note that clears what is left takes the VAT not yet given back, so a fully credited invoice
reverses its VAT exactly whichever rule split it.

**Seller invoice `-S`:** net = commission (net), VAT, gross = total; the lines carry `commission_vat_inclusive: true`
and the PDF / Dashboard / app say "Commission before VAT (20 %, VAT included)" and "Commission, VAT included".
Older invoices keep their wording.

## Worked examples (rates 20 % gold / 5 % stones, minimum 200, VAT 14 %; split as of 2026-10-23)

| Case | Total charged | Net | VAT | Seller receives |
|---|---|---|---|---|
| Part 3 §3.3, 21K ring 10 g, making 300/g | 600.0000 | 516.0000 | 84.0000 | 55,368.7500 − 600 = **54,768.7500** |
| Part 3 §3.4, IGI weight 9.900 g | 594.0000 | 510.8400 | 83.1600 | 54,815.0625 − 594 = **54,221.0625** |
| Minimum applies (gold, making 150 on 5 g) | 200.0000 | 172.0000 | 28.0000 | gross − 200 |
| Odd making that rounds (total 333.3333) | 333.3333 | 286.6666 | 46.6667 | gross − 333.3333 |
| 50 % code on the §3.3 ring | 300.0000 | 258.0000 | 42.0000 | gross − 300 |
| Free relist / market maker | 0 | 0 | 0 | gross |

## Item 13 — buyer invoice `-B`

Gold only. The line snapshot adds `seller_unit_rate` (`order.locked_seller_unit_rate`), `gold_value_at_seller_rate`
(seller gross − making) and `price_difference` (buyer gold value − gold at the seller rate). The difference equals
the posted spread exactly (it is buyer total − seller gross; making cancels out); the line is left out when it would
not. Shown only when the difference is positive and went to `dahab_spread`: not for a market-maker purchase (the
spread goes to the dealer), not for diamond / gold with diamond, not when the rates crossed. Label "Commission" /
"العمولة", VAT 0 as today; the buyer's total is unchanged: gold at the seller rate + commission + making = subtotal.
Invoices issued before the deploy have none of these keys and render as before (PDF, Dashboard, app).

**Reminder for the owner:** a line called "commission" on a VAT-0 document may raise a tax question — have the
accountant check the wording before production. The VAT treatment of the spread is unchanged.

## Backend Impact

`VatSplit` (new), `PriceCalculator::finish`, `PriceBreakdown::commissionTotal()`, `CommissionWaivers::resolve` (takes
the ordinary total), `CommissionResolution` (adds `ordinaryTotal`, `chargedTotal`, `givenUpTotal`),
`OrderSettlement`, `SettlementFigures::commissionGivenUpTotal`, `SpendCommissionCreditAction`,
`PreviewCommissionAction`, `QuoteAction::minimum_applied`, `InvoiceLines`, `documents/invoice.blade.php`.

## API Changes

| Endpoint / field | Change | Class |
|---|---|---|
| Every `commission` / `vat` / proceeds figure (`/reference/quote`, listing `you_would_receive`, market prices, orders, invoices, `/customer/me/commission-preview`) | Same fields, new values: commission + vat now equals the rate | Potentially breaking in meaning only (field shapes unchanged); every consumer already shows commission + VAT as one "VAT included" figure |
| `POST /customer/me/commission-preview` | Adds `ordinary_total_egp`, `charged_total_egp`, `saved_total_egp` (VAT included) | Non-breaking |
| Invoice `lines` | Adds `commission_vat_inclusive`, `seller_unit_rate`, `gold_value_at_seller_rate`, `price_difference` | Non-breaking |

## Amount credits (decided by the owner, 2026-10-15)

**Amount credits.** An amount credit (e.g. the inviter's EGP reward) is EGP off what the seller pays, VAT included,
so its balance now goes down by the saving VAT included. The credit event's `reduction_egp` must equal the order's
`commission_given_up` (DH026), which is net, so the event records the net saving and the audit row records both
(`reduction`, `reduction_vat_included`). The balance after the event is therefore before − saving-VAT-included, not
before − `reduction_egp`. Decided by the owner: a credit of EGP 100 saves the seller exactly EGP 100 and its balance goes down by 100
(VAT included), as built.

The order's `commission.saved_egp` on the customer order and the Dashboard's "Given up" stay net (as stored).

## Cut-over

Applies to every quote and settlement computed after the deploy, including orders already in flight (the app has
always shown the commission as VAT included). Nothing already posted — ledger entries, invoices, credit notes — is
touched. No migration.

## Testing

Unit: `VatSplitTest` (worked splits; sums; agreement with the credit-note split), `PriceCalculatorTest`,
`LockedBreakdownTest`, `CommissionWaiversTest` (new figures). Feature: settlement, orders, invoices (net + VAT =
gross, DH012), promo / referral credits, free relist, market maker, first sale, quote, listings — expectations
moved to the new figures; `Invoices/BuyerInvoiceCommissionLineTest` (lines add up, equal the ledger spread, absent
for a market maker / diamond / crossed rates, old invoices render). Flutter `test/pricing_test.dart` and the invoice
screen.
