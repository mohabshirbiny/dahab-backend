# API contract: spec 022 (planning artefact; the code and generated OpenAPI win on disagreement)

The envelope is as in `docs/platform/api-contract.md`: success `{ "data": … }`, errors `{ "message", "code", "errors"? }` (flat).

## 1. `POST /customer/me/listings/{listing}/price` (new)

- **Middleware**: `auth:customer` stack of `/customer/me/*`, `customer.gate:trade` (verified, not suspended), `throttle:customer.listings` (20 / min per customer, shared with creating listings), `idempotent` (last). `{listing}` is a UUID (`whereUuid`).
- **Header**: `Idempotency-Key` (required, as every listing write).

### Request

| Field | Category | Rule |
|---|---|---|
| `making_charge_per_g` | gold only | required for gold; `numeric`, at most 2 decimals, `>= 0`, `<= 99999999.99`; must differ from the current figure; `prohibited` for the other categories |
| `asking_price` | diamond, gold_with_diamond | required for those; `numeric`, at most 2 decimals, `> 0`, `<= 99999999.99`; must differ from the current figure; `prohibited` for gold |

```json
{ "making_charge_per_g": "220.00" }
```

### Answer `200`

The seller's `CustomerListing` (as `GET /customer/me/listings/{id}`), with the new figure, the recalculated `current_price` and `you_would_receive`, and the new field `can_change_price`:

```json
{ "data": { "id": "…", "state": "live", "category": "gold", "making_charge_per_g": "220.0000",
            "current_price": "…", "you_would_receive": "…", "can_change_price": true, "…": "…" } }
```

### Refusals (in the order they are checked)

| Status · code | When |
|---|---|
| 401 | no / bad token |
| 403 `verification_required` · `account_suspended` · `account_closed` | the trade gate (existing) |
| 404 `not_found` | not the caller's listing (RLS) |
| 409 `platform_stopped` | Switches "stop everything" is on (`details` carries the message EN/AR, as for buy requests) — C2 |
| 409 `listing_has_requests` | state `reserved`, `accepted`, `at_inspection`, `settling` or `sold` |
| 409 `listing_not_live` | any other state that is not `live` |
| 409 `listing_price_locked` | the piece is approved for market makers — C3 |
| 422 `validation_failed` | the rules above; `errors.making_charge_per_g` / `errors.asking_price` |
| 429 `too_many_requests` | the limiter |
| 409 `idempotency_*` | the existing `idempotent` middleware answers |

The state and the lock are re-checked under the listing row lock; a lost race answers the same codes.

### Side effects

- `listing.making_charge_per_g` or `listing.asking_price` updated; no state move, `state_changed_at` / `listed_at` unchanged.
- One `listing_price_change` row (seller as actor).
- Audit `listing.price_changed` (actor the seller; entity `listing`; `{listing_id, field, old_value, new_value}`).
- No notification of any kind (D22-2).

## 2. `GET /customer/me/listings` and `GET /customer/me/listings/{listing}` (changed, additive)

New field on every `CustomerListing`: `can_change_price` (boolean) — `state = live` and the price is not locked (C3). Non-breaking.

## 3. `GET /dashboard/listings/{listing}` (changed, additive)

New field on the detail only, `price_history`, oldest first (empty array when none):

```json
"price_history": [
  { "field": "making_charge_per_g", "old_value": "250.0000", "new_value": "220.0000",
    "actor": { "type": "customer", "name": "Mona Adel" }, "changed_at": "2026-10-15T12:04:11+03:00" }
]
```

Permission unchanged (any of `listing.review|listing.request_changes|listing.takedown`). Non-breaking.

## 4. Unchanged, on purpose

- `PATCH /customer/me/listings/{id}` keeps `409 listing_not_editable` for a live piece (C1).
- `/market/listings*`, `/customer/me/saved-pieces*`, `/customer/me/market/listings` show the new `current_price` because they already compute it from the listing; no shape change.
- `GET /reference/quote` is used by the app for the preview; no change.

## 5. Error codes (new)

| Code | Status | Message |
|---|---|---|
| `listing_has_requests` | 409 | Someone has already asked to buy this piece, so its price can't change. |
| `listing_price_locked` | 409 | This piece's price is fixed for now. Contact us if you need to change it. |
| `listing_price_history_immutable` | 409 | Price history cannot be changed. (database backstop only, DH023) |

`listing_not_live` (existing, spec 019) is reused with the message "Only a live piece's price can be changed."

## 6. Classification

| Change | Class |
|---|---|
| New endpoint `POST /customer/me/listings/{listing}/price` | Non-breaking |
| `can_change_price` on `CustomerListing` | Non-breaking (additive) |
| `price_history` on the staff listing detail | Non-breaking (additive) |
| New error codes, used only by the new endpoint (and DH023 backstops nobody reaches through the API) | Non-breaking |
| DH023 now refuses a price-column write outside draft / changes requested / live | Internal; no endpoint wrote those columns in those states |

Consumers: Customer app (`ApiListingsRepository`, `Listing.fromJson`, the piece page, `EditPriceScreen`, the fake backend in `test/test_app.dart`); Dashboard (`listing.service.ts`, `types/listing.ts`, `ListingReviewPanel.vue`).
