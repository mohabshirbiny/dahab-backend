# Bank per account, newest first, and app fixes (owner notes of 2026-10-29)

Branch `claude/wallet-ui-updates-e4ac69` (Backend) and `claude/wallet-ui-updates` (Dashboard, Flutter).

## What the owner asked

1. Remove Rapaport from *Where prices come from* (app).
2. The weight / making-charge figure on Sell step 1 can be tapped to type it, but people miss it: add an edit icon.
3. Every list newest first.
4. The bank in its own column in the wallet statement.
5. Pick the bank on every bank movement (in or out); the daily close per bank.
6. *Change making charge* on the piece page: the text is too big (cut to "Change making c…").
7. The logo in the middle of the splash screen.
8. Question: does the seller's take-down work, and putting the piece back on the market?

Decisions (asked 2026-10-29): the banks are Dahab's **receiving accounts** (spec 009); a withdrawal **release must name the account it was paid from**; the close keeps **one statement balance per account, in one close**.

## Impact

```
Backend:          YES — bank on movements / releases, per-account close lines, newest-first lists
Database:         YES — 2026_10_29_000010_bank_per_account
API:              YES — see below
Dashboard:        YES — pickers, close per account, bank columns, newest first
Customer App:     YES — items 1, 2, 6, 7 (no API change for the app)
Auth:             NO
Permissions:      NO new code; GET /dashboard/banks opens with bank.record | wallet.view | withdrawal.release | day.close
API models/types: YES — Dashboard finance and withdrawal types
```

## Rules

- `bank_movement.receiving_account_id` — the account; required except for an own-account transfer, where it is the
  account the money left and may be empty: cash **not assigned** (everything recorded before this change).
  `to_receiving_account_id` — own-account transfers only, required there, not the same as the one left.
- `withdrawal.paid_from_account_id` — set at release (required), fixed once set (DH007).
- Each bank posting belongs to the account its source names: a credited top-up → the account it reached, a released
  withdrawal → paid from, a bank movement → its own; a reversal → the entry it reverses. An own-account transfer posts
  nothing and moves cash between accounts **on its statement date** (so the first close after the deploy can assign
  the older money). The accounts plus "not assigned" always add up to the ledger's bank cash (`BankAccounts`).
- Daily close: `bank_balances` — one statement balance for every account the day asks for (active, or holding money);
  `daily_close_bank` keeps a line per account (typed, books, difference) and one for the not-assigned cash. The day locks
  when every line is 0 and nothing is unassigned, otherwise only with an explanation. `bank_balance` = the sum typed,
  `difference` = total, as before. Lines of a locked day never change.
- Lists newest first: identity documents, listings to review (every state), disputes, requests for more time, payout
  accounts to check, staff withdrawals (+ CSV), the inspections work list, staff buy requests (by request time), the
  Overview's *Needs a decision*, the wallet statement (all / customer / Dahab, + CSV), the customer's statement PDF and
  the bank book (+ CSV). Running balances are unchanged (before / after per row). The Dashboard shows the listing's
  history, its price changes and the order timeline newest first too.

## API (classification)

| Change | Class |
|---|---|
| `GET /dashboard/banks` | Non-breaking (new) |
| `POST /dashboard/bank-movements`: `receiving_account_id` required (except own transfer), `to_receiving_account_id` | Potentially breaking (new required field) — Dashboard updated |
| `POST /dashboard/withdrawals/{id}/release`: `paid_from_account_id` required | Potentially breaking — Dashboard updated |
| `POST /dashboard/daily-close`: `bank_balances[]` replaces `bank_balance` | Breaking for the old body — the Dashboard is the only consumer and ships with it |
| `bank`, `to_bank` on movements; `bank` on bank-book rows; `paid_from` on withdrawals; `banks`, `not_assigned` on `GET daily-close`; `banks` on a close | Non-breaking (new fields) |
| Lists newest first (cursors flipped) | Potentially breaking for anyone relying on the order — Dashboard labels updated |

The customer app is not affected by the API changes.

## Take-down and relist (item 8)

- The seller takes a piece down from **live** or **reserved** (`can_withdraw`; reserved releases the buyers in line and
  refunds their deposits). Not once a buyer is accepted.
- *Put it back on the market* (`POST /customer/me/listings/{id}/relist`) works for a piece the **seller took down
  themself** (`can_relist`): it goes straight back to live, no review. A piece taken down by staff is put back only by
  staff (*Taken down* tab → *Put it back on the market*, `listing.takedown`).
- A piece returned to the seller after an order (`awaiting_seller_return`) is relisted from the order screen.
