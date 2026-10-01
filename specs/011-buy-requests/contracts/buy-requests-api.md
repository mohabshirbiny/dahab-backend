# API contract: Buy requests (spec 011)

Base `/api/v1`. Envelope, error shape, cursor pagination (`per_page`, `cursor`, `meta.next_cursor`), money as decimal
strings with 4 places, times ISO 8601 with the Cairo offset — as in `docs/platform/api-contract.md`. Every POST
requires `Idempotency-Key` (UUID); the `idempotent` middleware runs last. Code wins over this file.

## Customer — buyer

### `POST /customer/me/buy-requests` — send a buy request (join the queue)

- **Gate**: `trade` · **Throttle**: `customer.buy_requests` (10/min) · **Idempotent**: required
- **Body**
  ```json
  { "listing_id": "uuid", "confirm_locked_price": "58200.0000", "deposit_legal_doc_id": 2 }
  ```
  `listing_id` required UUID; `confirm_locked_price` required decimal > 0, ≤ 4 dp; `deposit_legal_doc_id` required
  integer.
- **201** `data`: a `BuyRequest` (below).
- **Errors**: 404 `not_found` (listing not visible on the market) · 409 `listing_not_purchasable`,
  `cannot_buy_own_listing`, `already_in_queue`, `price_moved` (`details: { current_price, deposit_amount }`),
  `price_unavailable`, `insufficient_funds` (`details: { deposit_amount, available, shortfall }`) · 422
  `deposit_agreement_required`, `validation_failed` · 403 `verification_required`, `account_suspended`.

### `GET /customer/me/buy-requests` — my requests

- **Gate**: `verified` · Query: `state` (one `buy_request_state`), `listing_id` (UUID), `per_page` 1–100 (20), `cursor`.
- **200** `data: BuyRequest[]`, newest first; `meta.next_cursor`.

### `GET /customer/me/buy-requests/{buyRequest}` — one request

- **Gate**: `verified`. 404 for another customer's request (RLS).

### `POST /customer/me/buy-requests/{buyRequest}/withdraw` — leave the queue

- **Gate**: `verified` (a suspended buyer may leave) · **Idempotent**: required
- **Body** `{ "notify_when_free": true }` (boolean, optional, default false)
- **200** `data: BuyRequest` (state `withdrawn_by_buyer`).
- **Errors**: 409 `not_in_queue`; 404.

### `BuyRequest`

```json
{
  "id": "uuid",
  "state": "queued",
  "listing": {
    "id": "uuid", "category": "gold", "piece_type": { "code": "ring", "name_en": "Ring", "name_ar": "خاتم" },
    "karat": 21, "weight_g": "8.000", "state": "reserved", "queue_count": 2,
    "photo": { "id": "uuid", "url": "…/market/listings/{id}/media/{media}" }
  },
  "queue_position": 7,
  "place_in_line": 2,
  "ahead_count": 1,
  "locked_unit_rate": "6250.0000",
  "locked_total_price": "58200.0000",
  "deposit_amount": "11640.0000",
  "requested_at": "2026-09-30T19:00:00+03:00",
  "seller_reply_deadline": "2026-10-02T19:00:00+03:00",
  "resolved_at": null,
  "notify_when_free": false,
  "order": null
}
```
`place_in_line` / `ahead_count` are null unless `queued`. `order` is set when `accepted`:
`{ "order_ref": "DH-2026-000001", "state": "awaiting_delivery", "branch": { "id": 3, "name_en": "…", "name_ar": "…", "address_en": "…", "address_ar": "…" }, "accepted_at": "…", "reach_branch_deadline": "…", "cancelled_at": null, "cancel_reason": null }`
(`state` `cancelled_staff` with the time and reason after a staff cancellation; the deposit is then back in the
wallet). Rows are read under the buyer's own row-level isolation (research R2).
Never contains the seller. `photo` is the first public photo (null if none is public any more).

## Customer — seller

### `GET /customer/me/listings/{listing}/buy-requests` — the queue on my listing

- **Gate**: `verified` · 404 unless the caller is the seller.
- **200**
  ```json
  {
    "data": [
      { "id": "uuid", "place_in_line": 1, "queue_position": 5, "buyer": { "display_ref": "4417" },
        "locked_total_price": "58200.0000", "requested_at": "…", "seller_reply_deadline": "…", "is_head": true }
    ],
    "meta": { "queue_count": 2, "you_would_receive": "52110.0000", "price_is_indicative": true }
  }
  ```
  Queued requests only, arrival order, not paginated (the queue is small; it is the whole line). The buyer object
  carries `display_ref` only. `you_would_receive` is the spec 010 indicative figure (null when unpriced).

