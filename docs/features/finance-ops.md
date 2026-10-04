# Finance operations and order export

> File: `docs/features/finance-ops.md` · Branch: `feature/finance-ops` (backend, dashboard, flutter)
> Status: built, not committed · Date: 2026-10-04 · Spec Kit: [`specs/015-finance-ops/`](../../specs/015-finance-ops/spec.md)

## Goal

Give Finance and the CEO the tools the Dashboard design draws and the docs define: the Compensation page (every payment,
the caps, and paying outside a dispute), the CEO's wallet adjustment, the bank book (movements outside the app with proof,
and every bank posting), and the daily close against the bank statement. Replace every remaining mock figure on the
Overview with Backend data, export the Orders list, show customers what each order and buy request holds, and put live
gold prices and the seller's quote in the Customer App.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (17 dashboard + 1 customer + 2 public endpoints; deposit_held on requests and orders)
Dashboard:    YES (Compensation, Bank movements, Daily closing; live Overview; Orders export; Adjust wallet in the Customer file)
Customer App: YES (Held on open orders lists each order/request; live rate strip, splash cards, home calculator, sell estimate)
Auth:         NO
Permissions:  YES (wallet.adjust, bank.record, day.close)
```

**Classification**: non-breaking (new endpoints and optional fields). Potentially breaking: compensation rows may have no
dispute/order; the permission union (+3); the upload purpose enum (+1, staff only).

## Decisions (product owner, 2026-10-04 — spec Clarifications)

| Question | Decision |
|---|---|
| Compensation outside a dispute | Yes — `compensation.pay`, the same caps and per-payer lock; dispute, order and party become optional. |
| Wallet adjustment | Credit or debit of available against `external_equity`, kind `reversal` with no reversed entry, a `wallet_adjustment` row; never below zero; `wallet.adjust`, no role (founders). Shown to the customer as *Correction*. |
| Daily close basis | Staff type the statement balance (all Dahab accounts); books = the ledger's bank cash at midnight Cairo; 0 locks; non-zero locks only with an explanation, else saved unlocked. Only an ended day. |
| Bank book | Record movements (the design's seven kinds, optional encrypted proof) and list every bank posting with its source. Own-account transfers are records without an entry. A movement dated on a closed day posts now. |
| Public prices | `GET /reference/gold-prices` and `GET /reference/quote`; the four app parts go live. |

## Backend Impact

- **Migration** `2026_10_08_000010_create_finance_ops.php`.
- **Actions**: `Finance/*` (compensation list/export/direct pay, adjust wallet, staff upload, bank movements, bank book, daily close), `Overview/ShowOverviewAction`, `Orders/Staff/ExportOrdersAction`, `Wallet/ListHeldItemsAction`, `Reference/{ShowGoldPrices,Quote}Action`; `Disputes/PayCompensationAction` generalised.
- **Notifications**: `WalletNotification` (`compensation_paid` for direct payments, `wallet_adjusted`).

## Database Impact

`compensation` (dispute/order/party nullable + CHECKs, the check function), `wallet_adjustment` (forced RLS), `bank_movement`
(+ `bank_movement_no_seq`), `daily_close`; deferred checks DH011; the `daily_close_no_reopen` trigger.

## API Changes

See [`specs/015-finance-ops/contracts/finance-ops-api.md`](../../specs/015-finance-ops/contracts/finance-ops-api.md).

| Method + path | Who |
|---|---|
| `GET /dashboard/compensation` · `/export` | `compensation.pay` or `wallet.view` |
| `POST /dashboard/compensation` | `compensation.pay` |
| `POST /dashboard/customers/{customer}/wallet-adjustments` | `wallet.adjust` |
| `GET /dashboard/wallet-adjustments` | `wallet.adjust` or `wallet.view` |
| `POST /dashboard/uploads` · `POST /dashboard/bank-movements` | `bank.record` |
| `GET /dashboard/bank-movements` · `/export` · `/{movement}/proof` · `GET /dashboard/bank-book` · `/export` | `bank.record` or `wallet.view` |
| `GET /dashboard/daily-close` · `GET /dashboard/daily-closes` | `day.close` or `wallet.view` |
| `POST /dashboard/daily-close` | `day.close` |
| `GET /dashboard/overview` | any active staff (sections by permission) |
| `GET /dashboard/orders/export` | `order.view` |
| `GET /customer/me/wallet/held` | customer, verified |
| `GET /reference/gold-prices` · `GET /reference/quote` | public |

New error codes: `day_not_ended`, `day_already_closed`.

## Dashboard Impact

`src/pages/{compensation,bankbook,closing}/index.vue`, `src/components/finance/*`, the live Overview, the Orders export,
`AdjustWalletModal` in the Customer file; types / services / composables / errors; `src/mock/overview.ts` removed.

## Customer App Impact

The Held screen lists each request/order with its amount; `deposit_held` on request and order screens; prices from the
API in the rate strip, splash cards, home calculator and sell estimate (MOCK flags removed); EN/AR; fake backend and tests.

## Follow-ups

Founder alert on a wallet adjustment (OI-1.3); the first-sale advance; the Rapaport matrix; invoices; promo codes; a
bank-statement import; reversing a specific entry from the Dashboard.
