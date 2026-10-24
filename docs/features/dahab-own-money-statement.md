# Dahab's own money (external equity statement)

> File: `docs/features/dahab-own-money-statement.md` · Branch: `claude/gold-wallet-deduction-issue-42b998` (Backend, Dashboard)
> Status: done · Date: 2026-10-24

## Goal

Finance and the founders see `external_equity` — the money Dahab owns, outside what customers hold and what
Dahab earned — as a statement: every movement with the balance before and after it, what moved it, and the
first-sale advances it is still fronting. Until now its balance was shown nowhere.

Also shipped on the same branch (2026-10-23): the Dahab wallet view lists the first-sale advance going out and
coming back (`advance` per row, `advance_out` / `advance_back` in the summary), apart from commission and spread.

## Impact Summary

```
Backend:      YES
Database:     NO
API:          YES (non-breaking)
Dashboard:    YES
Customer App: NO
Auth:         NO
Permissions:  NO (wallet.view, as the other views)
```

## What moves external_equity

| Movement | Ledger entry | Direction |
|---|---|---|
| Capital paid in, refund from a supplier | `external_bank_movement` (bank ↔ equity) | in |
| Operating expense, bank charge, profit taken out, something else | `external_bank_movement` | out |
| First-sale advance paid to the seller | `first_sale_payout` | out |
| First-sale advance back from the buyer's payment | `balance_payment` (its equity line) | in |
| Compensation to a customer | `compensation` | out |
| Wallet adjustment by hand (credit / debit) | `reversal` | out / in |

The balance is `SUM(external_equity)`: positive while the owners have put in more than was spent from it.
A written-off advance and a settlement shortfall post nothing — that money simply never comes back.
Profit taken out leaves equity, not `dahab_commission`, so Dahab's own money and its earnings together
(`own_money_total` = equity + commission + spread) is the figure to compare over time.

## API Changes

`GET /dashboard/wallet-statement` and `/export` accept `view=equity` (new enum value on a request parameter —
non-breaking). Rows (grain=each) add `group` (`advance_paid`, `advance_back`, `compensation`,
`wallet_adjustment`, or the bank movement kind), `reference` = the order number, and
`wallet {customer_id, display_ref, full_name}` for the one customer paid or charged. The summary adds
`groups[{key, label, in, out}]`, `earned`, `own_money_total` (at the end of the period), and — as of today, not
the period — `advances_outstanding`, `advances_outstanding_count`, `advances_written_off`, `advances_shortfall`.
The CSV adds one line per group and the advance and total lines.

## Dashboard Impact

Wallet statement: a fourth tab, *Dahab's own money*, with the balance cards, a card per group, the advances
still out and the total with earnings; the table names the customer and the order on each movement.

## Customer App

Not affected — it never reads the staff statement.

## Tests

`tests/Feature/Wallet/WalletStatementTest.php` (the equity view: groups, labels, customers, reconciliation,
month grain), `tests/Feature/FirstSale/AdvanceTest.php` (a real advance shows as paid and still out).
