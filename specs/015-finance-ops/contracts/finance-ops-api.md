# Contract: Finance operations and order export (spec 015)

Planning artefact; the built `#[OA]` attributes and Postman win on disagreement. Envelope, errors, pagination and Cairo dates follow `docs/platform/api-contract.md`. Every POST requires `Idempotency-Key` and is audited. Amounts are decimal strings with 4 places.

## Staff — `/api/v1/dashboard` (auth:staff, staff.standing)

### Compensation

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/compensation` | `compensation.pay` \| `wallet.view` | Query: `from`, `to` (Cairo dates, default last 30 days, ≤ 366 days), `reason`, `paid_by` (staff uuid), `customer_id`, `cursor`, `per_page` (≤ 100). Newest first. |
| GET | `/compensation/export` | same | Same filters, CSV, audited `compensation.list_exported`. |
| POST | `/compensation` | `compensation.pay` | Direct payment (no dispute). |

`GET /compensation` → `200`
```json
{ "data": [ { "id": "uuid", "paid_at": "2026-10-04T10:14:00+03:00", "customer": { "id": "uuid", "display_ref": "4417", "name": "…" },
    "party": "seller|buyer|null", "amount": "800.0000", "reason": "wasted_trip", "note": "…",
    "paid_by": { "id": "uuid", "name": "A. Mostafa" }, "dispute": { "id": "uuid", "ref": "DSP-…" } , "order": { "id": "uuid", "ref": "DH-2026-000123" } } ],
  "meta": { "next_cursor": "…|null", "totals": { "period": "18400.0000", "this_month": "18400.0000" },
    "caps": { "per_payment": "2000.0000", "per_day": "5000.0000", "uncapped": false, "left_today": "1200.0000" } } }
```
`dispute` and `order` may be null. `left_today` is null when `uncapped`; `caps` describe the viewer.

`POST /compensation` body `{ customer_id, amount, reason: igi_delay|dahab_mistake|wasted_trip|dispute_settlement|goodwill, note (10–1000), order_id? }` → `201 { data: <row as above> }`.
Errors: `compensation_cap_exceeded` 403 (`details.per_payment`, `details.left_today`), `verification_required` 403, `validation_failed` 422 (an `order_id` the customer is not party to), `permission_denied` 403.

### Wallet adjustments

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/customers/{customer}/wallet-adjustments` | `wallet.adjust` | `{ direction: credit|debit, amount (> 0, ≤ 4 dp), reason (10–1000) }` → `201` |
| GET | `/wallet-adjustments` | `wallet.adjust` \| `wallet.view` | `from`, `to`, `customer_id`, `cursor`; newest first |

Row: `{ id, adjusted_at, customer {id, display_ref, name}, direction, amount, reason, customer_status, adjusted_by {id, name}, ledger_txn_id }`.
Errors: `insufficient_funds` 409 (`details.available`), `permission_denied` 403, 422.

