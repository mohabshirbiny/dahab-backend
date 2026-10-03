# Dahab — Technical Specification

## Part 2 of 4: API Endpoints

*Senior developer handoff · REST/JSON · Depends on Part 1 (Authentication & Authorization). Every endpoint here inherits the request-context contract, the role/permission matrix, the idempotency requirement and the error contract defined in Part 1 §7, §4, §9 — they are referenced, not restated.*

> **Revision (money-timing + spread).** §7 has been rewritten: settlement (seller proceeds + commission + spread + VAT) now fires at **`pay-balance`**, not at `handover`; the full price transits `escrow` as an **instantaneous pass-through** in that one transaction; `handover` is a physical, zero-money event; tax invoices issue at `pay-balance`. §2 now defines the **sell-side / buy-side / spread** pricing (gold only), computed at `pay-balance` from the IGI-confirmed weight and not stored. §11 sweeps and the open items are updated to match (decisions #3 and #4). All figures within tolerance are recomputed on the **IGI-confirmed weight** (`measured_weight_g`).

---

## 0. Conventions that apply to every endpoint

**Transport.** JSON over HTTPS. `Content-Type: application/json` on every request with a body. All timestamps are RFC 3339 / ISO 8601 with an explicit offset (`2026-09-05T14:03:00+03:00`); the server stores `TIMESTAMPTZ` and returns Africa/Cairo offsets. All money fields are decimal **strings** in the JSON, never floats — `"12500.0000"` — to preserve the `NUMERIC(18,4)` precision the ledger depends on. Weights are decimal strings to 3 places (grams). Karat is an integer code.

**Authentication.** Every protected route carries the session (Part 1 §2.3, §3.3). The server re-reads the live actor row inside the request transaction (Part 1 §7 step 2); token claims are routing hints only. Public browse routes (§2 below) are the only unauthenticated ones. Since spec 010 they form a **Public surface**: `/api/v1/market/*` and `/api/v1/reference/*` ([`specs/010-listings/spec.md`](../../specs/010-listings/spec.md)).

**Authorization.** Staff endpoints declare `permission:` — a code from the permission catalogue; which roles hold it is Dashboard-managed data seeded from Part 1 §4. Customer endpoints declare `gate:` (`trade_allowed`, `verified`, or `none`). Since spec 002 the customer gate is **default-deny**: `none` is only for the allow-list in Part 1 §2.2 (sign-in/session, own profile, identity verification incl. re-submission by a rejected customer, browse; wishlist is out of scope until its feature is implemented); `verified` refuses unverified customers with `403 verification_required`; `trade_allowed` also refuses suspended ones (`403 account_suspended`). The service resolves both before executing (Part 1 §4.4, §7 step 4). There is no Postgres-grant enforcement of staff actions (Part 1 §5.2, spec 002).

**Idempotency.** Every state-creating or money-moving `POST`/`PATCH` **requires** an `Idempotency-Key` header (a client-generated UUID). The server persists the key with the request fingerprint and the response; a replay of the same key returns the original response and never re-executes. This is mandatory, not optional — Part 1 §7 step 5 explains why (a retried "send buy request" must not hold two deposits). Endpoints below are marked `idempotent: required` or `idempotent: n/a` (safe reads).

> **As built (feature `007-customer-file`).** The layer exists: the `idempotent` route middleware (`App\Http\Middleware\EnforceIdempotency`) and the `idempotency_key` table.
> - **Scope** — keys are scoped per actor and route.
> - **Replay** — a response below 500 is stored as sent for 24 h and replayed with `Idempotent-Replayed: true`.
> - **Refusals** — `400 idempotency_key_required` (missing or not a UUID), `422 idempotency_key_mismatch` (the same key with a different request), `409 idempotency_in_progress` (still running; a claim older than 60 s is taken over).
> - **Retry after a failure** — a 5xx lets a retry run again.
> - **Adoption** — so far it guards `POST /dashboard/customers/{id}/suspend` and `/reinstate` only. Adding it to the earlier state-creating POSTs is a follow-up.
>
> Contract: `docs/platform/api-contract.md` "Idempotency".

**Audit.** Endpoints that write to the audit log declare `audited: yes` and `reason: required|optional|none` (Part 1 §6). The handler writes the `audit_log` row *inside the same transaction* as its main effect.

**The one-transaction rule.** Any endpoint that moves money writes its `ledger_transaction` + balanced `ledger_posting` set, its audit row, and its state change in a single database transaction (Part 1 §7). The deferred constraints (`trg_txn_balanced`, `trg_customer_nonneg`) fire at commit; a partial success is impossible. Where an endpoint below lists "ledger postings", those postings are written by the money service as one balanced set — never ad hoc.

**Standard error envelope.** Every non-2xx response is:
```json
{ "error": { "code": "not_verified", "message": "…", "details": { } } }
```
`code` is stable and machine-readable. Auth codes are in Part 1 §9; domain codes are defined per endpoint below and collected in the appendix (§12).

**Pagination.** List endpoints use keyset pagination: `?limit=N&cursor=<opaque>`. Responses carry `{ "items": [...], "next_cursor": "…"|null }`. Default `limit` 20, max 100.

**Versioning.** All routes are prefixed `/v1`. The prefix is omitted in the headings below for brevity.

---

## 1. Sessions & account (opening the door)

These implement Part 1 §2 (customer credential model) and §2.3 (device recognition). Auth mechanics live in Part 1; here are the wire contracts.

> **Paths and guards (amended with feature `001-auth-customer-staff`).** The customer session endpoints below are served under **`/api/v1/customer/auth/*`** (there is no generic `/api/v1/auth/*`), behind the Sanctum `customer` guard (`auth:customer`). Dashboard endpoints are under `/api/v1/dashboard/*` behind the `staff` guard. Tokens carry exactly one ability — `customer:access`, `customer:refresh`, `staff:access` or `staff:refresh`; access endpoints need the `*:access` ability and `refresh` needs `*:refresh` (wrong ability → `403 forbidden`; the other principal's token → `401 unauthenticated`). The `/me` endpoint of the customer profile is `GET /api/v1/customer/auth/me`. The response shapes and error codes in this section are the design target; `specs/001-auth-customer-staff/contracts/openapi.yaml` records what is implemented (`x-implemented`) and the exact envelopes the API returns today (e.g. the session is `data.session`, and the OTP branch is not built yet).

### `POST /customer/auth/register`
Creates an unverified customer. Registration does **not** verify identity or permit trading (Part 1 §2.2).
- **gate:** none · **idempotent:** required · **audited:** no
- **Body:** `{ "phone": "+2010…", "password": "…", "preferred_lang": "ar"|"en" }`
- **201:** `{ "customer_id", "display_ref", "is_verified": false }`
- **Errors:** `phone_taken` (409), `weak_password` (422), `invalid_phone` (422).
- **Notes:** password hashed Argon2id (Part 1 §2.1); never echoed. A `cust_available` and a `cust_held` account row are created for the customer here (the ledger needs both to exist before any hold). **Built by spec 008** ([`specs/008-ledger-core/spec.md`](../../specs/008-ledger-core/spec.md)): an AFTER INSERT trigger on `customer` creates both in the same transaction, whatever creates the customer; existing customers were backfilled.

- **As built (feature `002-registration-lifecycle`).** Registration is now **three steps**, and the single-step `POST /customer/auth/register` no longer exists:
  1. `POST /customer/auth/register/start` — body `{ phone, password, email?, full_name?, preferred_lang }`. Validates everything, holds it in an encrypted cache entry, sends a phone OTP. `202 { data: { registration_ref, status: "otp_sent", otp_channel: "sms", otp_expires_at, expires_at } }`. **Creates no customer and no draft row.**
  2. `POST /customer/auth/register/verify-otp` — body `{ registration_ref, otp }`. `200 { data: { registration_ref, status: "phone_verified", phone_verified: true, expires_at } }`. Still creates nothing. Errors: `otp_invalid` / `otp_expired` (401), `registration_session_invalid` (410).
  3. `POST /customer/auth/register/complete` — body `{ registration_ref }`. The only step that writes: customer + password + trusted device + token family in one transaction, then the registration-success email and SMS are queued **after commit**. `201 { data: { customer, session } }`. Errors: `registration_phone_unverified` (409), `registration_session_invalid` (410, also on replay — the ref is single-use), `validation_failed` (422) when the phone/email was taken while in flight.
  - A taken phone is still `422 validation_failed` at step 1, not the documented `phone_taken` (409).
  - The `cust_available` / `cust_held` account rows are **not** created — those tables do not exist yet.
  - There is no resend-OTP endpoint yet; `otp.send_cooldown_seconds` and `otp.max_sends_per_hour` remain unused.

### `POST /customer/auth/login`
Phone + password, **both required every time** (Part 1 §2.1, locked decision).
- **gate:** none · **idempotent:** n/a · **audited:** login attempts are recorded for lockout
- **Body:** `{ "phone", "password", "device_fingerprint" }`
- **200 (known device):** `{ "access_token", "refresh_token", "expires_in", "customer": { … } }`
- **202 (new device):** `{ "otp_required": true, "otp_channel": "sms", "challenge_id" }` — session withheld pending OTP (Part 1 §2.3).
- **Errors:** `password_invalid` (401, identical response whether or not the phone exists — Part 1 §9), `locked_out` (429), `account_suspended` (403 — sign-in still allowed for read/withdraw/wind-down; the *trade* gate is what blocks, so login itself returns 200 with a `suspended` flag rather than 403; see note).
- **Note on suspended sign-in:** a suspended customer *can* sign in (Part 1 §2.2). `login` therefore returns 200 with `customer.is_suspended = true`; the block happens at trade endpoints, not here. `account_suspended` at login is reserved for the case where sign-in itself is barred (not currently a state — kept in the enum for symmetry).

### `POST /customer/auth/otp/verify`
- **gate:** none · **idempotent:** required · **audited:** yes (new-device trust recorded)
- **Body:** `{ "challenge_id", "otp", "device_fingerprint" }`
- **200:** issues the session and records the device as trusted.
- **Errors:** `otp_invalid` (401), `otp_expired` (401), `locked_out` (429).

### `POST /customer/auth/refresh` · `POST /customer/auth/logout`
Standard rotating-refresh issue/revoke. `refresh` rotates the refresh token on every use (Part 1 §2.3). `logout` revokes the presented refresh token.

### `POST /customer/auth/password/reset-request` · `POST /customer/auth/password/reset-confirm`
Issues a set-password token to the customer's own channel; no path ever reveals or sets a known password (Part 1 §2.1). `reset-confirm` consumes a single-use token.

### `GET /me`
- **gate:** none (any authenticated customer) · **idempotent:** n/a
- **200:** profile + flags `{ is_verified, is_suspended, suspended_reason?, preferred_lang }`. Wallet figures are **not** here — they are a separate authorized read (§8).

### Identity verification (the trade gate)
Verification is an identity workflow (detailed in Part 3); its customer-facing endpoints:

### `POST /me/identity-document`
Upload an ID or passport for review. Storage is an encrypted object; the row holds a reference, not the image (`identity_document.storage_ref`).
- **gate:** none (must be signed in) · **idempotent:** required · **audited:** yes
- **Body:** `{ "doc_kind": "egyptian_id"|"passport", "upload_token": "…" }` (the image is uploaded to object storage via a pre-signed URL obtained from `POST /me/uploads`; the token references it).
- **201:** `{ "document_id", "status": "pending" }`
- **Errors:** `document_already_pending` (409), `unsupported_doc_kind` (422).
- **Note:** foreign phone + passport is valid; residence is not checked (Part 1 §2.1, open-questions §1). The reviewer flow is admin-side (§11).
- **As built:** `POST /api/v1/customer/me/identity-documents`, body `{ doc_kind, upload_token }`; `201 { data: { document_id, doc_kind, status: "pending", created_at } }`. An unsupported `doc_kind` is `422 unsupported_doc_kind` (its own code, not `validation_failed`); an unknown / expired / already-used / another customer's token is `422 upload_token_invalid`. One pending document per customer; a rejected customer may resubmit. Creating a document does not touch `customer.is_verified`. `Idempotency-Key` is not implemented yet (no idempotency layer exists in the codebase).

### `POST /me/uploads`
Returns a pre-signed URL for a private object (ID image, listing photo, invoice, proxy ID). The bucket is not public; every ID-document *view* is later logged (Part 1 §5.4).
- **gate:** none (signed in) · **idempotent:** n/a
- **Body:** `{ "purpose": "identity"|"listing_photo"|"listing_invoice"|"stone_certificate"|"proxy_id", "content_type" }`
- **200:** `{ "upload_token", "upload_url", "expires_in" }`
- **As built (local private disk, no object store yet):** `POST /api/v1/customer/me/uploads` is `multipart/form-data` (`purpose`, `file`) — the bytes come straight to the API, are encrypted application-side and stored on the private `identity_private` disk (no URL, never served). It answers `201 { data: { upload_token, purpose, expires_in } }` (there is no `upload_url`). The token is single-use, bound to the customer and purpose, and expires unclaimed after `dahab-identity.upload_token_ttl_seconds` (default 1 h). Only `purpose = identity` is accepted until the other purposes have a consumer; only JPEG/PNG/WebP up to 8 MB (content-sniffed, not trusted by extension); limiter `customer.uploads` (10/min). Swapping in S3 pre-signed URLs later changes this endpoint only. Unclaimed objects are not garbage-collected yet.
- **As built (spec 010 — listing media):** four more purposes, all trade-gated (`403 verification_required` / `account_suspended`): `listing_photo` (JPEG/PNG/WebP, ≤ 8 MB), `listing_video` (MP4/MOV/WebM, ≤ 50 MB), `listing_invoice` and `stone_certificate` (JPEG/PNG/WebP/PDF, ≤ 8 MB), content-sniffed. Listing media is encrypted and stored **in chunks** (never whole in memory) and is served back as a stream by the media endpoints in §2 and §3 with `Cache-Control: no-store`. The limiter `customer.uploads` is 20/min.

---

## 2. Browsing (public, no account)

> **Built by spec 010** — see [`specs/010-listings/spec.md`](../../specs/010-listings/spec.md) and [`docs/features/listings.md`](../features/listings.md). The contract is the code; this section is the as-built summary.

Browsing needs no account (Part 1 §2.2). It is served by the read-only `market` database scope and the market response shapes — **no view** (Part 1 §5.3, product-owner decision). A customer access token is optional and only sets `is_mine`.

### `GET /market/listings`
- **gate:** none (unauthenticated allowed) · **idempotent:** n/a · limiter `public.market`
- **Query:** `category`, `karat`, `piece_type`, `branch` (any of the listing's named options), `min_g`, `max_g`, `sort` (`newest`|`price_asc`|`price_desc`), `per_page` (1–100, default 20), `cursor`. There is no `is_market_maker` filter or field until the market-maker module.
- **200:** `{ "data": [ { id, category, piece_type {id, name_en, name_ar}, karat, weight_g, making_charge_per_g, current_price, price_available, price_is_indicative, photos: [media], branch_options: [{id, name_en, name_ar}], queue_count, listed_at, is_mine } ], "meta": { per_page, next_cursor } }` — listings in state `live` or `reserved` only.
- **Price semantics:** the `current_price` shown to a buyer is the **sell-side** price, from the single calculator (Part 3 §2, spec 005): for gold, `buyers_pay(karat) × weight + making charge × weight` at the current gold price (`price_is_indicative: true`); for diamond and gold-with-diamond, the seller's asking price. It is never stored. When it cannot be computed (no gold price, or the karat's prices are inverted) it is `null` with `price_available: false`, and the listing is still returned (last in price order). A price only *locks* when a buy request is placed (§4); the UI labels it indicative until then. A cursor only positions the page; prices are recomputed per page.
- **Spread (gold only).** For the `gold` category, Dahab earns the **spread** = the buy/sell rate difference on the gold value; **diamond and gold-with-diamond carry no spread** (Part 3 §2.3–§2.4). The spread is **computed at `pay-balance` from the IGI-confirmed weight and is not stored** (locked decision).
- **Never exposes:** `seller_id`, the seller's reference, name, phone or email, the private invoice (`listing_media.is_private = true`).

### `GET /market/listings/{id}`
Single public listing detail (same restrictions): adds `description`, `video`, `stone_certificate` and, for gold, `price_parts { rate_per_gram, gold_value, making_total }`. The **stone certificate is public once the listing is live**; only the invoice is private (spec 010 — this replaces "excludes … stone certificate pre-sale").
- **404** if the listing is not in a publicly visible state.

### `GET /market/listings/{id}/media/{media}`
The decrypted file of a public media item of a publicly visible listing, streamed, `Cache-Control: no-store`. **404** otherwise (private media, another listing's media, a listing that left the market).

> **Changed by spec 011** — see [`specs/011-buy-requests/spec.md`](../../specs/011-buy-requests/spec.md). `GET /market/listings/{id}` adds `deposit_amount` (indicative: `deposit.buyer_pct` of `current_price`, half-up to the piastre; null when unpriced), so the app never computes the deposit.

### Reference data for the apps (spec 010)
Unauthenticated, limiter `public.market`: `GET /reference/karats` (enabled karats), `GET /reference/piece-types?category=` (enabled types, EN/AR names, typical weights), `GET /reference/branches` (enabled branches, EN/AR name and address), `GET /reference/legal-documents/{code}` (the current version of a legal text, e.g. `ownership_declaration`).

---

## 3. Selling — listing a piece

> **Built by spec 010** — see [`specs/010-listings/spec.md`](../../specs/010-listings/spec.md). Paths follow the codebase convention: the seller's under `/customer/me/listings*`, staff's under `/dashboard/listings*` (the earlier `/listings`, `/admin/listings` headings are replaced).

Implements the listing lifecycle (`listing_state`) and the locked decision that **branch options are named at listing** (schema §7; open-questions §3). Every state change goes through the allowed moves (`listing_transition`, enforced by the `listing_guard` trigger) and is recorded in `listing_state_change` with its actor and message. Moves in use: `draft → in_review`, `changes_requested → in_review`, `in_review → live | changes_requested | rejected`, `live → withdrawn`, `live ↔ suspended_hold`. **`withdrawn` and `rejected` are final.**

### `POST /customer/me/listings`  (create draft)
- **gate:** `trade_allowed` · **idempotent:** required · **audited:** no (customer action; recorded in the listing history) · limiter `customer.listings`
- **Body:**
  ```json
  {
    "category": "gold"|"diamond"|"gold_with_diamond",
    "piece_type_id": 12,
    "karat_code": 21,                 // required unless pure diamond (prohibited for diamond)
    "stated_weight_g": "15.500",      // required unless pure diamond (prohibited for diamond)
    "making_charge_per_g": "85.00",   // gold only
    "asking_price": "…",              // diamond and gold_with_diamond only
    "description": "…",               // ≤ 2000; 40–2000 required to submit
    "branch_option_ids": [1, 3],      // >=1; the willing set
    "photo_tokens": ["…"], "video_token": "…"|null,
    "invoice_token": "…"|null, "stone_certificate_token": "…"|null,
    "ownership_declaration_accepted": true,
    "ownership_legal_doc_id": 7       // must be the current ownership_declaration
  }
  ```
- **201:** `{ "data": Listing }` with `state: "draft"`.
- **Validation & errors:**
  - `gold_needs_karat_weight` (422) — mirrors the schema CHECK: non-diamond requires karat + weight.
  - `branch_options_required` (422) — at least one branch option; each must be an enabled branch.
  - `ownership_declaration_required` (422) — not accepted, or not the current version; the acceptance is written to both `agreement_acceptance` (context `list_piece`) and `listing_ownership_declaration` in the same transaction.
  - `upload_token_invalid` (422) — a media token that is unknown, expired, used, for another purpose or another customer's.
  - Media limits: ≤ 6 photos, ≤ 1 video, ≤ 1 invoice, ≤ 1 stone certificate.
  - `category_stopped` (409) — reserved for `category_control` (schema §14); not raised until that module exists.
- **Note:** creating a listing also creates its `listing_queue_seq` row (needed before any buy request can take a position).

### `GET /customer/me/listings` · `GET /customer/me/listings/{id}` · `GET /customer/me/listings/{id}/media/{media}`
The seller's own listings (newest first, keyset, optional `state`), one listing, and any of its media (the invoice included).
- **gate:** `verified` (a suspended seller may read) · **idempotent:** n/a
- The listing carries `staff_message` (the latest staff note when changes were requested, it was rejected or it was taken down), `current_price` and `you_would_receive` (the calculator's seller proceeds, indicative).

### `POST /customer/me/listings/{id}/submit`
Moves `draft | changes_requested → in_review` (listing_transition).
- **gate:** `trade_allowed` · **idempotent:** required
- **Errors:** `illegal_listing_transition` (409) if not in `draft`/`changes_requested`; `photo_required` (422) with fewer than 2 photos (gold) or 3 (diamond, gold-with-diamond); `validation_failed` (422) without a 40–2000 character description or when the karat or piece type was turned off; `branch_options_required` (422) when no named branch is still enabled.

### `PATCH /customer/me/listings/{id}`
Edit a `draft` or `changes_requested` listing (e.g. after a reviewer asks for a better photo): any field but `category`, the branch set, and the media (`add_photo_tokens`, `remove_media_ids`, `photo_order`, and `video_token` / `invoice_token` / `stone_certificate_token`: a token replaces, `null` removes).
- **gate:** `trade_allowed` · **idempotent:** required
- **Errors:** `listing_not_editable` (409) if state is not `draft`/`changes_requested`.

### `POST /customer/me/listings/{id}/withdraw`
Seller takes a listing down: `live → withdrawn`. **Final** — a withdrawn piece is sold again only as a new listing. `reserved → withdrawn` (releasing and refunding the queue) is wired by the buy-request module; until then only `live` is accepted.
- **gate:** `trade_allowed` · **idempotent:** required · **audited:** no
- **Errors:** `illegal_listing_transition` (409) if not `live`.

### Staff listing review (Operations / founders)

### `GET /dashboard/listings?state=in_review` · `GET /dashboard/listings/{id}` · `GET /dashboard/listings/{id}/media/{media}`
- **permission:** any of `listing.review`, `listing.request_changes`, `listing.takedown` (CEO/COO/Operations — Part 1 §4.1) · **idempotent:** n/a
- `in_review` is oldest first; `meta.counts` has `in_review`, `changes_requested`, `approved_today`, `rejected`. A listing carries the seller (reference, name, masked phone), its history and the "sent back" counters. Staff connections bypass customer RLS (Part 1 §3.3) and see across sellers.

### `POST /dashboard/listings/{id}/approve`
`in_review → live`. Sets `listed_at`.
- **permission:** `listing.review` · **audited:** yes · **reason:** none · **idempotent:** required
- **Errors:** `illegal_listing_transition` (409); `seller_suspended` (409) while the seller is suspended; `karat_disabled` (409) when the listing's karat was turned off since it was submitted (it stays in review).

### `POST /dashboard/listings/{id}/request-changes`
`in_review → changes_requested`, with a message to the seller (e.g. "clearer hallmark photo").
- **permission:** `listing.request_changes` · **audited:** yes · **reason:** required (the message, 10–1000) · **idempotent:** required

### `POST /dashboard/listings/{id}/reject`  (spec 010)
`in_review → rejected` — a new **final** state — with a reason the seller sees.
- **permission:** `listing.review` · **audited:** yes · **reason:** required (10–1000) · **idempotent:** required

### `POST /dashboard/listings/{id}/takedown`
Take a live listing down (`live → withdrawn`, final). From `reserved` — with the queue released and refunded — once the buy-request module exists.

> **Changed by spec 011** — see [`specs/011-buy-requests/spec.md`](../../specs/011-buy-requests/spec.md). Built: staff take-down and the seller's withdrawal (`POST /customer/me/listings/{id}/withdraw`) also work from `reserved`. Every queued request becomes `released_declined` with its own `deposit_release` refund in the same transaction, and each buyer is told the piece was withdrawn; the take-down audit row carries `released_count`.
- **permission:** `listing.takedown` · **audited:** yes · **reason:** required (10–1000) · **idempotent:** required

**Notifications (spec 010).** Approve, request-changes (with the message), reject (with the reason) and takedown (with the reason) each send the seller an SMS, plus an email when they have one, after commit.

---

## 4. Buying — the queue (buy requests)

> **As built by spec 011** — see [`specs/011-buy-requests/spec.md`](../../specs/011-buy-requests/spec.md) and [`contracts/buy-requests-api.md`](../../specs/011-buy-requests/contracts/buy-requests-api.md). Paths follow the customer surface:
> - **Join** — `POST /customer/me/buy-requests` with `{ listing_id, confirm_locked_price, deposit_legal_doc_id }` (trade gate, `throttle:customer.buy_requests`, Idempotency-Key). The fresh calculator price is locked when the confirmed one is within the setting **`buyrequest.price_tolerance_pct`** (0.5); beyond it `price_moved` (409, `details.current_price`, `details.deposit_amount`). The deposit is `deposit.buyer_pct` of the locked price, **half-up to the piastre**. `insufficient_funds` carries `details.deposit_amount`, `available`, `shortfall`. New refusals: `cannot_buy_own_listing` (409), `price_unavailable` (409, a gold piece with no usable price). The deposit terms are the legal document **`deposit_agreement`** (v1 seeded); a stale id is `deposit_agreement_required` (422); each acceptance is an `agreement_acceptance` (context `buy_request`) linked from `buy_request.deposit_acceptance_id`. `category_paused` is not raised (no `category_control` yet).
> - **Follow / leave** — `GET /customer/me/buy-requests` (filters `state`, `listing_id`; keyset), `GET /customer/me/buy-requests/{id}`, `POST /customer/me/buy-requests/{id}/withdraw` `{ notify_when_free }` (verified gate: a suspended buyer may read and leave). Responses add `place_in_line`, `ahead_count` and, once accepted, `order`.
> - **Seller's queue** — `GET /customer/me/listings/{id}/buy-requests`: the whole line, buyer `display_ref` only, `meta.you_would_receive` (indicative, once for the listing).
> - **Listing state** — `trg_sync_queue` counts only; the application moves `live ↔ reserved` with a history row naming the actor, and a deferred check refuses a commit where they disagree.

This is the most concurrency-sensitive area in the system. It implements the locked queue model (open-questions §3): multiple buyers queue FIFO on one listing; **each holds their own deposit and locks their own price at request time**; the seller accepts the first active in line and all others are released and refunded at once; no standby queue behind the accepted buyer.

### `POST /listings/{id}/buy-requests`  (join the queue)
- **gate:** `trade_allowed` (first-purchase gate fires here) · **idempotent:** required · **audited:** no
- **Body:** `{ "confirm_locked_price": "…", "deposit_agreement_accepted": true, "deposit_legal_doc_id": 9 }`
  - `confirm_locked_price` is the price the buyer saw and agrees to lock. The server recomputes the price from the current rate and **rejects if it has moved beyond a small tolerance** (`price_moved`, 409) so the buyer never locks a stale figure by surprise.
- **201:** `{ "buy_request_id", "queue_position", "locked_unit_rate", "locked_total_price", "deposit_amount", "seller_reply_deadline" }`

**What happens in the one transaction (in order):**
1. Re-read the listing `FOR UPDATE`-style under the listing's queue lock; confirm state is `live` or `reserved` and no blocking `category_control` (`pause_category`/`stop_everything`) is active.
2. Reject if this buyer already holds an active request on this listing — enforced by the partial unique index `one_active_request_per_buyer_listing`; surfaced as `already_in_queue` (409).
3. Take the next `queue_position` from `listing_queue_seq` (the dedicated counter table exists specifically to make this race-free — schema §8).
4. Compute `deposit_amount = deposit.buyer_pct × locked_total_price` (setting `deposit.buyer_pct`, currently 20%).
5. **Ledger postings** (`event_kind = deposit_hold`), one balanced transaction:
   - `cust_available` (buyer) **−deposit**
   - `cust_held` (buyer) **+deposit**
   The money never leaves the buyer; it moves from spendable to reserved. `trg_customer_nonneg` rejects the whole request if the buyer's available balance can't cover it (`insufficient_funds`, 409).
6. Insert the `buy_request` (`state = queued`), storing `deposit_hold_txn_id`, the locked rate/price, and `seller_reply_deadline = now() + deadline.seller_reply_hours` (clock hours — this deadline is in *clock* hours, not working hours; only the reach-branch deadline is working-hours, schema §9).
7. The `trg_sync_queue` trigger flips the listing `live → reserved` and bumps `active_queue_count`.
- **Errors:** `price_moved` (409), `already_in_queue` (409), `insufficient_funds` (409), `listing_not_purchasable` (409, wrong state), `category_paused` (409), `deposit_agreement_required` (422).

### `GET /me/buy-requests` · `GET /me/buy-requests/{id}`
Buyer's own requests with live queue position and countdown to `seller_reply_deadline`.
- **gate:** none (signed in; RLS restricts to `buyer_id`) · **idempotent:** n/a
- **200 item:** `{ buy_request_id, listing_id, state, queue_position, locked_total_price, deposit_amount, seller_reply_deadline, notify_when_free }`
- Implements the buyer-facing queue-position view (open-questions §4, "still to build").

### `POST /me/buy-requests/{id}/withdraw`  (leave the queue)
Buyer leaves the line (`queued → withdrawn_by_buyer`).
- **gate:** none (signed in) · **idempotent:** required · **audited:** no
- **Body:** `{ "notify_when_free": true|false }` — if true, the buyer is notified **only once the piece returns to the market with no active requests at all**, and rejoins at the back if they return (locked decision, open-questions §3). Stored in `buy_request.notify_when_free`.
- **Effect (one transaction):** refund the held deposit — `event_kind = deposit_release`:
  - `cust_held` (buyer) **−deposit**
  - `cust_available` (buyer) **+deposit**
  Set `state = withdrawn_by_buyer`, `resolved_at = now()`. `trg_sync_queue` recounts; if the queue empties, the listing flips `reserved → live`.
- **Errors:** `not_in_queue` (409) if the request is not `queued`.

### The seller's view of the queue
### `GET /listings/{id}/queue`  (seller only)
- **gate:** `trade_allowed`; RLS + an explicit check that the caller is the listing's `seller_id` · **idempotent:** n/a
- **200:** the ordered active requests with **buyer display refs only** (e.g. "4417"), `queue_position`, `locked_total_price`, `requested_at`, `seller_reply_deadline`. Never exposes buyer contact details.

---

## 5. Acceptance & branch selection

> **Changed by spec 012** — see [`specs/012-orders/spec.md`](../../specs/012-orders/spec.md). **Accept** now also locks the seller's per-gram rate on the order (`locked_seller_unit_rate`: `sellers_get` for gold, the unadjusted mid for gold with diamond) and writes the order's first history row; a gold piece that cannot be priced now is refused `price_unavailable` (409). **Seller cancel** is `POST /customer/me/orders/{order}/cancel` (verified gate — a suspended seller winds down), audited `order.seller_cancelled`: `cancelled_seller`, the buyer refunded in full, a `seller_cancellation`, the listing `accepted → withdrawn`; it never suspends — the sweep applies `suspension.cancellations_threshold`. **Change branch** is `POST /dashboard/orders/{order}/change-branch` `{branch_id, reason, extend_to?}` (`order.change_branch`; only while awaiting delivery — `order_not_open`; a named, enabled branch — `branch_not_in_options`; the clock keeps running unless `extend_to` is later — then also an extension). **Extend deadline** is `POST /dashboard/orders/{order}/extend-deadline` `{which: reach_branch|balance|collect, new_deadline, reason}` (`order.extend_deadline`; the deadline running in the order's state — `deadline_not_running`; forward and in the future — `deadline_must_move_forward` 422; its reminder may fire again; a reopened collection window puts the piece back to `sold`). Reason 10–1000 required, no cap, audited, both parties told.

> **As built by spec 011** — see [`specs/011-buy-requests/spec.md`](../../specs/011-buy-requests/spec.md).
> - **Accept** — `POST /customer/me/listings/{id}/accept` `{ buy_request_id, branch_id }` (trade gate, Idempotency-Key). The branch must be one of the listing's options **and enabled** (`branch_not_in_options`). New refusals: `buyer_suspended` (409, the head's buyer is suspended — the seller may decline them) and `branch_hours_unavailable` (409, the resolver finds no working time). The order is created with `order_ref` `DH-YYYY-NNNNNN` (`order_ref_seq`, never resets) and nothing moves it afterwards until the orders module. `201 { order, listing, released_count }`.
> - **Decline** — `POST /customer/me/listings/{id}/decline` `{ buy_request_id }`: the **head only**, no reason; `released_declined` with refund; the next becomes the head; empty → live. Refusals `not_queue_head`, `queue_empty`.
> - **Staff cancel** — `POST /dashboard/orders/{id}/cancel` `{ reason, relist }` (permission `order.cancel`, audited `order.cancelled`, Idempotency-Key): `awaiting_delivery → cancelled_staff`, the buyer's deposit refunded in full, the listing `accepted → live | withdrawn`. `order_not_cancellable` (409) otherwise. Seller cancel, branch change and deadline extension remain the orders module's.

Implements the locked decision: the seller accepts the **first active in line**; the final branch is chosen now from the listing's named options; the reach-branch deadline starts at acceptance and is counted in **working hours** at that branch (schema §9).

### `POST /listings/{id}/accept`  (seller accepts the head of the queue)
- **gate:** `trade_allowed`; caller must be `seller_id` · **idempotent:** required · **audited:** no
- **Body:** `{ "buy_request_id": "…", "branch_id": 3 }`
  - `buy_request_id` **must be the current head** (lowest `queue_position` among `queued`). Accepting anyone else is rejected (`not_queue_head`, 409) — the model has no "pick whoever" (open-questions §3, "seller accepts the first").
  - `branch_id` must be one of the listing's named options — enforced by `trg_order_branch_subset`; surfaced as `branch_not_in_options` (409).

**One transaction, in order:**
1. Lock the listing's queue; confirm the chosen request is the head and still `queued`.
2. Create the `order`: `state = awaiting_delivery`, copy `locked_total_price` from the accepted request, set `branch_id`, `accepted_by = seller_id`, `accepted_at = now()`.
3. Compute `reach_branch_deadline = accepted_at + deadline.reach_branch_working_hours` **in working hours** at `branch_id`, honouring `branch_hours` + `branch_closure` (the working-hours resolver is specified in Part 3; the endpoint calls it, it does not re-implement it).
4. Set the accepted request `queued → accepted`.
5. **Release every other active request at once** (`queued → released_not_chosen`), each with its own `deposit_release` refund transaction (`cust_held −d`, `cust_available +d` per buyer). This is a single logical operation; all refunds and the acceptance commit together.
6. Generate `order_ref` (e.g. `DH-2026-004417`).
7. The listing moves toward `accepted` (listing_transition `reserved → accepted`).
- **201:** `{ "order_id", "order_ref", "state": "awaiting_delivery", "branch": {…}, "reach_branch_deadline" }`
- **Errors:** `not_queue_head` (409), `branch_not_in_options` (409), `illegal_listing_transition` (409), `queue_empty` (409).

### `POST /orders/{id}/seller-cancel`
Seller cancels after accepting (`awaiting_delivery → cancelled_seller`).
- **gate:** `trade_allowed`; caller must be `seller_id` · **idempotent:** required · **audited:** no (but see suspension)
- **Effect:** refund the buyer's held deposit in full; record a `seller_cancellation` row. When a seller's cancellation count reaches `suspension.cancellations_threshold` (setting, currently 2), the listing/seller is suspended — that enforcement is a backend rule (Part 3), triggered from here.
- **Errors:** `illegal_order_transition` (409) — `assert_order_transition` rejects anything not in `order_transition`.

### Admin: change the branch on an open order
### `POST /admin/orders/{id}/change-branch`
Only an admin can change the branch after acceptance; the deadline **keeps running**, and the admin *may* extend it if the new branch is closed (locked decision, open-questions §3; schema `order_branch_change`).
- **permission:** *Change the inspection branch on an open order* (CEO/COO/Operations — Part 1 §4.1) · **audited:** yes · **reason:** required · **idempotent:** required
- **Body:** `{ "to_branch": 5, "extend_to": "2026-…"|null, "reason": "…" }`
  - `to_branch` must still be one of the listing's named options (`trg_order_branch_subset` re-checks on the `branch_id` update).
  - If `extend_to` is provided, an `order_deadline_extension` row (`which = 'reach_branch'`) is written; `extension_moves_forward` CHECK guarantees it only moves forward.
  - An `order_branch_change` row records `from_branch`, `to_branch`, `changed_by`, `reason`.
- **Errors:** `branch_not_in_options` (409), `order_not_open` (409).

### Admin: extend a deadline on request
### `POST /admin/orders/{id}/extend-deadline`
- **permission:** *Extend a deadline on request* (CEO/COO/Operations) · **audited:** yes · **reason:** required · **idempotent:** required
- **Body:** `{ "which": "reach_branch"|"balance"|"collect", "new_deadline": "…", "reason": "…" }`
- Writes `order_deadline_extension`; `extension_moves_forward` enforced.

---

## 6. Inspection & settlement

> **Changed by spec 012** — see [`specs/012-orders/spec.md`](../../specs/012-orders/spec.md). As built: `GET /dashboard/inspections/work-list` (`inspection.enter` | `order.receive` | `order.handover`, no money or names); `POST /dashboard/orders/{order}/receive` (`order.receive`); `POST /dashboard/orders/{order}/inspection-results` (`inspection.enter`) with `measured_karat`, `measured_weight_g`, `measured_stone_grade`, `certificate_number`, `inspector_note`, `is_counterfeit`, `stone_below_claim`, `supersedes_id`. The outcome adds the inspector's two flags: counterfeit → `fake_cancel`, a stone below its claim → `stone_regrade`. Karat or counterfeit: the buyer refunded, the seller suspended (`piece_misrepresented`) by the inspector, the piece returned to the seller with a code and no compensation. A weight adjustment asks the buyer at the price on the measured weight (the locked rates); a stone regrade waits for `POST /dashboard/orders/{order}/propose-price` (`order.price_adjust`). A correction supersedes the latest result only while the order waits for the buyer or the balance, before any decision or payment (`inspection_correction_not_allowed` 409). The buyer's decision is `POST /customer/me/orders/{order}/decision` `{accept, inspection_id}` (verified gate, audited `order.decided`; `price_not_set` before a regrade is priced; a stale `inspection_id` → `inspection_correction_not_allowed`); an adjustment unanswered by `decision_due_deadline` is declined by the sweep (no decision row, no forfeiture).

Implements the immutable-inspection rule and the karat rule (schema §10). Results are entered by the IGI branch account (Part 1 §3.4), which sees no prices, wallets or contact details.

### `GET /igi/orders?state=awaiting_delivery,at_inspection`
The IGI-facing work list, **scoped to the caller's own branch** (`inspection.branch_id = session.branch_id`, Part 1 §3.4).
- **permission:** IGI role; branch-scoped · **idempotent:** n/a
- **200 item:** only inspection-relevant fields — `order_ref`, `piece_type`, `stated_karat`, `stated_weight_g`, category, expected stones. **No price, no buyer/seller identity beyond what handover needs.**

### `POST /igi/orders/{id}/receive`
Marks the piece physically received at the branch (`awaiting_delivery → at_inspection`; listing `accepted → at_inspection`).
- **permission:** IGI; branch-scoped · **audited:** yes (actor = IGI branch account) · **idempotent:** required
- **Errors:** `illegal_order_transition` (409), `wrong_branch` (403 — piece not routed here).

### `POST /igi/orders/{id}/inspection-result`  (the immutable result)
Records what IGI measured. **This row is append-only** (`inspection_no_update` trigger); a correction is a new superseding row, never an edit (Part 1 §3.4, schema §10).
- **permission:** IGI; branch-scoped · **audited:** yes · **reason:** none · **idempotent:** required
- **Body:**
  ```json
  {
    "measured_karat": 21,
    "measured_weight_g": "15.480",
    "measured_stone_grade": "…"|null,
    "certificate_number": "…"|null,
    "inspector_note": "…"|null,
    "supersedes_id": "…"|null      // present only when correcting an earlier result
  }
  ```
- **Server-derived fields (set by the settlement service, not the client):**
  - `karat_mismatch = (stated_karat IS DISTINCT FROM measured_karat)` — the `karat_rule` CHECK enforces this exactly.
  - `weight_diff_pct` from stated vs measured.
  - `outcome` ∈ `pass | weight_adjust | karat_cancel | stone_regrade | fake_cancel`, decided as:
    - **any** karat difference → `karat_cancel` (the `karat_mismatch_forces_cancel` CHECK guarantees a mismatch can only ever carry this outcome). Zero tolerance — this is structural, not a setting (schema §3 note, open-questions §3).
    - weight within `inspection.weight_tolerance_pct` (1.5%) → `pass` (auto-adjust of the minor difference).
    - weight above tolerance → `weight_adjust` (buyer must approve the new price).
    - stone grade differs → `stone_regrade` (buyer must approve).
    - counterfeit → `fake_cancel`.
- **Effects by outcome (one transaction each):**
  - `pass` → order `at_inspection → inspection_passed`; set `balance_due_deadline = now() + deadline.buyer_pay_days` (days). Proceed to §7.
  - `karat_cancel` / `fake_cancel` → order `at_inspection → cancelled_inspection`; **refund the buyer's deposit in full**; **suspend the seller** (karat rule / counterfeit — no penalty charged to the seller beyond suspension, open-questions §3 "decided"). The refund is a `deposit_release`; the suspension is a backend action recording actor + reason.
  - `weight_adjust` / `stone_regrade` → order `at_inspection → weight_adjust_pending`; await the buyer's decision (§6 next).
- **Errors:** `illegal_order_transition` (409), `wrong_branch` (403), `karat_rule_violation` (422 — only if a client tries to submit an inconsistent mismatch/outcome pair; normally impossible via the derived fields).

### `POST /orders/{id}/settlement-decision`  (buyer accepts/declines an adjustment)
Buyer's decision when a weight adjustment or stone regrade needs approval (schema `settlement_decision`).
- **gate:** none (signed in); caller must be `buyer_id` · **idempotent:** required · **audited:** no
- **Body:** `{ "inspection_id": "…", "accept": true|false }`
- **Effect:**
  - accept → order `weight_adjust_pending → awaiting_balance`; record `settlement_decision(buyer_accepted = true, old_price, new_price)`; set `balance_due_deadline`.
  - decline → order `weight_adjust_pending → cancelled_inspection`; **refund the deposit in full** (`deposit_release`); the seller is **not** suspended (this is a price disagreement, not a karat/fake fault).
- **Errors:** `illegal_order_transition` (409), `not_the_buyer` (403).

---

## 7. Balance payment, settlement & collection

> **Changed by spec 012** — see [`specs/012-orders/spec.md`](../../specs/012-orders/spec.md). **Pay balance** is `POST /customer/me/orders/{order}/pay-balance` (verified gate — a suspended buyer may pay), audited `order.paid`. Wallet only, in full, once, before `balance_due_deadline` (`deadline.buyer_pay_days` **calendar** days). One `balance_payment` transaction: buyer available −balance, buyer held −deposit, [buyer available +excess when the total is below the deposit], escrow +total / −total, seller available +proceeds, `dahab_commission`, `vat_payable`, `dahab_spread` (may be negative when the locked rates crossed; zero lines omitted). Figures stored on the order. `insufficient_funds` carries `amount_due`, `available`, `shortfall`; `settlement_not_possible` (409) when proceeds would be ≤ 0. The 6-digit collection code is stored as an HMAC and also encrypted so the buyer reads it in their own order detail (never a list, never staff). **Handover** of a returned piece to its seller is `POST /dashboard/orders/{order}/seller-return/handover` (`order.handover`): a wrong code answers **422** `invalid_collection_code` (not 401, which would sign Dashboard staff out) with `attempts_left`; the fifth locks it 15 minutes (429 `handover_locked`, `retry_after`). The seller may instead relist (`POST /customer/me/orders/{order}/relist`, trade gate; a gold piece takes the IGI-measured karat and weight). The buyer's **handover** is `POST /dashboard/orders/{order}/handover` (`order.handover`, branch-scoped, same code rules): `ready_to_collect → completed`, no money; a piece past its collection window goes back from `uncollected_expired` to `sold` first. Tax invoices are out of scope.

Implements the **locked money-timing decision**: the seller is settled and Dahab takes commission + spread + VAT **at balance payment (`pay-balance`), not at collection.** Collection (`handover`) becomes a **physical handover only, with zero money movement.** The full price still transits `escrow`, but as an **instantaneous pass-through inside the one `pay-balance` transaction** — money enters `escrow` and is distributed out of it in the same atomic step — rather than resting there from payment until collection.

Locked rules that still hold: **commission is never taken from the customer's gold value**; **no paying the seller before the buyer pays** (Dahab carries no credit risk — open-questions §3). The single exception is the first-sale advance (`payout.first_sale_cap_egp`, `event_kind = first_sale_payout`), a distinct capped, promo-gated, audited path specified in Part 3 — it is the *only* case where the seller receives money before the buyer pays, and Dahab recovers the advance from the buyer's later `pay-balance`.

> **Why escrow is still on the path (design note).** The pass-through is deliberate, not vestigial. `escrow` remains the single, well-defined accounting source for a buyer refund in the *paid-but-never-collected* case (`uncollected_expired`, §10 / Part 3) and for any dispute reversal after payment. Keeping the full price transit `escrow` — even instantaneously — means every post-payment reversal has one clean origin account. (Locked decision: pass-through, not direct distribution.)

### `POST /orders/{id}/pay-balance`
Buyer pays the remaining balance; **this is now the settlement event.** The final figures are computed here from the **IGI-confirmed weight** (locked decision: IGI weight is the final basis for all figures within tolerance — see the spread/settlement math in Part 3), not from the seller's stated weight. `locked_total_price` locks the **per-gram price and the formula**, not a frozen final number; the final total is `recompute(locked formula, measured_weight_g)`.

- **gate:** none (signed in); caller must be `buyer_id` · **idempotent:** required · **audited:** no
- **Precondition:** order in `awaiting_balance`; `now() ≤ balance_due_deadline`; an inspection result exists whose `outcome` permits settlement (`pass`, or an approved `weight_adjust`/`stone_regrade` via `settlement_decision`).
- **Amounts (all recomputed on `measured_weight_g`; full derivation in Part 3):**
  - `buyer_total` = the sell-side price (what the buyer pays) = gold value at the **sell-side** corrected rate + making charge (gold), or the seller's fixed asking price (diamond / gold-with-diamond).
  - `balance` = `buyer_total − deposit_already_held`.
  - `seller_proceeds` = the buy-side price (what the seller receives) = gold value at the **buy-side** corrected rate + making charge − commission − VAT.
  - `spread` = `buyer_total − seller_proceeds − commission − VAT` = (sell-side − buy-side) gold value. **Gold category only**; zero for diamond / gold-with-diamond (§7 spread note; Part 3).
  - `commission`, `vat` as defined below; **never from gold value.**
- **Ledger — one balanced transaction, escrow as instantaneous pass-through** (`event_kind = balance_payment` for the inflow leg set, then the distribution legs; written by the money service as one balanced set):
  1. Inflow into escrow:
     - buyer `cust_available` **−balance**
     - buyer `cust_held` **−deposit** (the deposit held since the buy request)
     - `escrow` **+buyer_total**
  2. Distribution out of escrow, same transaction:
     - `escrow` **−buyer_total**
     - seller `cust_available` **+seller_proceeds** (`settlement_seller`)
     - `dahab_commission` **+commission** (`commission`)
     - `dahab_spread` **+spread** (`spread`; gold only, omitted when zero)
     - `vat_payable` **+vat** (`vat`)
  - Net effect on `escrow` is zero within the transaction; the deferred `trg_txn_balanced` sees a single balanced set.
  - **First-sale case:** if a `first_sale_payout` advance was already paid to the seller at receipt (Part 3), the seller's settlement leg here is reduced by the advance already received, so the seller is not paid twice and Dahab recovers its front. The reconciliation math is specified in Part 3.
- **Effect:** order `awaiting_balance → ready_to_collect`; generate the collection code, store only its hash (`collection.code_hash`); set `collect_deadline = now() + deadline.collect_weeks`. **Issue both tax invoices automatically now** (`tax_invoice`, one per `party_role`) — settlement has occurred, so the invoices belong here, not at handover; nobody issues one by hand (open-questions §3); ETA filing is Part 4.
- **200:** `{ "state": "ready_to_collect", "collect_deadline", "buyer_total", "seller_proceeds" }` (the plaintext collection code is delivered to the buyer over their own channel, not returned in a list response).
- **Errors:** `insufficient_funds` (409), `balance_deadline_passed` (409 → the no-pay cancellation path runs as a job, §10), `illegal_order_transition` (409).

### `POST /igi/orders/{id}/handover`  (counter confirmation — physical only)
IGI confirms the physical handover at the counter against the collection code and, for a proxy, the collector's ID (Part 1 §3.4; schema `collection`). **This event moves no money** — settlement already happened at `pay-balance`.
- **permission:** IGI; branch-scoped · **audited:** yes (actor = IGI branch account; `handover_by`) · **idempotent:** required
- **Body:** `{ "collection_code": "…", "is_proxy": false }` or, for proxy, `{ "collection_code", "is_proxy": true, "proxy_name", "proxy_phone", "proxy_id_upload_token" }`
  - Proxy requires the uploaded proxy ID and the buyer's prior proxy authorisation acceptance (recorded in `agreement_acceptance`, context `collection_proxy`); `proxy_needs_details` CHECK enforces the stored fields. **Dahab does not verify the relationship** — liability rests on the buyer's declaration (open-questions §1, *for the legal clinic*).
- **One transaction (no ledger postings):**
  1. Verify the presented code against `collection.code_hash`.
  2. Order `ready_to_collect → completed`; set `completed_at`, `collection.collected_at`, `handover_by`.
  - No `settlement_seller`/`commission`/`spread`/`vat` postings here — they were written at `pay-balance`. A handover that finds the order already `completed` replays idempotently.
- **Errors:** `invalid_collection_code` (401), `illegal_order_transition` (409), `wrong_branch` (403), `proxy_details_missing` (422).

---

## 8. Wallet (customer)

Wallet **balances** for the customer are their own (RLS-scoped) read. This is distinct from staff wallet access, which is a permission seeded to CEO+Finance (Part 1 §5.2). A verified customer always sees their own two figures.

> **Built by spec 008** ([`specs/008-ledger-core/spec.md`](../../specs/008-ledger-core/spec.md)). As-built paths are under the customer surface: `GET /customer/me/wallet` → `{ available, held, total, currency }` and `GET /customer/me/wallet/transactions` (one row per ledger entry touching the customer: `kind`, `created_at`, `available_change`, `held_change`, `available_after`, `held_after`, `reference`; newest first, keyset `cursor`). The running balance is **available**: a hold is money out of available, a release money in, with held shown beside it. `POST /me/wallet/topup` was built by spec 009 as the endpoints below.

### `GET /me/wallet`
- **gate:** `verified` (spec 002; RLS to own rows) · **idempotent:** n/a
- **200:** `{ "available": "…", "held": "…" }` — read from `customer_wallet` (derived from the ledger; never a stored balance).

### `GET /me/wallet/transactions`
Customer's own ledger history (their postings, human-labelled by `event_kind`).
- **gate:** `verified` (spec 002) · **idempotent:** n/a · keyset paginated.

### Top-up (built by spec 009)
> **Changed by spec 009** ([`specs/009-wallet-topup/spec.md`](../../specs/009-wallet-topup/spec.md), contract [`contracts/topup-api.md`](../../specs/009-wallet-topup/contracts/topup-api.md)). Top-up is a **manual transfer**, never a payment gateway. The customer files a notice and moves no money; staff credit it after seeing the money arrive (§9). The ledger legs are unchanged: `event_kind = topup`, `bank −amount`, customer `cust_available +amount` (spec 008 R15: the bank's cash is `−SUM(bank)`).

- `GET /customer/me/wallet/topup-methods` — the active receiving accounts grouped by method, with display-only daily limit and provider-fee text, plus the customer's reference `DAHAB-<display_ref>`. **gate:** `trade_allowed`.
- `POST /customer/me/uploads` with `purpose = topup_receipt` — an optional receipt (JPEG/PNG/WebP/PDF), encrypted on the private uploads disk. **gate:** `trade_allowed` for this purpose.
- `POST /customer/me/wallet/topups` — `{ amount, receiving_account_id, receipt_upload_token? }` → a `pending` notice. **gate:** `trade_allowed` · **idempotent:** required · **audited:** no · throttled.
- `GET /customer/me/wallet/topups` — the customer's own notices (RLS). **gate:** `verified` (a suspended customer may read). Each open notice carries `expected_amount`: the claim minus the provider fee its account showed **when the notice was filed** (snapshot `topup.notice_fee_percent`; a later fee change never moves it), half-up to piastres, display only.
- `POST /customer/me/wallet/topups/{topup}/cancel` — `pending → cancelled`. **gate:** `verified` (a suspended customer may cancel) · **idempotent:** required.
- A suspended customer cannot read the receiving details, upload a receipt or submit a notice (`account_suspended`).

### Payout accounts & withdrawals
Money leaves only to an account in the customer's own name; a payout-account change pauses withdrawals for the setting window (48h) and the email second-check gates every withdrawal (Part 1 §2.4; schema §12).

> **Changed by spec 013** — see [`specs/013-withdrawals/spec.md`](../../specs/013-withdrawals/spec.md) and [`docs/features/withdrawals.md`](../features/withdrawals.md). Built under `/api/v1/customer/me/*`:
> - **Payout accounts** — `GET /payout-accounts` (verified; accounts masked, the open pause, the last 20 changes); `POST /payout-accounts` `{bank_name, account_name, account_number_or_iban, declaration_id, declaration_accepted}` (trade gate; an Egyptian IBAN with a valid checksum or 8–20 digits; the `payout_account_declaration` acceptance is recorded with context `payout_account`; `pending_review`; the customer is told on phone and email; nothing is paused); `POST /payout-accounts/{id}/use|remove|keep` (trade gate). Several accounts, **exactly one in use**: making a different one in use — not the first time ever — cancels every withdrawal not yet released (money back) and opens the pause (`withdrawal.account_change_pause_hours`, none at 0); `remove` cancels a request, removes an account, or makes it `removing` while a withdrawal not yet released goes to it (it keeps its in-use flag and takes no new withdrawal); `keep` returns it to active with no pause.
> - **Withdrawals** (verified gate: a suspended customer may withdraw and cancel) — `POST /withdrawals/confirmations` `{amount, payout_account_id}` (gates first, then the email link, Part 1 §2.4), `GET /withdrawals/confirmations/{id}`, `POST /withdrawals` `{confirmation_id, amount, payout_account_id}` (replaces `email_confirmation_token`), `GET /withdrawals`, `GET /withdrawals/{id}`, `POST /withdrawals/{id}/cancel`. No fee, no minimum or maximum beyond available. Every POST needs an `Idempotency-Key`. The withdrawal reference is `WD-{n}`.
> - **Errors**: `email_confirmation_required` 403, `withdrawals_paused` 409 (`details.pause_until`), `payout_account_not_active` 409, `insufficient_funds` 409 (`details.available`, `details.shortfall`), `illegal_payout_account_transition` 409, `illegal_withdrawal_transition` 409, `declaration_required` 422, `confirmation_invalid` 422 (the public link page).
> - The wallet (`GET /customer/me/wallet`) adds `held_on_orders` and `pending_withdrawals` (held = both).

### `POST /me/payout-accounts`
- **gate:** `verified` (signed in) · **idempotent:** required · **audited:** no
- **Body:** `{ "account_name", "bank_name", "account_number_or_iban" }` — `account_name` must match the verified ID (checked by staff, §11).
- **Effect:** new account `state = pending_review`. **If this replaces/changes an active account, open a `withdrawal_pause`** (`pause_until = now() + withdrawal.account_change_pause_hours`) and cancel any in-flight withdrawal (schema §12) — the pause window is read from settings, never hardcoded (locked rule).
- **201:** `{ "payout_account_id", "state": "pending_review", "withdrawal_pause_until": "…"|null }`

### `POST /me/withdrawals`
Request a withdrawal. **Two gates beyond the session:** the email second-check (Part 1 §2.4) and the account-change pause.
- **gate:** `verified`; **email confirmation required** · **idempotent:** required · **audited:** no
- **Body:** `{ "payout_account_id", "amount", "email_confirmation_token" }`
- **Preconditions & errors:**
  - `email_confirmation_required` (403) if the token is missing/unverified (Part 1 §9).
  - `withdrawals_paused` (409) if an active `withdrawal_pause` covers `now()` (account changed within the window).
  - `payout_account_not_active` (409) if the account is not `active`.
  - `insufficient_funds` (409) — checked against available balance.
- **Effect (one transaction):** move the amount from the customer's `cust_available` into a pending state (a hold), create `withdrawal` in `requested`. **No money leaves the bank yet** — release is a separate, person-reviewed step (§9 admin). The available→hold move keeps the customer from double-spending the pending amount.
- **201:** `{ "withdrawal_id", "state": "requested" }`

### `POST /me/withdrawals/{id}/cancel`
Holder cancels before release (`requested/under_review → cancelled`); the held amount returns to available.
- **gate:** none (signed in) · **idempotent:** required

---

## 9. Admin — money (CEO + Finance by default)

Every endpoint here is wallet-touching: the seed gives these permissions to CEO/Finance only (Part 1 §4.2), and role managers may change that from the Dashboard (spec 002). There is no Postgres grant behind them (Part 1 §5.2). By default the COO, though a founder, lacks them and gets `403 permission_denied`.

> **Wallet reads built by spec 008** ([`specs/008-ledger-core/spec.md`](../../specs/008-ledger-core/spec.md)), permission `wallet.view`: `GET /dashboard/wallets/overview` (available, held, total owed, bank cash, headroom, system total), `GET /dashboard/customers/{customer}/wallet`, `GET /dashboard/wallet-statement` (`view=customer|customers|dahab`, `from`, `to`, `grain=each|day|month`, keyset `cursor`; the one-customer view is audited as `ledger.statement.viewed`) and `GET /dashboard/wallet-statement/export` (CSV, audited as `ledger.statement.exported`). No endpoint in spec 008 moves money.

> **Changed by spec 013** — see [`specs/013-withdrawals/spec.md`](../../specs/013-withdrawals/spec.md). Built under `/api/v1/dashboard/*`: `GET /withdrawals` (oldest first; `state` csv, default requested,under_review; `held`, `from`, `to`, `q`, `customer_id`; keyset; `meta.figures`; each row with the account, its name check and the *Before you release* signals), `GET /withdrawals/export` (CSV, audited), `GET /withdrawals/{id}` (with its ledger entries), and `POST /withdrawals/{id}/review|hold|unhold|release|reject`, all `withdrawal.release` (reads also `wallet.view`, masked, no action). **Hold** is a flag on an under-review withdrawal (reason, a message the customer is told, a staff note); a held withdrawal is not released (`withdrawal_on_hold` 409); the hold record stays after a reject or cancel. **Release** records the transfer a person sent at the bank (`bank_txn_number` required, `transfer_reference`, `value_date`), posts held −X / bank +X, and accepts an account that is `active` or `removing` (its in-flight withdrawal). The pause is **not** re-checked at release: every open withdrawal is cancelled when the account in use changes. **Reject** works from `requested` or `under_review` (new transition `requested → rejected`), with a reason the customer sees and a note they never see. `released` is final here (no `settled`, no bounce). Every POST is idempotent and audited.

### `GET /admin/withdrawals?state=requested,under_review`
The review queue. · **permission:** *View a wallet* / *Release a withdrawal* (CEO/Finance) · **idempotent:** n/a

### `POST /admin/withdrawals/{id}/review`
Claim for review (`requested → under_review`). · **permission:** CEO/Finance · **audited:** yes · **idempotent:** required

### `POST /admin/withdrawals/{id}/release`
Approve and send to bank (`under_review → released`). **Every withdrawal is released by a person** (schema §12).
- **permission:** *Release a withdrawal* — **CEO or Finance** (resolved decision: both may release; Finance is no longer review-only for this action). The COO, though a founder, cannot — it is a wallet-touching action (Part 1 §5.2 grant). Every release is still a person's named action, audited.
- **audited:** yes · **reason:** optional · **idempotent:** required
- **Ledger** (`event_kind = withdrawal`): customer hold **−amount**, `bank` **+amount** (money leaves the bank — **changed by spec 008**, research R15: the lines sum to zero; the bank's cash is `−SUM(bank)`); balanced against the pending-hold account opened at request. Records `release_txn_id`, `released_at`, `reviewed_by`.
- **Errors:** `withdrawals_paused` (409, re-checked at release), `illegal_withdrawal_transition` (409).

### `POST /admin/withdrawals/{id}/reject`
`under_review → rejected`; the held funds return to the customer's available. · **reason:** required · **audited:** yes

### Incoming transfers (built by spec 009 — was `POST /admin/transfers/match`)
> **Changed by spec 009** ([`specs/009-wallet-topup/spec.md`](../../specs/009-wallet-topup/spec.md)). **Permission:** `topup.match` (*Match an incoming transfer*, CEO/Finance) unless noted. Every POST is **idempotent** (Idempotency-Key) and **audited**.

- `GET /dashboard/topups` (filters: status — default pending and on hold — Cairo dates, and search by reference with or without `DAHAB-`, phone or name) · `GET /dashboard/topups/export` (CSV, audited) · `GET /dashboard/topups/{topup}` · `GET /dashboard/topups/{topup}/receipt`.
- Staff top-ups carry the same `expected_amount` plus `notice_fee_percent` (the snapshot); `notice_account.provider_fee_percent` is the account's current fee.
- `POST /dashboard/topups/{topup}/match` — `{ amount, receiving_account_id, note?, arrival_reference? }`. Credits **what actually arrived** in one transaction (row lock → `topup` ledger entry → `credited` with the unique ledger id → audit), then SMS/email after commit. A note is required when the amount differs from the claim; a notice is credited at most once.
- `POST /dashboard/topups/{topup}/hold` (note) · `/unhold` · `/reject` (`money_not_received | duplicate_notice | sender_not_accepted | other` + note; the customer is told the reason, never the note).
- `POST /dashboard/topups` — **credit by hand** (money with no notice): `{ customer_id, amount, receiving_account_id, note, arrival_reference? }`.
- **Suspended-customer credit rule:**
  - verified and active → match and credit by hand allowed;
  - verified and suspended (suspended from active) → match and credit by hand allowed **only** as staff-side reconciliation of money that has already arrived: `arrival_reference` (the provider's transaction reference for the arrival) is required (otherwise 422), and the audit row records the customer's status; the same idempotency, ledger and audit rules apply;
  - awaiting verification, rejected, or suspended from those → credit by hand refused with `verification_required`;
  - the customer side is unchanged: a suspended customer cannot read receiving details, upload a receipt or submit a notice, but may list and cancel their own pending notices.
- `GET /dashboard/receiving-accounts` (`topup.match` or `topup.accounts.manage`) · `POST /dashboard/receiving-accounts` and `PATCH /dashboard/receiving-accounts/{account}` (`topup.accounts.manage`, audited; accounts are deactivated, never deleted; the method never changes).
- **Errors:** `illegal_topup_transition` (409), `verification_required` (403), validation (422).

### `POST /admin/compensation`
Pay goodwill/dispute compensation into a wallet (`event_kind = compensation`).
- **permission:** *Pay compensation* — CEO unlimited; **Finance up to the caps** `compensation.cap_per_payment_egp` / `compensation.cap_per_day_egp` (Part 1 §4.2; settings). Over-cap from Finance → `compensation_cap_exceeded` (403).
- **audited:** yes · **reason:** required · **idempotent:** required
- **Ledger:** `external_equity −amount`, customer `cust_available +amount`.

### `POST /admin/orders/{id}/refund`
Refund a buyer in full (dispute/quality). · **permission:** *Refund a buyer* (CEO/Finance) · **audited:** yes · **reason:** required · **idempotent:** required

### `POST /admin/wallet-adjustment`
Direct wallet adjustment — **CEO only** (matrix). The narrowest, most sensitive money action.
- **permission:** *Adjust a wallet balance directly* (CEO only) · **audited:** yes · **reason:** required · **idempotent:** required
- **Ledger:** a reversible `compensation`/`reversal`-kind transaction; never an in-place balance edit (there are no stored balances to edit).

### `POST /admin/bank-movements`
Record a bank movement outside the app (capital, rent, fees, profit draw) with proof (schema §17).
- **permission:** *Record a bank movement* (CEO/Finance) · **audited:** yes · **reason:** required · **idempotent:** required
- **Body:** `{ "kind": "capital_in"|"rent"|"bank_charge"|"profit_draw", "amount", "occurred_on", "reason", "proof_ref" }`
- **Ledger:** `external_equity` ↔ `bank` (`event_kind = external_bank_movement`).

### `POST /admin/daily-close`
Close the day: snapshot bank balance, customer liability, Dahab wallet, and the difference; lock when clean (schema §18; `daily_close_no_reopen` blocks reopening a locked day).
- **permission:** *Close the day* (CEO/Finance) · **audited:** yes · **idempotent:** required
- **Note:** reads `solvency_check` (Part 1 §5.2 / ledger view) — the headline "bank minus what is owed to customers" figure.

---

## 10. Admin — rates, settings & operating controls

### `PATCH /admin/settings/{key}`
Change any tunable (deposit %, deadlines, caps, pause window…). Writes `setting` + `setting_history` (append-only history) — never hardcoded (locked rule).
- **permission:** varies by key. Commission/spread keys (`commission.*`, `spread*`) → **founders + Finance** (resolved decision this session; blueprint governs over the roles matrix). Other operational keys → per matrix. · **audited:** yes · **reason:** required · **idempotent:** required
- **Special case:** a manual gold price whose deviation exceeds `manualprice.confirm_deviation_pct` requires a second confirm (setting; matrix "set the gold price correction").
- **As built** (spec 005): `GET /api/v1/dashboard/settings` · `PATCH /api/v1/dashboard/settings/{key}` `{value, reason}` · `GET /api/v1/dashboard/settings/history`. Keys are a code-defined catalogue; rates keys (commission, VAT, manual-price rules, feed staleness, compensation and first-sale caps) need `pricing.rates.manage`, operations keys (deposit, deadlines, windows, tolerances, thresholds) need `settings.manage`. The two `price_correction.*` settings became per-karat adjustments — see "Pricing" below.

### `POST /admin/gold-price/manual`
Enter a manual gold price / correction (when Evolve is unavailable — Part 4).
- **permission:** *Enter a gold price manually* / *Set the correction* (CEO/Finance) · **audited:** yes · **reason:** required · **idempotent:** required
- Deviation above the setting → `manual_price_confirm_required` (409) until a `confirm=true` second call.
- **Changed by spec 005** (product-owner decisions 2026-09-27): the price is the 24K **bid and ask**; the confirmation is a separate request by a holder of `gold_price.confirm` (and, while `manualprice.confirmer_must_differ` is on, another person); a request that needs it is **recorded** and answered `202` with `code: manual_price_confirm_required` (not 409). See "Pricing" below.

### Pricing — gold prices, adjustments (built by spec 005)
> See [`specs/005-pricing/`](../../specs/005-pricing/) and [`docs/features/pricing.md`](../features/pricing.md). Implemented under `/api/v1/dashboard/*`:

| Endpoint | Permission (seed roles, + ceo) | Notes |
|---|---|---|
| `GET /dashboard/gold-prices/current` | `pricing.view` (coo, finance, operations) | Current price, feed state, pending manual price, every karat's market bid/ask, sellers get, buyers pay |
| `GET /dashboard/gold-prices` | `pricing.view` | History, newest first |
| `POST /dashboard/gold-prices/preview` | `pricing.view` | Bid/ask, change %, or current, with optional trial adjustments; writes nothing |
| `POST /dashboard/gold-prices/manual` `{bid_24k, ask_24k}` or `{change_pct}`, `reason` | `gold_price.enter` (finance) | Only while the feed is down (`409 price_feed_healthy`); `201` effective or `202` pending |
| `POST /dashboard/gold-prices/manual/{id}/confirm` | `gold_price.confirm` (finance) | `403 confirmer_must_differ`, `409 manual_price_not_pending` |
| `PUT /dashboard/karats/{code}/adjustments` `{buy, sell: {kind, value}, reason}` | `pricing.rates.manage` (finance) | `422 price_inverted` |
| `GET /dashboard/price-adjustments/history` | `pricing.view` | |

The feed itself is a scheduled command (`pricing:pull-feed`, every minute), not an endpoint — Part 4 §1.

### `POST /admin/karats/{code}/toggle`
Turn a karat on/off (a row toggle on `karat.is_enabled`, not a release — locked "data not code").
- **permission:** *Turn a karat on or off* (CEO/Finance) · **audited:** yes · **idempotent:** required
- **As built** (spec 004): `POST /api/v1/dashboard/karats/{code}/toggle` `{enabled}` (`karats.toggle`) — see "Reference data" below.

### Reference data — karats, branches, hours, closures (built by spec 004)
> See [`specs/004-reference-data/`](../../specs/004-reference-data/) and [`docs/features/reference-data.md`](../features/reference-data.md). Implemented under `/api/v1/dashboard/*`; permissions are catalogue codes seeded onto the dynamic roles (ceo gets all):

| Endpoint | Permission (seed roles) | Notes |
|---|---|---|
| `GET /dashboard/karats` | `reference.view` (coo, finance, operations) | Display order |
| `POST /dashboard/karats` `{code 1..24, purity (0,1] ≤5 dp, sort_order?}` | `karats.create` (coo) | Starts off; code and purity immutable (no update route) |
| `POST /dashboard/karats/{code}/toggle` `{enabled}` | `karats.toggle` (finance) | Audited only when it changes |
| `GET /dashboard/branches` | `reference.view` | With the weekly hours (`dow` 0 = Sunday) |
| `POST /dashboard/branches` · `PATCH /dashboard/branches/{branch}` | `branches.manage` (coo, operations) | `hours` replaces the whole week; split days allowed, overlaps → 422 `hours.N`; branches are disabled, never deleted |
| `GET /dashboard/branch-closures` | `reference.view` | `branch_id` null = every branch |
| `POST /dashboard/branch-closures` | `branches.manage` | Today or later; duplicate → `409 closure_exists` |
| `DELETE /dashboard/branch-closures/{closure}` | `branches.manage` | Future only; today or earlier → `409 closure_in_past` |
| `PUT /dashboard/staff/{staff}/branch` `{branch_id\|null, reason}` | `roles.manage` | Enabled branch only; not on yourself (`403 escalation_denied`); `422 reason_required` |

Every change is audited (`reference.*`, `authz.staff.branch_changed`). Piece types are seeded only; they have no endpoint yet. The working-hours deadline resolver (Part 3 §1) is `App\Support\WorkingHours\WorkingHoursResolver`.

### `POST /admin/category-controls`
Set a category stop/pause (`stop_new_listings` | `pause_category` | `stop_everything`); anything with a locked price is left alone (schema §14).
- **permission:** graduated — *Stop new listings* (CEO/COO/Operations); *Pause a whole category* (CEO only); *Stop everything* (CEO only) (Part 1 §4.2). · **audited:** yes · **reason:** required · **idempotent:** required

### Market makers
### `POST /admin/market-maker/approve-listing`
Approve a specific aged piece for market-maker purchase (`market_maker_approval`), recording the numbers the approver saw; the piece must be older than `marketmaker.min_list_age_days` (checked in service).
- **permission:** *Approve a piece for market makers* (CEO/Finance — money-adjacent, waives commission; Part 1 §4.1 note) · **audited:** yes · **idempotent:** required
- **Note:** the MM programme's controls assume people known personally; whether it scales is an open question (open-questions §5) — not a build blocker, but the endpoint should not assume trust beyond the tied customer.

### `POST /admin/promo-codes` · `PATCH /admin/promo-codes/{code}`
Manage first-sale / market-maker codes (`promo_code`). · **permission:** *Manage promo codes* (CEO/Finance) · **audited:** yes · **idempotent:** required

### Accounts & access
### `POST /admin/customers/{id}/suspend` · `/reinstate`
> **Changed by spec 010:** suspending also moves the customer's `live` listings to `suspended_hold` (off the market) in the same transaction; reinstating returns them to `live` ([`specs/010-listings/spec.md`](../../specs/010-listings/spec.md)).
- **permission:** *Suspend / reinstate a user account* — **both founders** (open-questions §3 governs: COO has full permissions except wallets; suspension is not a wallet action). · **audited:** yes · **reason:** required · **idempotent:** required
- Sets `is_suspended` + `suspended_by` + `suspended_reason` (the `suspended_needs_actor` CHECK enforces the reason + actor).
- **As built (feature `007-customer-file`, [`specs/007-customer-file/`](../../specs/007-customer-file/), [`docs/features/customer-file.md`](../features/customer-file.md)).** Every route below is under `/api/v1/dashboard/customers`.
  - `POST /{id}/suspend`, body `{ reason, note }`:
    - `reason` is one of the seven codes (Part 1 §4.3 amendment); `note` is 1–1000 characters.
    - Needs `customer.suspend` and an `Idempotency-Key`. Any state may be suspended.
    - Refused with `409 customer_already_suspended` when already suspended.
    - Writes one `auth.customer.suspended` audit entry: before/after `{status, suspended_reason}`, with the note as `reason`.
  - `POST /{id}/reinstate`, body `{ note }`:
    - Returns the customer to `status_before_suspension` and clears the suspension details.
    - Refused with `409 customer_not_suspended` when not suspended.
    - Writes one `auth.customer.unsuspended` audit entry.
  - Both answer `200` with the Customer file (the `StaffCustomerFile` schema, as returned by `GET /{id}`).
- **The rest of the Customer file (spec 007):**
  - `GET /{id}` — extended with `documents[]`, `suspension`, `preferred_lang` and `joined_at`. Still one `auth.customer.verification_details_viewed` audit entry per call.
  - `GET /?q=` — an exact match on the display reference or the E.164 phone, in any state.
  - `GET /{id}/activity` — History:
    - audit entries by or about the customer (including their identity documents), without `auth.token.rotated`, keyset-paginated;
    - needs `customer.view` plus `audit.view_all` or `audit.view_own` (own actions only).
  - `GET /{id}/sessions` — every trusted device (a 12-character `device_ref`) and the **open** sessions, one per token family. Sign-out deletes a session's tokens, so ended ones appear in History instead. Needs `customer.view`.

### `POST /admin/identity-documents/{id}/review`
Approve/reject an ID (`verification` or founders). **Viewing the document image writes a `document_view_log` row in the same transaction as the read** (Part 1 §5.4) — a view that cannot be logged must fail.
- **permission:** *Approve an ID or passport* (CEO/Verification) · **audited:** yes · **reason:** required on reject · **idempotent:** required
- **As built** (dashboard surface, Spatie permissions `identity.view` / `identity.review`, both held by `ceo` and `verification`):
  - `GET /api/v1/dashboard/identity-documents[?status=pending|approved|rejected&per_page=]` — review queue, oldest first, paginated; metadata only. `identity.view`.
  - `GET /api/v1/dashboard/identity-documents/{id}` — review metadata, never the image or `storage_ref`; logs nothing. `identity.view`.
  - `GET /api/v1/dashboard/identity-documents/{id}/image` — the decrypted image (`Cache-Control: no-store`). Writes a `document_view_log` row **and** an `identity.document.viewed` audit row in the same transaction as the read, before returning any byte; if either cannot be written, or the object cannot be read, the transaction rolls back and nothing is returned. `410 document_image_deleted` after `image_deleted_at`. `identity.view`.
  - `POST /api/v1/dashboard/identity-documents/{id}/review`, body `{ decision: "approved"|"rejected", reason }` — `reason` required for `rejected` (422 otherwise; kept in `audit_log.reason`, the schema has no column on the document). `pending → approved|rejected` exactly once (`409 illegal_document_transition` after that). Approval sets `customer.is_verified`; rejection leaves the customer unchanged. Document, customer and audit row commit together. `identity.review`.
  - The document id is matched as a UUID in the route and loaded inside the Action (no route-model binding), so a caller without the permission gets `403` for existing and missing ids alike.

### Audit log viewer (built by spec 006)
> See [`specs/006-audit-log/`](../../specs/006-audit-log/) and [`docs/features/audit-log.md`](../features/audit-log.md). Part 1 §4.3 "View the audit log": CEO everything; COO, Finance, Operations, Verification own actions; IGI none — as catalogue codes `audit.view_all` (ceo) and `audit.view_own` (coo, finance, operations, verification).

| Endpoint | Notes |
|---|---|
| `GET /dashboard/audit-log` | Newest first, keyset pages (`cursor`), filters `from`/`to` (Cairo dates, default last 7 days), `category` (absent = everything but sign-ins and sessions), `actor` (staff id or `system`), `action`, `entity_type`, `entity_id`; `meta.total` |
| `GET /dashboard/audit-log/{entry}` | Full before/after, reason, IP, device fingerprint; `404` when not visible to the caller |
| `GET /dashboard/audit-log/export` | CSV (UTF-8, BOM) of every match, capped at 50,000 (`X-Export-Truncated`); each export is recorded as `audit.log.exported` |
| `GET /dashboard/audit-log/categories` | The 8 categories and whether "Everything" includes each |

Without `audit.view_all`, every read is limited to the caller's own actions. Customers appear by `display_ref`; personal fields never appear in the summaries.

### `POST /admin/payout-accounts/{id}/verify`
Verify a payout account's name against the ID (`active`).

> **Changed by spec 013** — see [`specs/013-withdrawals/spec.md`](../../specs/013-withdrawals/spec.md). Built as `GET /dashboard/payout-accounts` (default `pending_review`, oldest first, with the customer's verified ID name) and `POST /dashboard/payout-accounts/{id}/verify|refuse` (`payout_account.verify`; idempotent, audited). Verify makes the account the one in use when the customer has none (a change, unless it is their first). Refuse moves it to the new final state `refused` with a reason (`name_mismatch`, `name_shortened`, `not_in_customer_name`, `details_invalid`, `other`) and a staff note.
- **permission:** *Verify a payout bank account* (CEO/Finance/Verification) · **audited:** yes · **idempotent:** required

### Access control — roles, permissions, staff roles (built by spec 002)
> **Changed by spec 002** (product-owner decision 2026-09-26) — see [`specs/002-dynamic-staff-authorization/`](../../specs/002-dynamic-staff-authorization/). Roles and their permissions are Dashboard-managed data. Implemented under `/api/v1/dashboard/*` (contract: `specs/002-dynamic-staff-authorization/contracts/openapi.yaml`):

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /dashboard/permissions` | `roles.manage` | The code-defined catalogue (read-only) |
| `GET /dashboard/roles` · `GET /dashboard/roles/{role}` | `roles.manage` | With permissions and holder counts |
| `POST /dashboard/roles` | `roles.manage` | Only permissions the actor holds |
| `PATCH /dashboard/roles/{role}` | `roles.manage` | Not a role the actor holds; `reason` required for permission/MFA changes |
| `DELETE /dashboard/roles/{role}` | `roles.manage` | `409 role_in_use` while held; `reason` required |
| `GET /dashboard/staff` · `GET /dashboard/staff/{staff}` | `staff.view` | System actor never listed |
| `PUT /dashboard/staff/{staff}/roles` | `roles.manage` | Not on yourself; `reason` required; founder status untouched |

Refusals: `403 escalation_denied`, `409 last_role_manager`, `409 role_in_use`, `422 reason_required`. Every change is audited.

### `POST /admin/staff` · `PATCH /admin/staff/{id}` · founder security
Create / disable / enable staff accounts is a later feature (spec 002 FR-080); permissions are changed through the access-control endpoints above. A branch is optional for any staff member (the former `igi_has_branch` CHECK is removed). Founder status is never changeable through the API. Founder device-approval and freeze/unfreeze endpoints implement Part 1 §8:
- `POST /admin/founder/device-approvals/{id}/approve` — the *other* founder approves a held new-device sign-in (`approver_is_not_self`).
- `POST /admin/founder/freeze` / `/unfreeze` — either founder freezes the other instantly (`no_self_freeze`); unfreeze needs **both** confirmations (`unfreeze_confirm_1/2`).

### Disputes & legal
### `POST /admin/disputes/{id}/resolve` · `/pass-on`
Resolve (needs a reply, `resolved_needs_reply` CHECK) or pass to a named colleague. · **permission:** handle disputes (Operations/founders) · **audited:** yes · **reason:** required
### `POST /admin/case-files`
Assemble a case file for law enforcement (`case_file`), audited. · **permission:** *Build a full case file* (CEO/COO/Finance) · **audited:** yes · **reason:** required
### `POST /admin/legal-documents`
Publish a new legal version (`legal_document`); a material change forces re-acceptance. Every prior version a customer accepted is kept forever (locked rule). · **permission:** *Edit legal text and publish* (both founders) · **audited:** yes

---

## 11. Background jobs (not endpoints, but part of the API surface's contract)

> **Changed by spec 012** — see [`specs/012-orders/spec.md`](../../specs/012-orders/spec.md). Built: `orders:sweep`, every minute, system actor, one item per transaction — the cancellation threshold (suspends the seller, `repeated_cancellations`), missed reach-branch deadlines (`cancelled_seller`, refund), the threshold again, the reach-branch reminder (3 working hours before) and the balance reminder (24 h before), the unpaid balance (`cancelled_buyer_nopay`, forfeit, piece returned) and the seller-return window (`seller_unclaimed`, calendar weeks). Also the unanswered adjustment (declined, system actor) and the collection window (listing `sold → uncollected_expired`, the buyer told; the order stays ready to collect).

These are scheduled workers that drive deadline-based transitions. They are listed here because they produce the same audited, ledgered effects as endpoints and must obey the same one-transaction rule. Each runs as the system actor (the single `staff` row with `is_system = true`, `App\Support\SystemActor`, spec 002) recorded in the audit log.

- **Seller-reply deadline sweep.** Requests past `seller_reply_deadline` while still `queued` → `released_expired`, deposit refunded. (Per request; FIFO integrity preserved.) *Built by spec 011: `buy-requests:expire`, every minute, one transaction per request, system actor; the buyer is told.*
- **Reach-branch deadline sweep.** Orders past `reach_branch_deadline` in `awaiting_delivery` → `cancelled_seller` path (or a distinct no-deliver cancellation), buyer refunded; counts toward seller suspension.
- **Balance-payment deadline sweep.** Orders past `balance_due_deadline` in `awaiting_balance` → order `cancelled_buyer_nopay` (terminal accounting record) **and** the **piece returns to the seller**: listing `settling/at_inspection/accepted → awaiting_seller_return`, and a `seller_return` row is opened with `return_deadline = now() + deadline.seller_return_weeks` (working hours). **Deposit forfeiture** (`event_kind = deposit_forfeit`): the buyer's held deposit is split — `deposit.seller_forfeit_share_pct` (50%) to the seller's `cust_available` (recorded as `seller_return.compensation_txn_id`), remainder to `dahab_*` — drafted as **agreed compensation, not a penalty** (open-questions §1, for the legal clinic; the split ratio is a setting). Full workflow (incl. the seller-return collection and the `awaiting_seller_return → seller_unclaimed` escalation) is in Part 3. Because the seller was **not** yet paid (the buyer never paid, so settlement never fired), there is no seller settlement to reverse here.
- **Seller-return deadline sweep.** Returned pieces past `seller_return.return_deadline` while still uncollected (`collected_at IS NULL`) → listing `awaiting_seller_return → seller_unclaimed`; a status is shown to the seller ("window passed, not our liability"). Disposition (hand over / compensate) is a **manual decision** when the seller makes contact (decision #3; Part 3). The order stays `cancelled_buyer_nopay` throughout — the afterlife rides on the listing, never the order.
- **Collection-deadline sweep.** Orders past `collect_deadline` in `ready_to_collect` → listing `sold → uncollected_expired`; the order stays terminal (`completed` once handed over, but here it was never handed over — it remains `ready_to_collect` as the *order* accounting state while the *listing* carries `uncollected_expired`). Fire a buyer notification. **Disposition is a manual decision when the buyer makes contact** (decision #4 — hand over the piece, or refund from `escrow`): the money source for a refund is the escrowed price, which is why §7 keeps escrow on the path. The system provides the state, the notification, and the manual hand-over/refund actions; it does **not** auto-dispose. *(This was OI-2.1; decision #4 resolves the disposition to a manual path. The commercial choice per case — hand over vs refund — is made by an operator, not automated.)*
- **Withdrawal-pause expiry.** `on_hold_account_change → under_review` once `pause_until` elapses.
  > **Changed by spec 013** ([`specs/013-withdrawals/spec.md`](../../specs/013-withdrawals/spec.md)): withdrawals not yet released are **cancelled** at the change, so nothing waits in `on_hold_account_change`. Built as `withdrawals:sweep`, every minute, system actor: for each pause that has ended it stamps `ended_notified_at` and tells the customer once that withdrawals are open again (a pause superseded by a later open one is stamped silently).
- **Notify-when-free.** When a listing returns to `live` with zero active requests, notify buyers who left with `notify_when_free = true` (they rejoin at the back — locked decision). *Built by spec 011: an after-commit job; `buy_request.free_notified_at` makes it once per leave; a buyer already back in line is skipped.*
- **Pattern/cap flags.** When transaction counts cross `flag.pattern_txn_threshold`, raise a review flag. ⚠️ **Who receives the alert and by which channel is unresolved** (open-questions §5; Part 1 OI-1.3). **OI-2.2.**

---

## 12. Consolidated domain error codes

Auth codes are in Part 1 §9. Domain codes introduced above:

| Code | HTTP | Where |
|---|---|---|
| `phone_taken` | 409 | register |
| `weak_password` / `invalid_phone` | 422 | register |
| `document_already_pending` | 409 | identity upload |
| `price_moved` | 409 | join queue (rate moved past tolerance) |
| `already_in_queue` | 409 | join queue (partial unique index) |
| `insufficient_funds` | 409 | any debit vs available balance |
| `listing_not_purchasable` | 409 | join queue (wrong listing state) |
| `category_stopped` / `category_paused` | 409 | listing create / buy |
| `deposit_agreement_required` / `ownership_declaration_required` | 422 | buy / list |
| `not_in_queue` / `not_queue_head` / `queue_empty` | 409 | withdraw / accept / decline |
| `cannot_buy_own_listing` / `price_unavailable` | 409 | join queue (spec 011) |
| `buyer_suspended` / `branch_hours_unavailable` | 409 | accept (spec 011) |
| `order_not_cancellable` | 409 | staff cancel acceptance (spec 011) |
| `illegal_order_transition` | 409 | any guarded order change — SQLSTATE DH006 since spec 012 (was DH005) |
| `inspection_correction_not_allowed` / `price_not_set` | 409 | inspection correction / buyer decision before a regrade price (spec 012) |
| `deadline_not_running` / `order_not_open` | 409 | extend a deadline / change the branch (spec 012) |
| `deadline_must_move_forward` | 422 | extend a deadline / change the branch (spec 012) |
| `settlement_not_possible` | 409 | pay-balance when the proceeds would be ≤ 0 (spec 012) |
| `invalid_collection_code` | **422** | handover — spec 012 deviation from the 401 below: a 401 signs Dashboard staff out |
| `handover_locked` | 429 | five wrong codes, 15 minutes (spec 012) |
| `illegal_buy_request_transition` | 409 | any guarded request / order change (SQLSTATE DH005, spec 011) |
| `branch_not_in_options` | 409 | accept / change-branch |
| `illegal_listing_transition` / `illegal_order_transition` / `illegal_withdrawal_transition` | 409 | any guarded state change |
| `listing_not_editable` | 409 | edit a listing outside draft / changes requested (spec 010) |
| `seller_suspended` / `karat_disabled` | 409 | approve a listing (spec 010) |
| `photo_required` / `gold_needs_karat_weight` / `branch_options_required` | 422 | create / submit a listing (spec 010) |
| `wrong_branch` | 403 | IGI actions outside own branch |
| `karat_rule_violation` | 422 | inconsistent inspection submit |
| `not_the_buyer` / `not_the_seller` | 403 | caller-identity checks |
| `balance_deadline_passed` | 409 | pay-balance |
| `invalid_collection_code` | 401 | handover |
| `proxy_details_missing` | 422 | proxy handover |
| `email_confirmation_required` | 403 | withdrawal (Part 1 §2.4) |
| `confirmation_invalid` | 422 | the email link's page: unknown, expired, replaced or used token (spec 013) |
| `declaration_required` | 422 | add a payout account without the current declaration (spec 013) |
| `withdrawal_on_hold` | 409 | release while held (spec 013) |
| `illegal_payout_account_transition` | 409 | any guarded payout-account change — SQLSTATE DH008 (spec 013) |
| `withdrawals_paused` | 409 | withdrawal within account-change window |
| `payout_account_not_active` | 409 | withdrawal |
| `compensation_cap_exceeded` | 403 | Finance over-cap compensation |
| `manual_price_confirm_required` | 409 | manual gold price beyond deviation |
| `wallet_access_denied` | 403 | non-CEO/Finance wallet path (Part 1 §5.2) |

---

## Open items raised in Part 2 (decide before build)

- **OI-2.1 — Uncollected paid piece. RESOLVED (decision #4).** The collection-deadline sweep moves the listing to `uncollected_expired` and fires a buyer notification. Disposition is a **manual, per-case operator decision** taken when the buyer makes contact: either hand the piece over, or refund the buyer from `escrow`. Build the state, the notification, and the two manual actions (hand-over / refund); do **not** auto-dispose. The refund source is the escrowed price (why §7 keeps escrow on the path). Legal framing of "window passed, not our liability" still needs the clinic (open-questions §1, custody/liability), but the system behaviour is now defined.
- **OI-2.2 — Cap/pattern alert routing.** Same as Part 1 OI-1.3: who is alerted, and by which channel. The flag job exists; the delivery target does not.
- **OI-2.3 — Withdrawal release authority. RESOLVED.** Both **CEO and Finance** may release a withdrawal (not CEO-only); the COO is excluded as a wallet action. The admin-roles matrix and Part 1 §4.2 are updated to match.
- **Legal-clinic dependencies surfaced by endpoints** (all from open-questions §1, flagged not built): the proxy-collection declaration wording and data-protection consent (handover); deposit-forfeiture drafted as agreed compensation not a penalty (no-pay sweep); the piece-left-at-branch liability framing (uncollected/insurance). These are contract/wording decisions a lawyer must settle; the endpoints leave the hooks.

---

*End of Part 2. Part 3 (backend logic & workflows) will specify the working-hours deadline resolver, the settlement math in full, the suspension rules, the queue concurrency model in depth, and the state-machine guards — the logic the endpoints above call into. Before I start Part 3: please confirm OI-2.3 (withdrawal release authority), and note that OI-2.1 (uncollected paid piece) still blocks one job's disposition logic.*
