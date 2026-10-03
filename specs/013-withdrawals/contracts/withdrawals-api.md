# API contract (planning): payout accounts and withdrawals — spec 013

Planning artefact. The built contract is the code's `#[OA]` attributes → `composer swagger:generate`; the Postman folder "Withdrawals" mirrors it. Envelope, money (4-place strings), keyset pagination and the error shape are as in `docs/platform/api-contract.md`. Every POST except the public confirmation calls needs an `Idempotency-Key`.

## Customer — `/api/v1/customer/me/*` (`auth:customer`)

### `GET /payout-accounts` — gate `verified`

```json
{ "data": {
  "accounts": [ { "id": "uuid", "bank_name": "CIB", "account_name": "Mona Hassan Ibrahim",
                  "number_masked": "•••• 4417", "kind": "iban|account_number",
                  "state": "pending_review|active|refused|removing|removed", "in_use": true,
                  "added_at": "…", "checked_at": "…|null", "refusal_reason": "name_shortened|null",
                  "can": { "use": false, "remove": true, "keep": false } } ],
  "pause": { "until": "2026-10-05T10:00:00+03:00" } | null,
  "recent_changes": [ { "kind": "added|verified|refused|in_use|removal_scheduled|kept|removed|request_cancelled",
                        "account": { "bank_name": "CIB", "number_masked": "•••• 4417" },
                        "at": "…", "by": "you|dahab" } ]
} }
```

Removed accounts appear in `recent_changes` only. `recent_changes`: the last 20.

### `POST /payout-accounts` — gate `trade` · idempotent · throttle `customer.payout_accounts`

Body `{ "bank_name", "account_name", "account_number_or_iban", "declaration_id", "declaration_accepted": true }` → `201 { data: <account> , meta: { pause: null } }`.
Errors: `422 validation_failed` (`account_number_or_iban`: not an Egyptian IBAN with a valid checksum nor 8–20 digits), `422 declaration_required`, `403 verification_required | account_suspended`.

### `POST /payout-accounts/{account}/use` · `/remove` · `/keep` — gate `trade` · idempotent

- `use`: an `active` account not in use → in use; `200 { data: <list as GET> }` with `pause` set when one opened and `meta.cancelled_withdrawals: ["WD-12"]`.
- `remove`: `pending_review → removed`; `active → removed | removing` (a `removing` account keeps `in_use` until removed; new confirmations to it get `payout_account_not_active`; its in-flight withdrawals may still be released).
- `keep`: `removing → active`.
Errors: `409 illegal_payout_account_transition`, `404`, `403 account_suspended`.

### `GET /withdrawals` — gate `verified` · keyset (`cursor`)

Item: `{ "id", "number": "WD-12", "amount", "state": "requested|under_review|released|rejected|cancelled", "on_hold": bool, "hold_message": "…|null", "account": { "bank_name", "number_masked" }, "requested_at", "released_at", "value_date", "rejection_reason", "cancelled_by_change": bool, "can_cancel": bool }`. Filter `state=open|closed`.

### `GET /withdrawals/{withdrawal}` — gate `verified`

The item. Never the staff notes, reviewer or bank transaction number.

### `POST /withdrawals/confirmations` — gate `verified` · idempotent · throttle `customer.withdrawals`

Body `{ "amount": "42000.00", "payout_account_id" }` → `201 { data: { "id", "state": "sent", "amount", "account": {…}, "expires_at", "email_masked": "m•••@email.com" } }`. The link goes by email.
Errors: `409 withdrawals_paused` (`details.pause_until`), `409 payout_account_not_active`, `409 insufficient_funds` (`details { available, shortfall }`), `422`.

### `GET /withdrawals/confirmations/{confirmation}` — gate `verified`

`{ data: { "id", "state": "sent|confirmed|used|expired|replaced", "amount", "account", "expires_at" } }`.

### `POST /withdrawals` — gate `verified` · idempotent · throttle `customer.withdrawals`

Body `{ "confirmation_id", "amount", "payout_account_id" }` → `201 { data: <withdrawal> }`; the wallet shows `available −X`, `pending_withdrawals +X`.
Errors: `403 email_confirmation_required`, `409 withdrawals_paused | payout_account_not_active | insufficient_funds`, `422`.

### `POST /withdrawals/{withdrawal}/cancel` — gate `verified` · idempotent

`requested|under_review → cancelled` → `200 { data: <withdrawal> }`. `409 illegal_withdrawal_transition`.

### `GET /wallet` (changed, additive)

Adds `"pending_withdrawals": "42000.0000"`, `"held_on_orders": "11640.0000"` (`held = held_on_orders + pending_withdrawals`). History rows of kind `withdrawal` carry `reference: "WD-12"`.

