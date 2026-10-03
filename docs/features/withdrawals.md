# Withdrawals and payout accounts

> File: `docs/features/withdrawals.md` · Branch: `feature/withdrawals` (backend, dashboard; Flutter has no repo)
> Status: built, not committed · Date: 2026-10-03 · Spec Kit: [`specs/013-withdrawals/`](../../specs/013-withdrawals/spec.md)

## Goal

Money out. A verified customer adds bank accounts in their own name; staff check each name against the verified ID.
The customer withdraws to the account in use after confirming from a link emailed to them (Part 1 §2.4). The amount
is held until Finance sends the transfer at Dahab's bank and records it (release), or rejects it. Changing the
account in use cancels any withdrawal not yet released and pauses new ones for `withdrawal.account_change_pause_hours`
(48). The Dashboard gets the Withdrawals page; the Customer App runs Bank accounts and Withdraw on the API.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (11 customer + 2 public + 10 dashboard endpoints; wallet / overview / customer-file fields added)
Dashboard:    YES (Withdrawals page with Payout accounts to check; Customer file panel; held split)
Customer App: YES (Bank accounts, Add a bank account, Payout account, Withdraw with the email step, confirm page)
Auth:         YES (small: one public route pair under the existing bootstrap elevation)
Permissions:  YES (withdrawal.release; payout_account.verify)
```

**Classification**: the new endpoints and the added response fields are non-breaking. Potentially breaking: the
wallet history now has `withdrawal` rows (reference `WD-{n}`); the permission union and the audit events grow.

## Decisions (product owner, 2026-10-03 — spec Clarifications)

| Question | Decision |
|---|---|
| Accounts | Several, exactly one *in use*. Becoming the one in use (not the first time ever) cancels open withdrawals and opens the pause. Adding an account only waits for review. |
| Remove / keep | Prototype rules: cancel a request; remove at once, or `removing` while a withdrawal not yet released goes to it (keeps its in-use flag, takes no new withdrawal, may still receive its in-flight one); *Keep it after all* → active, no pause. |
| Refusal | New final state `refused`, reasons `name_mismatch`, `name_shortened`, `not_in_customer_name`, `details_invalid`, `other`; the customer sees the reason, never the note. |
| Who verifies | `payout_account.verify` (CEO, Finance, Verification): a *Payout accounts to check* tab on Withdrawals, and Verify / Refuse in the Customer file. |
| Email check | Confirm, then submit: a 30-minute single-use link tied to the amount and the account; the page needs a tap (no sign-in); email only (no SMS code). |
| Limits | No fee, no minimum, no maximum beyond available. |
| Suspended | May withdraw to the verified account in use and cancel (verified gate); may not change accounts (trade gate). |
| Pause | Un-left withdrawals are cancelled at the change (`on_hold_account_change` unused); the job tells the customer when the pause ends. No pause re-check at release. |
| Review | Take for review, a hold flag (reason, customer message, note), release, reject (reason + note). The hold record stays after a reject or cancel; "on hold" = under review with a hold. |
| Release | Finance sends the transfer at the bank, then records the bank transaction number (required), the transfer reference and the value date. `released` is final here. |
| Permissions | One `withdrawal.release` (CEO, Finance, never COO) for the queue, review, hold, reject, release, export; `wallet.view` reads the queue only. |
| Held figure | Split everywhere: `held_on_orders` + `pending_withdrawals`. |
| Export / numbers / bank | CSV export, audited; Egyptian IBAN (checksum) or 8–20 digits; bank is free text. |
| Approved additions | `WD-{n}`, `payout_account_change`, `withdrawal_confirmation`, the hold / bank-record / rejection fields, `ended_notified_at`, 4 new error codes, no pause re-check at release (analysis D1). |

## Backend Impact

- **Migration** `2026_10_06_000010_create_withdrawals.php` (see Database).
- **Actions**: `app/Actions/Payouts/Customer/{Add,ListOwn,Use,Remove,Keep}…`, `Payouts/Staff/{ListPayoutAccountsForReview,Verify,Refuse}…`,
  `Withdrawals/Customer/{RequestWithdrawalConfirmation,SubmitWithdrawal,CancelWithdrawal,ListOwnWithdrawals}…`,
  `Withdrawals/Public/ConfirmWithdrawalAction`, `Withdrawals/Staff/{List,Export,TakeForReview,Hold,Release,Reject}…`,
  `Withdrawals/AnnounceEndedPausesAction`; the concern `WorksWithdrawals` (lock order, the only state mover, the
  change that cancels and pauses, the gates, the outbox).
- **Support**: `WithdrawalLedger` (hold / release / return through the money service), `Iban`, `ConfirmationTokens`,
  `KeysetPage`, `WithdrawalListQuery`, `WithdrawalSignals`.
- **Command** `withdrawals:sweep`, every minute.
- **Notifications**: `PayoutNotification` (SMS + email, after commit), `WithdrawalConfirmationNotification` (mail).
- **Changed**: the customer and staff wallet and the overview add `held_on_orders` / `pending_withdrawals`; the
  wallet history gives `withdrawal` rows `WD-{n}`; the Customer file adds `payout_accounts` and `withdrawal_pause`.
- **Seeder**: `LocalWithdrawalSeeder` (Hoda 900006: every withdrawal state and an account to check; Karim 900007:
  an announced old pause, a refused account and a pause open now).

## Database Impact

- **Enums**: `withdrawal_state` (schema), `payout_account_state` + `refused`.
- **Tables**: `payout_account` (+ `is_in_use`, refusal, removal), `payout_account_change` (new, append-only),
  `withdrawal_pause` (+ `ended_notified_at`), `withdrawal` (+ `withdrawal_no`, hold / return txn ids, review, hold,
  rejection and bank-record columns), `withdrawal_confirmation` (new), `payout_account_transition` (new),
  `withdrawal_transition` (+ `requested → rejected`).
- **Guards**: DH007 (withdrawal) and DH008 (payout account); deferred `trg_withdrawal_money`; `lt_withdrawal_fk`
  (deferred); forced RLS on the five tables; the legal document `payout_account_declaration` v1.
- **Rollback**: `down()` refuses once a withdrawal exists.

## API Changes

| Method + path | Gate / permission | Notes |
|---|---|---|
| `GET /customer/me/payout-accounts` | verified | accounts (masked), pause, recent changes |
| `POST /customer/me/payout-accounts` | trade | `{bank_name, account_name, account_number_or_iban, declaration_id, declaration_accepted}`; `declaration_required` |
| `POST /customer/me/payout-accounts/{id}/use\|remove\|keep` | trade | `illegal_payout_account_transition`; `use` answers `meta.cancelled_withdrawals` |
| `POST /customer/me/withdrawals/confirmations` · `GET …/{id}` | verified | `withdrawals_paused` (`pause_until`), `payout_account_not_active`, `insufficient_funds` (`available`, `shortfall`) |
| `POST /withdrawal-confirmations/read\|confirm` | public | `{token}`; `confirmation_invalid` |
| `POST /customer/me/withdrawals` | verified | `{confirmation_id, amount, payout_account_id}`; `email_confirmation_required` |
| `GET /customer/me/withdrawals` · `/{id}` · `POST /{id}/cancel` | verified | `illegal_withdrawal_transition` |
| `GET /dashboard/withdrawals` · `/{id}` | `withdrawal.release` or `wallet.view` | figures, signals, ledger; masked and no actions for `wallet.view` |
| `GET /dashboard/withdrawals/export` | `withdrawal.release` | CSV, audited |
| `POST /dashboard/withdrawals/{id}/review\|hold\|unhold\|release\|reject` | `withdrawal.release` | `withdrawal_on_hold`, `payout_account_not_active`, `illegal_withdrawal_transition` |
| `GET /dashboard/payout-accounts` · `POST /{id}/verify\|refuse` | `payout_account.verify` | `illegal_payout_account_transition` |

Every POST except the public pair needs an `Idempotency-Key`.

## Dashboard Impact

See the Step 5 report of this feature: the Withdrawals page (figures, filters, queue with signals, detail, take for
review, hold / unhold, release with the bank record, reject, export) with a *Payout accounts to check* tab; the
Customer file's payout accounts, pause and withdrawals; the held split on the wallet panel and the Overview.

## Customer App Impact

Bank accounts, Add a bank account, Your details → Payout account, Withdraw with the email step (*Waiting* →
*Confirmed*), the `#/withdraw-confirm` page, the wallet's "On its way to your bank" and withdrawals, EN/AR.

