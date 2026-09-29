# Wallet Top-up

> File: `docs/features/wallet-topup.md` · Branch: `feature/wallet-topup` (backend, dashboard; Flutter has no repo)
> Status: done (pending review and merge) · Date: 2026-09-29 · Spec Kit: [`specs/009-wallet-topup/`](../../specs/009-wallet-topup/spec.md)

## Goal

The first way money enters Dahab: a **manual transfer**, never a payment gateway.

- A verified, non-suspended customer picks bank transfer, InstaPay or Vodafone Cash. They see Dahab's receiving details and their reference `DAHAB-<display_ref>`, send the money from their own app, optionally attach a receipt, and tap *I've sent the transfer*. That records a **transfer notice**.
- CEO / Finance see it on the Dashboard's *Incoming transfers*. After seeing the money in Dahab's own bank or wallet app, they **match** it: the wallet is credited with what actually arrived. They can also hold, un-hold or reject a notice, or **credit by hand** money that arrived without a notice.
- Dahab's receiving accounts are managed from the Dashboard (*Controls → Receiving accounts*).

This is the first money-moving endpoint, so it closes the spec 008 Constitution Principle V waiver.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (non-breaking: 4 customer + 12 dashboard endpoints, new upload purpose, new error code)
Dashboard:    YES
Customer App: YES
Auth:         NO  (the customer gates `trade` / `verified` are reused)
Permissions:  YES (topup.match, topup.accounts.manage — finance + CEO; never coo)
```

## Decisions (product owner, 2026-09-29)

| Question | Decision |
|---|---|
| How a notice ends | Statuses are pending, on hold, credited, rejected (a fixed reason + a staff note) and cancelled (by the customer, while pending). There is no automatic expiry. |
| Amount differs from the claim | Credit what actually arrived. A note is required when the amounts differ. Exactly one credit per notice; no partial credits. |
| Customer messages | SMS, plus email when the customer has one, on credited (with the amount) and rejected (with the plain reason), sent after commit. Never the staff note. |
| Reference | `DAHAB-` + the customer's existing `display_ref`. Nothing new is stored. |
| Daily limits per method | Display only. |
| Provider fee after filing (2026-09-30) | Each notice keeps the fee its account showed when it was filed (`notice_fee_percent`). The display-only `expected_amount` (claim minus that fee, half-up to piastres) uses the snapshot, so a later fee change never moves an existing notice's estimate. Credits are unchanged: staff credit what actually arrived. |
| Sender name | Not checked automatically. Staff decide on the evidence (reference, a receipt showing the customer's name, phone). This replaces Part 3 §11's "money in only from an account in their own name" for top-ups. |
| Suspended customer | Can't read the receiving details, upload a receipt or submit a notice, but may list and cancel their own pending notices. Staff may credit them (match or by hand) only with the provider's transaction reference (`arrival_reference`), and the audit row records the suspension. |
| Credit by hand | Allowed for verified and active customers, and for verified and suspended ones as above. Refused for customers awaiting verification or rejected, including those suspended from those states. |
| Release | The Receiving accounts page blocks production release: the seeded accounts are fake and local-only. |

## Backend Impact

- The `app/Actions/TopUp/*` Actions: the customer's methods, submit, list and cancel; the staff list, export, show, receipt, match, hold, un-hold, reject and credit by hand; receiving-account create and update. Credits post through `PostLedgerEntryAction` (`topup`: bank −X, customer available +X) inside the caller's transaction.
- Models `TopUp` and `ReceivingAccount`. Enums `TopUpMethod`, `TopUpOrigin`, `TopUpStatus` and `TopUpRejectReason`. `UploadPurpose::TOPUP_RECEIPT`.
- Notifications `TopUpCreditedNotification` and `TopUpRejectedNotification` (SMS + mail, queued, after commit).
- The wallet history and statement fill `reference` = `TOP-{n}` for top-up rows.

## Database Impact

Two migrations (the table set, then the fee snapshot), mirroring the "Top-ups" section of `03_schema_ledger.sql` and the RLS list in `05_schema_security.sql`:
- the types `topup_method`, `topup_origin`, `topup_status` and `topup_reject_reason`;
- `receiving_account`: typed details per method, CHECKed, never deleted;
- `topup`:
  - per-status shape CHECKs;
  - `ledger_txn_id UNIQUE`;
  - `notice_fee_percent`, the fee snapshot taken when a notice is filed (second migration, 2026-09-30; open notices are back-filled from their account);
  - a guard trigger that freezes final states and identity columns (SQLSTATE `DH003`);
  - forced RLS.

## API Changes

All new and non-breaking. See [`specs/009-wallet-topup/contracts/topup-api.md`](../../specs/009-wallet-topup/contracts/topup-api.md).

- **Customer**:
  - `GET /customer/me/wallet/topup-methods` and `POST /customer/me/wallet/topups` 🔑 (gate `trade`);
  - `GET /customer/me/wallet/topups` and `POST /customer/me/wallet/topups/{topup}/cancel` 🔑 (gate `verified`);
  - `POST /customer/me/uploads` with `purpose=topup_receipt` (gate `trade` for this purpose).
- **Dashboard**:
  - `GET /dashboard/topups`, `/export`, `/{topup}` and `/{topup}/receipt`;
  - `POST /dashboard/topups/{topup}/match|hold|unhold|reject` 🔑 and `POST /dashboard/topups` 🔑 (credit by hand), all under `topup.match`;
  - `GET|POST /dashboard/receiving-accounts` and `PATCH /dashboard/receiving-accounts/{account}` (`topup.accounts.manage`; GET also allows `topup.match`).
- Top-ups carry the display-only `expected_amount` (both views); the staff view also has `notice_fee_percent`, the fee snapshot it is based on.
- The new error code `illegal_topup_transition` (409). `reference` is now filled for top-up rows in wallet history and statements.

## Dashboard Impact

- *Money → Incoming transfers*:
  - filters, search, totals and export;
  - a detail drawer with a receipt preview and the expected amount after the provider fee;
  - the match, hold, reject and credit-by-hand forms (the transaction reference is required for suspended customers).
- *Controls → Receiving accounts*: a new page, not in the design, as the product owner asked.
- Both are gated on the new permission strings. The Wallet statement shows `TOP-n`.

## Customer App Impact

- `AddFundsScreen` is live: accounts per method, reference, the provider-fee estimate and daily limit (display only), receipt upload, and submit with an idempotency key.
- A new `TopUpsScreen` lists the customer's notices, with cancel and "about X reaches your wallet" (`expected_amount`).
- `ApiClient` sends `Idempotency-Key`. The models are `ReceivingAccount`, `TopUpMethods` and `TopUp`. The fake backend in `test/flows_test.dart` covers the new endpoints.

## Authentication / Authorization

- **Customer**: `auth:customer` + `customer:access`. `customer.gate:trade` covers the methods, submit and receipt upload; `customer.gate:verified` covers the list and cancel. Everything is RLS-scoped to the customer's own rows.
- **Staff**: `auth:staff` + `staff:access` + `staff.standing` + `staff.permission:*`.

## Permissions

| Code | Label | Seeded to |
|---|---|---|
| `topup.match` | Match an incoming transfer | CEO, Finance |
| `topup.accounts.manage` | Manage Dahab's receiving accounts | CEO, Finance |

The COO is never seeded (Part 1 §4.2). Both are editable from Staff and permissions.

## Validation

The Backend rules are authoritative (contract): amounts > 0 with ≤ 2 decimals; an active receiving account for notices; a note when the credited amount differs from the claim; `arrival_reference` for suspended customers; per-method account details; receipts JPEG/PNG/WebP/PDF. The frontends mirror them for UX only.

## Error Handling

- `verification_required` / `account_suspended` (403) come from the gate.
- `permission_denied` (403).
- `illegal_topup_transition` (409).
- `upload_token_invalid` (422).
- `idempotency_key_required` (400), `idempotency_key_mismatch` (422) and `idempotency_in_progress` (409).
- Validation (422).

## Tests

Pest feature tests through HTTP: schema, methods, submit, receipt upload, list, cancel, isolation, match, concurrency, hold/reject, incoming list, receipt stream, notifications, permissions, credit by hand, receiving accounts, and the wallet history reference. There is also an optional `perf` group. Dashboard: type-check, lint and build. Flutter: analyze and test.
