# Contract: After collection — free relist and rating

Names are **proposed** (spec "Proposed surfaces"); the `#[OA]` attributes become the contract. Envelope, error shape and `Idempotency-Key` behaviour are the platform's (`docs/platform/api-contract.md`). All changes are additive.

## Customer — new

### `POST /api/v1/customer/me/orders/{order}/free-relist`
Gate `customer.gate:trade`; middleware `idempotent` (last); throttle `customer.listings`.
Request (JSON): `making_charge_per_g` (gold only, 0–100000, ≤ 2 decimals) **or** `asking_price` (stones, > 0, ≤ 2 decimals) — exactly the field the piece's category needs; `description` (optional, ≤ 2000); `ownership_legal_doc_id` (required, the current declaration).
`201` → `{ data: { listing: <ListingResource of the new live listing>, order: <CustomerOrderResource> } }`.
Errors: `404` not the buyer / unknown order; `403 account_suspended | account_closed`; `409 illegal_order_transition` (order not completed), `free_relist_expired`, `already_relisted`; `422 branch_options_required`, `ownership_declaration_required`, validation errors; `422 idempotency_key_mismatch`, `400 idempotency_key_required`.
Audit: `order.free_relisted`. Notification: `OrderEvent::FREE_RELISTED` (SMS + email + inbox).

### `POST /api/v1/customer/me/orders/{order}/rating`
Gate `customer.gate:verified`; `idempotent`; throttle `customer.ratings` (10/min).
Request: `stars` (int 1–5, required), `note` (optional string ≤ 500).
`201` → `{ data: { rating: { stars, note, created_at }, order: <CustomerOrderResource> } }`.
Errors: `404` not a party; `403 account_closed`; `409 rating_not_available` (not settled / buyer before completed / cancelled order), `rating_closed` (30 days passed), `already_rated`; `422` validation.
Audit: `order.rated` (no note text). No notification.

## Customer — additive fields on `GET /customer/me/orders` and `/{order}`
```jsonc
"free_relist": {                       // always present
  "status": "none|open|used|expired",
  "ends_at": "2026-10-12T14:00:00+03:00" | null,   // null when none
  "listing_id": "uuid" | null                       // when used
},
"rating": {                            // the caller's own party only
  "can_rate": true,
  "opens_at": "…" | null, "closes_at": "…" | null,
  "given": { "stars": 5, "note": "…" | null, "created_at": "…" } | null
},
"no_fee": false                        // true on the seller's order when the sale was a free relist
```
For the seller of a waived sale `invoice` is `null` and `no_fee` is `true`. No existing field or enum value changes.

## Dashboard — additive

### `GET /api/v1/dashboard/orders` (existing)
New optional query `free_relist` ∈ `open|used|expired` (permission `order.view`, unchanged). Each row gains `free_relist_status`.

### `GET /api/v1/dashboard/orders/{id}` (existing)
New `free_relist { status, ends_at, listing { id, title } | null }` (with `order.view`). New `relisted_from_order { id, order_ref } | null` when the order's listing is a free relist. New `ratings [ { party_role, stars, note, created_at } ]` **only** when the viewer holds `rating.view` (absent otherwise; the key is omitted, not empty).

### `GET /api/v1/dashboard/listings…` (existing list and detail)
Each listing gains `relisted_from_order { id, order_ref } | null`.

### `GET /api/v1/dashboard/customers/{id}/activity` (existing)
Includes `order.free_relisted`; includes `order.rated` only for `rating.view` holders.

### Permission catalogue
`rating.view` ("View ratings"), group Orders, seeded CEO + COO. `GET /dashboard/permissions` lists it; the Dashboard adds it to `src/types/staff.ts`.

## Unchanged by design
`POST /customer/me/orders/{order}/relist` (seller's returned piece), pay-balance, handover endpoints (their bodies and statuses are unchanged; handover's response order resource gains the same optional `free_relist` block), invoices endpoints.

## Error codes added (all 409 unless noted)
`free_relist_expired`, `already_relisted`, `rating_not_available`, `rating_closed`, `already_rated`.
