# Contract: Listings (Public + Customer + Dashboard)

Planning artefact — the contract is the Backend code and its `#[OA]` attributes. Every endpoint is under `/api/v1`. Envelope and errors follow `docs/platform/api-contract.md`: success `{ data, meta? }`, errors `{ message, code, errors? }`. Money is a decimal string with 4 places; weight a decimal string with 3; karat an integer code; timestamps ISO 8601 with the Cairo offset.

**Idempotency**: every write marked 🔑 requires `Idempotency-Key: <uuid>` (400 `idempotency_key_required`, 422 `idempotency_key_mismatch`, 409 `idempotency_in_progress`, replay → the stored response with `Idempotent-Replayed: true`).

**Pages**: keyset — `?per_page=&cursor=`; `meta: { per_page, next_cursor }`; malformed cursor → 422.

## Shared shapes

**Media**

```json
{ "id": "uuid", "kind": "photo", "is_private": false, "mime": "image/jpeg", "position": 0,
  "url": "/api/v1/market/listings/{listing}/media/{id}" }
```

`kind` ∈ `photo | video | invoice | stone_certificate`. `url` points at the surface that returned it (market / customer / dashboard). The market never returns `is_private: true` items.

**MarketListing** (list item)

```json
{ "id": "uuid", "category": "gold",
  "piece_type": { "id": 1, "name_en": "Ring", "name_ar": "خاتم" },
  "karat": 21, "weight_g": "8.000", "making_charge_per_g": "250.0000",
  "current_price": "58200.0000", "price_available": true, "price_is_indicative": true,
  "photos": [Media…], "branch_options": [ { "id": 1, "name_en": "…", "name_ar": "…" } ],
  "queue_count": 0, "listed_at": "…", "is_mine": false }
```

- `karat`, `weight_g` are null for pure diamond; `making_charge_per_g` is null unless gold.
- `current_price` is null and `price_available` false when it cannot be computed (gold only).
- `is_mine` is true only with a valid customer token whose customer is the seller. **No seller field exists in this shape.**

**MarketListingDetail** = MarketListing plus `description`, `video` (Media|null), `stone_certificate` (Media|null), `price_parts` (gold: `{ "rate_per_gram": "6975.0000", "gold_value": "55800.0000", "making_total": "2000.0000" }`, else null).

**Listing** (seller view)

```json
{ "id": "uuid", "state": "changes_requested", "category": "gold", "piece_type": {…}, "karat": 21,
  "stated_weight_g": "8.000", "making_charge_per_g": "250.0000", "asking_price": null, "description": "…",
  "media": [Media…], "branch_options": [ {…} ],
  "current_price": "58200.0000", "price_available": true, "price_is_indicative": true,
  "you_would_receive": "57744.0000",
  "staff_message": "The hallmark photo is blurred…", "staff_message_at": "…",
  "created_at": "…", "listed_at": null, "state_changed_at": "…",
  "can_edit": true, "can_submit": true, "can_withdraw": false }
```

- `state` ∈ the `listing_state` values (clients must tolerate unknown ones).
- The weight is `stated_weight_g` here and in the staff view, as in requests; the market shapes use `weight_g` (Part 2 §2).
- `staff_message` is the note of the latest staff move to `changes_requested`, `rejected` or `withdrawn`; null otherwise (and null once resubmitted).
- `you_would_receive` is the calculator's seller proceeds; null when it cannot be computed.

