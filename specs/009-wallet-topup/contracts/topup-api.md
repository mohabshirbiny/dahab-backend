# Contract: Wallet Top-up (Customer + Dashboard)

Every endpoint is under `/api/v1`. The envelope and errors follow `docs/platform/api-contract.md`: success is `{ data, meta? }`, errors are `{ message, code, errors? }`. Money in responses is a decimal string with 4 places, as everywhere in the API (`"20000.0000"`, `api-contract.md`); request amounts are decimal strings or numbers with ≤ 2 decimals. Times are ISO-8601 with the Cairo offset. The code, through `#[OA]`, wins over this file.

**Idempotency**: every `POST` marked 🔑 requires `Idempotency-Key: <uuid>` (spec 007 layer): missing → 400 `idempotency_key_required`; same key + different body → 422 `idempotency_key_mismatch`; key in flight → 409 `idempotency_in_progress`; replay → the stored response with `Idempotent-Replayed: true`.

## Shared shapes

**ReceivingAccount (customer view)**

```json
{ "id": 3, "method": "instapay", "label": "InstaPay",
  "details": [ { "key": "instapay_address", "value": "dahab@instapay" } ],
  "daily_limit": "70000.0000", "provider_fee_percent": "0.500", "note": "The fee is charged by InstaPay, not by Dahab." }
```

`details` is ordered per method: bank transfer → `bank_name`, `account_holder`, `account_number`, `iban` (when set); InstaPay → `instapay_address`, `account_holder` (when set); Vodafone Cash → `wallet_number`, `account_holder` (when set). Each row is a stable `key` and its `value`; the Customer App labels the keys in English and Arabic.

**TopUp (customer view)**

```json
{ "id": "uuid", "number": "TOP-128", "method": "instapay", "reference": "DAHAB-004417",
  "status": "pending", "claimed_amount": "20000.0000", "expected_amount": "19900.0000", "credited_amount": null,
  "has_receipt": true, "submitted_at": "…", "credited_at": null,
  "reject_reason": null, "can_cancel": true }
```

`status` ∈ `pending | on_hold | credited | rejected | cancelled`; `reject_reason` ∈ `money_not_received | duplicate_notice | sender_not_accepted | other` (only when rejected). `expected_amount` (added 2026-09-30) is a display-only estimate: the claim minus the provider fee the account showed when the notice was filed (a snapshot, so a later fee change never moves it), half-up to piastres; null when there was no fee or the notice is closed. Staff notes, the arrival reference and receiving-account details are never returned in this shape — Dahab's receiving details reach customers only through `topup-methods` (trade gate).