### `POST /customer/me/listings/{listing}/accept` — accept the head of the queue

- **Gate**: `trade` · **Idempotent**: required
- **Body** `{ "buy_request_id": "uuid", "branch_id": 3 }`
- **201** `data`:
  ```json
  { "order": { "order_ref": "DH-2026-000001", "state": "awaiting_delivery", "branch": { … },
               "accepted_at": "…", "reach_branch_deadline": "…", "locked_total_price": "58200.0000" },
    "listing": { …seller ListingResource, state "accepted"… },
    "released_count": 2 }
  ```
- **Errors**: 409 `not_queue_head`, `queue_empty`, `branch_not_in_options`, `buyer_suspended`,
  `branch_hours_unavailable`, `illegal_listing_transition`; 404; 403 `account_suspended`, `verification_required`.

### `POST /customer/me/listings/{listing}/decline` — decline the head of the queue

- **Gate**: `trade` · **Idempotent**: required · **Body** `{ "buy_request_id": "uuid" }`
- **200** `data`: the seller `ListingResource` (state `reserved` or `live`, `queue_count`).
- **Errors**: 409 `not_queue_head`, `queue_empty`; 404.

### Changed: `POST /customer/me/listings/{listing}/withdraw`

Now also from `reserved`: every queued request is released (`released_declined`) and refunded; buyers are told.

### Changed: seller `ListingResource`

Adds `order` (as in `BuyRequest.order`, plus `state`) when the listing is `accepted`, or when it went back to `live` /
`withdrawn` through a staff cancellation (then with `state: "cancelled_staff"` and the reason). `queue_count` already
exists.

## Public

### Changed: `GET /market/listings/{id}`

Adds `deposit_amount` (string or null; `deposit.buyer_pct` of `current_price`, half-up to 2 dp; indicative).

### `GET /reference/legal-documents/deposit_agreement` (existing endpoint, new code)

`{ "id": 2, "code": "deposit_agreement", "version": 1, "body_en": "…", "body_ar": "…" }`.

## Dashboard

### Changed: `GET /dashboard/listings?state=reserved|accepted`

Both states are accepted by the existing `state` filter (most recent first); `meta.counts` gains `reserved` and
`accepted`.

### Changed: `GET /dashboard/listings/{listing}` (any listing permission)

Adds:
```json
"queue": [ { "id": "uuid", "place_in_line": 1, "buyer": { "id": "uuid", "display_ref": "6620" },
             "locked_total_price": "…", "deposit_amount": "…", "requested_at": "…", "seller_reply_deadline": "…" } ],
"order": { "order_ref": "…", "state": "awaiting_delivery", "branch": { … }, "buyer": { "id": "uuid", "display_ref": "6620" },
           "accepted_at": "…", "reach_branch_deadline": "…", "locked_total_price": "…", "deposit_amount": "…" }
```
`can_take_down` is true for `live` and `reserved` with `listing.takedown`.

### Changed: `POST /dashboard/listings/{listing}/takedown`

Now also from `reserved` (queue released and refunded, buyers told). Audit row `listing.taken_down` gains
`released_count` in its details.

### New: `POST /dashboard/orders/{order}/cancel` — cancel an acceptance (research R22)

- **Permission**: `order.cancel` · **Idempotent**: required · **Audited**: `order.cancelled` (category `orders`)
- **Body** `{ "reason": "The seller reported the piece damaged before delivery.", "relist": false }` — `reason`
  required 10–1000 characters; `relist` required boolean.
- **200** `data`: `{ "order": { "order_ref": "…", "state": "cancelled_staff", "cancelled_at": "…", "cancel_reason": "…" },
  "listing": { …staff ListingResource, state "live" or "withdrawn"… }, "refunded": "11640.0000" }`
- **Errors**: 409 `order_not_cancellable` (not `awaiting_delivery`, or a lost race); 403 `permission_denied`
  (audited); 404; 422 `validation_failed`.
- Buyer and seller get `order_cancelled` (SMS + email) after commit. Not a seller cancellation.

The staff listing Resource's `order` gains `can_cancel` (state `awaiting_delivery` and the caller holds
`order.cancel`), and `state`, `cancelled_at`, `cancel_reason` once cancelled.

### Changed: `POST /dashboard/customers/{customer}/suspend` / `reinstate`

Suspend also moves reserved listings to `suspended_hold` and releases their queues (refunds, buyers told); the
response is unchanged.

## Settings

`buyrequest.price_tolerance_pct` appears in `GET /dashboard/settings` (operations group) and is changed with
`PATCH /dashboard/settings/{key}` like every other key.

## Scheduled

`buy-requests:expire` — every minute; releases due queued requests (`released_expired`), refunds, tells the buyer.
