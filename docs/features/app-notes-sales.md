# App notes on sales (2026-10-26)

> File: `docs/features/app-notes-sales.md` · Branch: `claude/app-notes-sales-1d1172` (Backend), `claude/app-notes-sales` (Dashboard, Customer App)
> Status: done (uncommitted) · Date: 2026-10-26

## Goal

Ten owner notes from testing the sale flow, on the customer app and the Dashboard:

| # | Note | What changed |
|---|------|--------------|
| 1 | The seller should not see who sent a buy request | The seller's line no longer shows "From buyer" (app only; the API is unchanged) |
| 2 | While the seller waits for the buyer to pay, show what they will receive | `seller_estimate` on the seller's order detail; *What you will receive* on the order screen |
| 3, 4 | The reference on a wallet line must be the order number; a compensation must always name its order | `reference` = the order number on every wallet line of an order (app history, PDF, Dashboard statement); `order_id` required on `POST /dashboard/compensation`, picked in the form from `GET /dashboard/compensation/orders` |
| 5 | Write "including VAT" on the commission, also for diamond and mixed pieces | Sell estimate reads *Dahab commission, VAT included* on every category |
| 6 | The inspector must see the certificate and the stone as stated; no certificate-number box | Work list and order detail carry `stone` and `certificates`; the dialog shows them with *Open the certificate*; the number box is gone |
| 7 | A buyer who sent a top-up not yet matched should see it on the pay screen | *Waiting for Dahab to confirm* with each pending / on-hold top-up |
| 8 | A free relist may not be priced above what the buyer paid | Stones: `asking_price` ≤ the order's `final_buyer_total` (gold already capped the making charge); `free_relist.previous_price` |
| 9 | Orders: a relist filter, or closed orders with All | An *All, closed too* chip; picking a free-relist filter looks at every order |
| 10 | Show staff the bank that received or paid the money | `bank {direction, name}` on statement rows and a *Bank* CSV column |

Note 8, confirmed by the owner on 2026-10-27: the stone ceiling mirrors the existing gold rule — *the same or lower*,
never higher than what the buyer paid.

## Impact Summary

```
Backend:      YES
Database:     NO
API:          YES
Dashboard:    YES
Customer App: YES
Auth:         NO
Permissions:  NO (new routes reuse compensation.pay and inspection.enter|order.view)
```

## API Changes

| Endpoint | Change | Class |
|---|---|---|
| `GET /customer/me/orders/{id}` | adds `seller_estimate` (seller, detail, until paid); `free_relist.previous_price` | non-breaking |
| `GET /customer/me/wallet/transactions`, statement PDF | `reference` is the order number when the entry belongs to an order (was null) | non-breaking |
| `GET /dashboard/wallet-statement` (+ export) | `reference` = order number, else TOP-/WD-; new `bank`; CSV *Bank* column after *Reference* | non-breaking (CSV column order shifts) |
| `POST /dashboard/compensation` | `order_id` **required** (422 without, or when not the customer's) | potentially breaking — only the Dashboard calls it, updated here |
| `GET /dashboard/compensation/orders?customer_id=` | new, `compensation.pay` | non-breaking |
| `GET /dashboard/inspections/work-list`, `GET /dashboard/orders/{id}` | add `stone`, `certificates` | non-breaking |
| `GET /dashboard/orders/{order}/certificates/{media}` | new, `inspection.enter|order.view`, stone certificates only | non-breaking |
| `POST /customer/me/orders/{id}/free-relist` | stones: `asking_price` above what the buyer paid → 422 | potentially breaking — the app checks it too |

## Customer App

`SellerEstimate` and `FreeRelistOffer.previousPrice` (`lib/models/order.dart`); the order screen's estimate card and
pending top-ups; the relist form's stone ceiling; the sell estimate's VAT label; the seller's side of a settlement
reads *Paid for a piece you sold*. Texts `order.estimate.*`, `order.pay.pending_topups.*`,
`order.free_relist.form.previous_price|error_price_above_paid` (`app_notes_sales.dart`, Arabic AR-DRAFT) and three
`shared.*` wallet keys; manifest regenerated and copied to `database/data/app_text_keys.json`.

## Tests

Backend `tests/Feature/Order/AppNotesSalesTest.php` (estimate = posted proceeds, order number on wallet lines,
compensation order rules and list, inspector certificate route), plus updated Finance, FreeRelist, Wallet and
OpenAPI tests. Flutter `flows_test.dart`: the estimate and the pending top-up; the buyer ref no longer on the line.