## Public — `/api/v1/withdrawal-confirmations/*` (no token; throttle `public.withdrawal_confirmations`)

### `POST /withdrawal-confirmations/read`

Body `{ "token" }` → `200 { data: { "state", "amount", "account_masked": "CIB •••• 4417", "expires_at" } }`. No side effect. `422 confirmation_invalid` for an unknown token.

### `POST /withdrawal-confirmations/confirm`

Body `{ "token" }` → `200 { data: { "state": "confirmed", … } }` (idempotent by nature: confirming a confirmed token answers the same). `422 confirmation_invalid` when unknown, expired, replaced or used.

## Dashboard — `/api/v1/dashboard/*` (`auth:staff`)

### `GET /withdrawals` — `withdrawal.release` or `wallet.view` (read-only: no `can` actions, no export, numbers masked)

Query: `state` (csv of `requested,under_review,released,rejected,cancelled`; default `requested,under_review`), `held=1`, `from`, `to` (Cairo dates), `q`, `customer_id`, `cursor`, `per_page`.
Item: `{ "id", "number", "amount", "state", "requested_at", "review_started_at", "reviewer": StaffRef|null, "hold": { "reason", "message", "note", "by", "at" }|null, "customer": { "id", "display_ref", "full_name", "available" }, "account": { "id", "bank_name", "account_name", "number" (full for withdrawal.release / payout_account.verify, else masked), "verified_at", "verified_by", "in_use" }, "signals": { "identity_verified", "suspended", "account_added_at", "first_payout_to_account", "payouts_to_account", "in_use_changed_at", "completed_sales", "topped_up_never_traded" }, "released_at", "bank_txn_number", "transfer_reference", "value_date", "rejection": { "reason", "note" }|null, "can": { "review", "hold", "unhold", "release", "reject" } }`.
`meta`: `per_page`, `next_cursor`, `figures { waiting_count, waiting_sum, released_today_count, released_today_sum, on_hold_count, avg_hours_to_release }`.

### `GET /withdrawals/export` — `withdrawal.release`

CSV of the filtered list; `X-Export-Truncated` over 50,000; audited.

### `GET /withdrawals/{withdrawal}` — `withdrawal.release` or `wallet.view` (read-only: no `can` actions, numbers masked)

The item plus `history[]` (requested, taken, held, unheld, released/rejected/cancelled with actor and time) and `ledger[]` (the hold, release or return entries).

### `POST /withdrawals/{withdrawal}/review` — `withdrawal.release` · idempotent · audited

`requested → under_review`. `409 illegal_withdrawal_transition`.

### `POST /withdrawals/{withdrawal}/hold` — body `{ reason, message, note }` · `/unhold` — body `{ note? }`

Only `under_review`; `409 illegal_withdrawal_transition` otherwise.

### `POST /withdrawals/{withdrawal}/release` — body `{ bank_txn_number, transfer_reference?, value_date? }`

`under_review`, not held, the account `active` or `removing` and the customer's (a `removing` account becomes `removed` when this was its last un-released withdrawal). Ledger held −X / bank +X. `409 withdrawal_on_hold | illegal_withdrawal_transition | payout_account_not_active`.

### `POST /withdrawals/{withdrawal}/reject` — body `{ reason, note }`

`requested|under_review → rejected`; held → available.

### `GET /payout-accounts` — `payout_account.verify`

Query `state` (default `pending_review`), `q`, `cursor`. Item: `{ "id", "bank_name", "account_name", "number", "state", "in_use", "added_at", "checked": { "by", "at" }|null, "refusal": { "reason", "note" }|null, "customer": { "id", "display_ref", "full_name", "identity_verified", "suspended" } }`.

### `POST /payout-accounts/{account}/verify` — `payout_account.verify` · idempotent · audited

`pending_review → active` (in use when the customer has none; may cancel and pause per FR-005). `200 { data: <item>, meta: { pause_until, cancelled_withdrawals } }`.

### `POST /payout-accounts/{account}/refuse` — body `{ reason, note }`

`pending_review → refused`.

### `GET /customers/{customer}` (changed, additive)

`StaffCustomerFile` adds `payout_accounts[]` (the staff item without `customer`; number masked unless `payout_account.verify` / `withdrawal.release`) and `withdrawal_pause { until }|null`.

### `GET /customers/{customer}/wallet` and `GET /wallets/overview` (changed, additive)

Add `pending_withdrawals` and `held_on_orders`.

## Error codes (new or newly used)

`email_confirmation_required` 403 · `confirmation_invalid` 422 · `declaration_required` 422 · `withdrawals_paused` 409 · `payout_account_not_active` 409 · `illegal_withdrawal_transition` 409 (DH007) · `illegal_payout_account_transition` 409 (DH008) · `withdrawal_on_hold` 409.