### Bank movements and bank book

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/uploads` | `bank.record` | multipart `{ purpose: bank_movement_proof, file }` (PDF/JPG/PNG ≤ 10 MB) → `201 { data: { token, expires_in } }` |
| POST | `/bank-movements` | `bank.record` | `{ kind, direction: in|out, amount, occurred_on (≤ today), reason (10–500), proof_upload_token? }` → `201` |
| GET | `/bank-movements` | `bank.record` \| `wallet.view` | Hand-recorded movements: `from`, `to` (by `occurred_on`), `kind`, `cursor`; newest first; `meta.totals {in, out}` |
| GET | `/bank-movements/export` | same | CSV, audited `bank.movements_exported` |
| GET | `/bank-movements/{movement}/proof` | same | Streams the file; audited `bank.movement_proof_viewed`; 404 when none |
| GET | `/bank-book` | same | `from`, `to` (Cairo, ≤ 366 days), `cursor`; oldest first |
| GET | `/bank-book/export` | same | CSV, audited `bank.book_exported` |

Movement row: `{ id, number: "BM-12", kind, direction, amount (positive), occurred_on, reason, has_proof, recorded_by {id, name}, recorded_at, posts_to_ledger }`.

`GET /bank-book` → `{ data: [ { ledger_txn_id, at, kind: topup|withdrawal|external_bank_movement|reversal, direction: in|out, amount, cash_after, actor: { type: staff|system|customer, name }, source: { type: topup, reference: "DAHAB-…", customer_ref, how: matched_notice|credited_by_hand, receiving_account } | { type: withdrawal, number: "WD-…", customer_ref, bank_txn_number } | { type: bank_movement, number, kind, reason, occurred_on, has_proof } | { type: reversal, reverses_txn_id } } ], meta: { summary: { opening, in, out, closing }, next_cursor } }`.

Errors: `upload_token_invalid` 422, 422 validation.

### Daily close

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/daily-close?date=YYYY-MM-DD` | `day.close` \| `wallet.view` | The day's figures (today live, a past day at its cut-off) + the stored row if any + `can_close` |
| GET | `/daily-closes` | same | `from`, `to` (default this month); `meta: { days_closed, days_in_period, last_difference: { date, difference, explanation } }` |
| POST | `/daily-close` | `day.close` | `{ date, bank_balance, explanation? (10–1000) }` → `200` locked or saved |

Day view: `{ date, ended: bool, books: { bank, customer_available, customer_held, customer_liability, dahab_wallet, escrow, vat_payable, movements_in, movements_out }, close: null | { bank_balance, difference, explanation, is_locked, saved_by, saved_at, closed_by, closed_at }, can_close: bool }`.
POST result: the close row plus `state: locked|saved`. Errors: `day_not_ended` 422, `day_already_closed` 409, 422 (non-zero difference with an explanation shorter than 10 is a validation error; a missing explanation saves unlocked).

### Overview

`GET /overview` (any active staff) → `{ data: { earnings?: { month, commission, spread, total }, orders?: [ { state, count, value, held_now } ], needs_decision?: [ { kind: listing_review|withdrawal|dispute|identity_document|topup_notice|payout_account|extension_request, id, title, subtitle, waiting_since, acting_permission } ], this_month?: { new_sellers, pieces_listed, sold, sell_through_pct, avg_days_to_pay_sellers } } }` — a key is absent when the viewer lacks its permission (R10).

### Orders export

`GET /orders/export` (`order.view`) — `group`, `past_deadline`, `branch_id`, `q` as the list; CSV (BOM; cap 10,000 with a truncation line); `X-Export-Truncated` header; audited `order.list_exported`.

## Customer — `/api/v1/customer/me` (auth:customer)

| Change | Notes |
|---|---|
| `BuyRequestResource` + `deposit_held` | string; what is held on this request now |
| `CustomerOrderResource` + `deposit_held` | string for the buyer, `null` for the seller |
| `GET /wallet/held` (verified gate) | `{ data: { total, items: [ { type: buy_request|order, id, ref, title, state, amount } ] } }`, amount > 0, newest first; `total` = the wallet's `held_on_orders` |

## Public — `/api/v1/reference` (no auth, throttle `public.market`)

- `GET /gold-prices` → `{ data: { price_at, feed_state: live|manual|stale, karats: [ { code: 21, label: "21K", sellers_get, buyers_pay } ] } }`; `price_unavailable` 409 when no usable price.
- `GET /quote?category=gold|gold_with_diamond|diamond&karat=&weight_g=&making_per_g=&asking_price=` → `{ data: { category, gold_value, making_back, asking_price, commission, vat, payout, commission_rate, minimum_applied, indicative: true, price_at } }`; 422 (disabled karat, weight ≤ 0 or > 10000, missing fields per category); `price_unavailable` 409.

## Classification

All additive: new endpoints and optional response fields (non-breaking). Potentially breaking: `compensation` rows may now have no dispute/order (Dashboard dispute views unaffected — they only read rows of their dispute); the permission union grows by three; the upload purpose enum grows by one (staff only).