## Authentication / Authorization

Customer surface (`auth:customer`, verified or trade gate); public pair (no token, `db.elevate:bootstrap`, throttled,
the token's HMAC finds one row); Dashboard surface (`auth:staff`, permission codes, never role names).

## Permissions

| Code | Seeded to |
|---|---|
| `withdrawal.release` | CEO, Finance (never COO) |
| `payout_account.verify` | CEO, Finance, Verification |

## Validation

Amount: > 0, at most 2 decimals. Account: bank 2–80, holder 3–120, Egyptian IBAN (mod-97) or 8–20 digits. Staff:
reasons from the lists, notes 3–1000, hold message 3–500, bank transaction number 3–64, value date not in the future.

## Error Handling

New: `email_confirmation_required` 403 (now used), `confirmation_invalid` 422, `declaration_required` 422,
`withdrawals_paused` 409, `payout_account_not_active` 409, `withdrawal_on_hold` 409, `illegal_withdrawal_transition`
409 (DH007), `illegal_payout_account_transition` 409 (DH008).

## Follow-ups

`released → settled` and bounced transfers; the design's *Transfer file*; "Stop everything" stopping withdrawals;
cap/pattern alerts (OI-3.4); open-question §5 (manual review at scale); email change; daily close and bank movements;
the dispute and "weight short" signals.