**Listing** (staff view) = seller view without `can_*`, plus:
`seller { id, display_ref, full_name, phone_masked, status }`, `history [ { from_state, to_state, actor: { type: "customer"|"staff", name }, note, changed_at } ]`, `sent_back_count`, `seller_listings_sent_back`, `seller_listings_submitted`, `seller_listing_number`, `can_approve`, `can_request_changes`, `can_reject`, `can_take_down` (state and the caller's permissions).

## Public (no auth; `throttle:public.market`)

### `GET /reference/karats`
**200** `{ "data": [ { "code": 21, "sort_order": 3 } ] }` — enabled karats, display order. No purity, no prices.

### `GET /reference/piece-types`
Query: `category` (optional). **200** `{ "data": [ { "id", "category", "name_en", "name_ar", "typical_min_g", "typical_max_g" } ] }` — enabled only.

### `GET /reference/branches`
**200** `{ "data": [ { "id", "name_en", "name_ar", "address_en", "address_ar" } ] }` — enabled only.

### `GET /reference/legal-documents/{code}`
**200** `{ "data": { "id": 1, "code": "ownership_declaration", "version": 1, "body_en": "…", "body_ar": "…" } }` — the current version. **404** unknown code.

### `GET /market/listings` — scope `market`; optional customer token
Query: `category`, `karat`, `piece_type`, `branch`, `min_g`, `max_g`, `sort` (`newest` default | `price_asc` | `price_desc`), `per_page` (1–100, default 20), `cursor`.
**200** `{ "data": [MarketListing…], "meta": { "per_page", "next_cursor" } }` — state live or reserved only.
**Errors**: 422 `validation_failed`.

### `GET /market/listings/{listing}`
**200** `{ "data": MarketListingDetail }`. **404** `not_found` when the listing is not publicly visible or does not exist.

### `GET /market/listings/{listing}/media/{media}`
The decrypted file, streamed, with its `Content-Type`; `Cache-Control: no-store`. **404** when the listing is not publicly visible or the media is private or not this listing's.

## Customer (`auth:customer`, `abilities:customer:access`)

### `POST /customer/me/uploads` (existing) — new purposes
`purpose` ∈ `listing_photo` (jpg/png/webp ≤ 8 MB) · `listing_video` (mp4/mov/webm ≤ 50 MB) · `listing_invoice`, `stone_certificate` (jpg/png/webp/pdf ≤ 8 MB). Gate `trade` (403 `verification_required` / `account_suspended`). **201** as today.

### `GET /customer/me/listings` — gate `verified`
Query: `state` (optional, one state), `per_page` (1–50, default 20), `cursor`. **200** `{ "data": [Listing…], "meta": {…} }`, newest first.

### `GET /customer/me/listings/{listing}` — gate `verified`
**200** `{ "data": Listing }`. **404** for another customer's listing.

### `GET /customer/me/listings/{listing}/media/{media}` — gate `verified`
The file, streamed, including the private invoice. `Cache-Control: no-store`.

### `POST /customer/me/listings` 🔑 — gate `trade`; `throttle:customer.listings`

```json
{ "category": "gold", "piece_type_id": 1, "karat_code": 21, "stated_weight_g": "8.000",
  "making_charge_per_g": "250.00", "asking_price": null, "description": "…",
  "branch_option_ids": [1, 3],
  "photo_tokens": ["…"], "video_token": null, "invoice_token": null, "stone_certificate_token": null,
  "ownership_declaration_accepted": true, "ownership_legal_doc_id": 1 }
```

**201** `{ "data": Listing }` with `state: "draft"`.
**Errors**: 422 `gold_needs_karat_weight` · 422 `branch_options_required` · 422 `ownership_declaration_required` (not accepted, or not the current version) · 422 `upload_token_invalid` · 422 `validation_failed` (category rules in data-model.md, media limits) · 403 `verification_required` / `account_suspended`.

### `PATCH /customer/me/listings/{listing}` 🔑 — gate `trade`
Any of: `piece_type_id`, `karat_code`, `stated_weight_g`, `making_charge_per_g`, `asking_price`, `description`, `branch_option_ids` (replaces the set), `add_photo_tokens[]`, `remove_media_ids[]`, `photo_order[]` (media ids), `video_token` / `invoice_token` / `stone_certificate_token` (a token replaces, `null` removes, absent leaves). `category` cannot change.
**200** `{ "data": Listing }`. **Errors**: 409 `listing_not_editable`; the create errors; 404.

### `POST /customer/me/listings/{listing}/submit` 🔑 — gate `trade`
`draft | changes_requested → in_review`. **200** `{ "data": Listing }`.
**Errors**: 409 `illegal_listing_transition` · 422 `photo_required` · 422 `branch_options_required` (no named branch is still enabled) · 422 `validation_failed` (`description` 40–2000; karat or piece type turned off).

### `POST /customer/me/listings/{listing}/withdraw` 🔑 — gate `trade`
`live → withdrawn` (final; from `live` only). **200** `{ "data": Listing }`. **Errors**: 409 `illegal_listing_transition`.

## Dashboard (`auth:staff`, `abilities:staff:access`, `staff.standing`)

Read routes: any of `listing.review | listing.request_changes | listing.takedown`.

### `GET /dashboard/listings`
Query: `state` (default `in_review`), `per_page` (1–50, default 25), `cursor`.
**200** `{ "data": [Listing (staff, without history)…], "meta": { "per_page", "next_cursor", "counts": { "in_review", "changes_requested", "approved_today", "rejected" } } }` — `in_review` oldest first, other states newest first.

### `GET /dashboard/listings/{listing}`
**200** `{ "data": Listing (staff) }`.

### `GET /dashboard/listings/{listing}/media/{media}`
The file, streamed, private ones included. `Cache-Control: no-store`.

### `POST /dashboard/listings/{listing}/approve` 🔑 — `listing.review`
`in_review → live`, sets `listed_at`. Body: none. **200** `{ "data": Listing (staff) }`.
**Errors**: 409 `illegal_listing_transition` · 409 `seller_suspended` · 409 `karat_disabled` (the karat was turned off; the listing stays in review). Audit `listing.approved`. Notifies the seller.

### `POST /dashboard/listings/{listing}/request-changes` 🔑 — `listing.request_changes`
Body `{ "message": "…" }` (required, 10–1000). `in_review → changes_requested`. Audit `listing.changes_requested` (reason = message). Notifies the seller with the message.

### `POST /dashboard/listings/{listing}/reject` 🔑 — `listing.review`
Body `{ "reason": "…" }` (required, 10–1000). `in_review → rejected` (final: no later edit, submit or approval). Audit `listing.rejected`. Notifies the seller with the reason.

### `POST /dashboard/listings/{listing}/takedown` 🔑 — `listing.takedown`
Body `{ "reason": "…" }` (required, 10–1000). `live → withdrawn` (final; from `live` only). Audit `listing.taken_down`. Notifies the seller with the reason.

All four: **403** `permission_denied` (audited), **404**, **409** `illegal_listing_transition`, **422** `validation_failed`.

### Changed: `POST /dashboard/customers/{customer}/suspend` and `/reinstate`
Same request and response. Side effect: the customer's `live` listings move to `suspended_hold` (suspend) and back (reinstate).

## Classification

| Change | Class | Consumers |
|---|---|---|
| New Public surface, customer listing endpoints, dashboard listing endpoints | non-breaking | both apps (new use) |
| `UploadPurpose` +4 values (request enum) | non-breaking | Flutter |
| `customer.uploads` limit 10 → 20/min | non-breaking | Flutter |
| `StaffPermission` +3 | potentially breaking (new permission strings) | Dashboard `src/types/staff.ts` |
| `AuditCategory` + `listings`, `AuditEvent` +4 | potentially breaking (response enum) | Dashboard audit filter / labels |
| Suspend / reinstate side effect | non-breaking (behaviour) | Dashboard customer file |