**TopUp (staff view)** = customer view plus `origin` (`notice|by_hand`), `arrival_reference`, `customer { id, display_ref, full_name, phone, status }`, `notice_fee_percent` (the fee snapshot behind `expected_amount`, 3 decimals, or null), `notice_account` (`{ id, method, label, provider_fee_percent }` or null — the account's *current* fee), `receiving_account` (summary or null), `hold_note`, `held_by { id, name }`, `held_at`, `reject_note`, `rejected_by`, `rejected_at`, `cancelled_at`, `credit_note`, `credited_by`, `ledger_txn_id`, `allowed_actions` (subset of `match|hold|unhold|reject`).

## Customer (`auth:customer`, `abilities:customer:access`)

### `GET /customer/me/wallet/topup-methods` — gate `trade`

**200**: `{ "data": { "reference": "DAHAB-004417", "methods": [ { "method": "bank_transfer", "accounts": [ReceivingAccount…] }, … ] } }` — active accounts only, by `sort_order`; a method without an active account is omitted.
**Errors**: 403 `verification_required`, 403 `account_suspended`.

### `POST /customer/me/uploads` (existing) — new purpose `topup_receipt`

`multipart/form-data`: `purpose = topup_receipt`, `file` (JPEG, PNG, WebP or PDF; ≤ `dahab-identity.max_upload_kb`).
**201**: `{ "data": { "upload_token": "…", "purpose": "topup_receipt", "expires_in": 3600 } }`.
**Errors**: 422 validation; for this purpose 403 `verification_required` / `account_suspended`.

### `POST /customer/me/wallet/topups` 🔑 — gate `trade`, `throttle:customer.topups`

**Body**: `{ "amount": "20000.00", "receiving_account_id": 3, "receipt_upload_token": "…" }` (`receipt_upload_token` optional).
Rules: `amount` required, numeric, > 0, ≤ 2 decimals, ≤ 99,999,999.99; `receiving_account_id` required, an **active** account (an account deactivated while the customer was on the screen → 422; the app refreshes the list); `receipt_upload_token` a valid, unexpired `topup_receipt` token of this customer (single use).
**201**: `{ "data": TopUp }` (status `pending`). No money moves.
**Errors**: 422 validation (incl. inactive account); 422 `upload_token_invalid`; 403 gate codes; 429 throttle.

### `GET /customer/me/wallet/topups?status=&cursor=&per_page=` — gate `verified`

Own notices only (RLS), newest first, keyset `cursor`, `per_page` 1–50 (default 20); optional `status` filter.
**200**: `{ "data": [TopUp…], "meta": { "next_cursor": "…"|null } }`.

### `POST /customer/me/wallet/topups/{topup}/cancel` 🔑 — gate `verified`

**200**: `{ "data": TopUp }` (status `cancelled`).
**Errors**: 404 (not found or not own); 409 `illegal_topup_transition` (not `pending`).

## Dashboard (`auth:staff`, `abilities:staff:access`, `staff.standing`)

### `GET /dashboard/topups?status=&from=&to=&q=&cursor=&per_page=` — `topup.match`

Default `status` = `pending,on_hold` (spec FR-015).

`status` one or more of the five (comma list; default `pending,on_hold`); `from`/`to` Cairo dates on `submitted_at` (default last 30 days); `q` = reference (with or without `DAHAB-`), phone, or name fragment; newest first, keyset.
**200**: `{ "data": [TopUp(staff)…], "meta": { "next_cursor", "totals": { "count": 8, "claimed": "96400.0000" } } }`.

### `GET /dashboard/topups/export?…same filters` — `topup.match`

`text/csv` (UTF-8 with BOM), columns: number, origin, submitted at, customer ref, customer name, phone, method, reference, claimed, status, credited, credited at, receiving account, arrival reference, staff, reject reason. Audited `topup.list_exported`.

### `GET /dashboard/topups/{topup}` — `topup.match` → **200** `{ "data": TopUp(staff) }`

### `GET /dashboard/topups/{topup}/receipt` — `topup.match`

Streams the decrypted receipt with its `Content-Type`, `Cache-Control: no-store`. **404** when the notice has no receipt.

### `POST /dashboard/topups/{topup}/match` 🔑 — `topup.match`

**Body**: `{ "amount": "19900.00", "receiving_account_id": 3, "note": "InstaPay fee taken", "arrival_reference": "IPN-883120" }` — `amount` > 0, ≤ 2 decimals; `receiving_account_id` exists and has the notice's method (inactive allowed); `note` ≤ 1000, **required when `amount` ≠ claimed**; `arrival_reference` ≤ 100, optional, **required when the customer is suspended**.
**200**: `{ "data": TopUp(staff) }` (status `credited`, `ledger_txn_id` set). Effects: ledger `topup` entry (bank −amount, customer available +amount), audit `topup.matched`, SMS/email after commit.
**Errors**: 409 `illegal_topup_transition` (already credited, rejected or cancelled — including a lost race); 422 validation (`note` required on difference; account method mismatch; `arrival_reference` missing for a suspended customer).

### `POST /dashboard/topups/{topup}/hold` 🔑 — `topup.match` · body `{ "note": "…" }` (required, ≤ 1000) → **200** TopUp(staff), status `on_hold`. 409 unless `pending`.

### `POST /dashboard/topups/{topup}/unhold` 🔑 — `topup.match` → **200**, status `pending`. 409 unless `on_hold`.

### `POST /dashboard/topups/{topup}/reject` 🔑 — `topup.match`

**Body**: `{ "reason": "money_not_received", "note": "…" }` (both required). **200**, status `rejected`; audit; SMS/email with the reason after commit. 409 unless `pending` or `on_hold`.

### `POST /dashboard/topups` 🔑 — `topup.match` (credit by hand)

**Body**: `{ "customer_id": "uuid", "amount": "15000.00", "receiving_account_id": 3, "note": "Phone matches 01x…8842, no reference", "arrival_reference": "FT26273ABC91" }` — `customer_id`, `amount`, `receiving_account_id` and `note` required; `arrival_reference` (≤ 100) optional, **required when the customer is suspended**; `method` is taken from the account (inactive accounts allowed).
**201**: `{ "data": TopUp(staff) }` (`origin = by_hand`, status `credited`). Effects as match, audit `topup.credited_by_hand` (records the customer's status and the arrival reference).
**Who**: verified and active → allowed; verified and suspended (from active) → allowed with `arrival_reference`; awaiting verification, rejected, or suspended from those → refused.
**Errors**: 403 `verification_required` (awaiting verification or rejected, including suspended from those states); 422 validation (incl. unknown `customer_id` via `exists`, and `arrival_reference` missing for a suspended customer).

### `GET /dashboard/receiving-accounts?include_inactive=1` — `topup.match|topup.accounts.manage`

**200**: `{ "data": [ { "id", "method", "label", "bank_name", "account_holder", "account_number", "iban", "instapay_address", "wallet_number", "daily_limit", "provider_fee_percent", "customer_note", "sort_order", "is_active", "updated_at", "updated_by": { "id", "name" } } ] }`.

### `POST /dashboard/receiving-accounts` — `topup.accounts.manage`

Body: the fields above except `id`, `updated_*`; per-method required details (data-model). **201**. Audited `receiving_account.created`.

### `PATCH /dashboard/receiving-accounts/{account}` — `topup.accounts.manage`

Any editable field; sending `method` → 422 (`prohibited`: an account's method never changes; create a new account instead). `is_active` toggles visibility. **200**. Audited `receiving_account.updated` with before/after. No `DELETE` route.

## Changed existing responses (non-breaking)

- `GET /customer/me/wallet/transactions` and the Wallet statement rows: `reference` is `"TOP-{n}"` for `topup` rows (was always `null`).
- `GET /dashboard/permissions`: two new codes in group `Money`.

## New error code

| Code | HTTP | When |
|---|---|---|
| `illegal_topup_transition` | 409 | a status move not in the state machine, or a lost race (DB `DH003` maps here) |
