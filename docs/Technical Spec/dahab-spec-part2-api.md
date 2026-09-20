# Dahab — Technical Specification

## Part 2 of 4: API Endpoints

*Senior developer handoff · REST/JSON · Depends on Part 1 (Authentication & Authorization). Every endpoint here inherits the request-context contract, the role/permission matrix, the idempotency requirement and the error contract defined in Part 1 §7, §4, §9 — they are referenced, not restated.*

> **Revision (money-timing + spread).** §7 has been rewritten: settlement (seller proceeds + commission + spread + VAT) now fires at **`pay-balance`**, not at `handover`; the full price transits `escrow` as an **instantaneous pass-through** in that one transaction; `handover` is a physical, zero-money event; tax invoices issue at `pay-balance`. §2 now defines the **sell-side / buy-side / spread** pricing (gold only), computed at `pay-balance` from the IGI-confirmed weight and not stored. §11 sweeps and the open items are updated to match (decisions #3 and #4). All figures within tolerance are recomputed on the **IGI-confirmed weight** (`measured_weight_g`).

---

## 0. Conventions that apply to every endpoint

**Transport.** JSON over HTTPS. `Content-Type: application/json` on every request with a body. All timestamps are RFC 3339 / ISO 8601 with an explicit offset (`2026-09-05T14:03:00+03:00`); the server stores `TIMESTAMPTZ` and returns Africa/Cairo offsets. All money fields are decimal **strings** in the JSON, never floats — `"12500.0000"` — to preserve the `NUMERIC(18,4)` precision the ledger depends on. Weights are decimal strings to 3 places (grams). Karat is an integer code.

**Authentication.** Every protected route carries the session (Part 1 §2.3, §3.3). The server re-reads the live actor row inside the request transaction (Part 1 §7 step 2); token claims are routing hints only. Public browse routes (§2 below) are the only unauthenticated ones.

**Authorization.** Staff endpoints declare `permission:` referencing the matrix (Part 1 §4). Customer trade endpoints declare `gate:` (`trade_allowed`, `verified`, or `none`). The service resolves both before executing (Part 1 §4.4, §7 step 4). Wallet-touching staff actions are enforced twice — matrix check and Postgres grant — per Part 1 §5.2.

**Idempotency.** Every state-creating or money-moving `POST`/`PATCH` **requires** an `Idempotency-Key` header (a client-generated UUID). The server persists the key with the request fingerprint and the response; a replay of the same key returns the original response and never re-executes. This is mandatory, not optional — Part 1 §7 step 5 explains why (a retried "send buy request" must not hold two deposits). Endpoints below are marked `idempotent: required` or `idempotent: n/a` (safe reads).

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
- **Notes:** password hashed Argon2id (Part 1 §2.1); never echoed. A `cust_available` and a `cust_held` account row are created for the customer here (the ledger needs both to exist before any hold).

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

---

## 2. Browsing (public, no account)

Browsing needs no account (Part 1 §2.2). Served by the dedicated public view that exposes only non-owner-sensitive columns of `live`/`reserved` listings and never `seller_id` or contact details (Part 1 §5.3, schema note on the public read).

### `GET /market/listings`
- **gate:** none (unauthenticated allowed) · **idempotent:** n/a
- **Query:** `category`, `karat`, `piece_type`, `branch` (any of the listing's named options), `min_g`, `max_g`, `is_market_maker` (filter flag — the marker exists in the prototype; server logic is in scope, open-questions §4), `sort` (`newest`|`price_asc`|`price_desc`), `limit`, `cursor`.
- **200:** `{ "items": [ { listing_id, category, piece_type, karat, weight_g, making_charge_per_g, current_price, photos: [urls], branch_options: [names], queue_count, is_market_maker } ], "next_cursor" }`
- **Price semantics:** the `current_price` shown to a buyer is the **sell-side** price. For gold it is computed live from the current gold rate corrected by `price_correction.sell_side` (EGP/gram), × weight × purity, + making charge (the rate source is Evolve, Part 4; a manual rate applies if set — Part 1 matrix "enter a gold price manually"). The public list shows the *live* sell-side price; a price only *locks* when a buy request is placed (§4). This must be labelled in the UI as indicative until a request locks it.
- **Spread (gold only).** For the `gold` category, Dahab earns the **spread** = the buy/sell rate difference on the gold value: the buyer pays at the rate corrected by `price_correction.sell_side` and the seller receives at the rate corrected by `price_correction.buy_side`; the difference `(sell_side − buy_side) × weight × purity` is Dahab's spread. The published buy/sell difference is *derived from these two correction settings, never entered by hand* (blueprint §3) — change a correction and the published figure moves with it. **Diamond and gold-with-diamond carry no spread:** the seller sets one fixed asking price, the buyer pays it, and Dahab earns commission on the stone value only (blueprint §5). The spread is **computed at `pay-balance` from the IGI-confirmed weight and is not stored** (locked decision); `locked_total_price` locks the per-gram rate and formula, not a frozen total (§7, Part 3).
- **Never exposes:** `seller_id`, seller contact, the private invoice (`listing_media.is_private = true`).

### `GET /market/listings/{id}`
Single public listing detail (same column restrictions). Includes public photos and video, excludes the private invoice and stone certificate pre-sale.
- **404** if the listing is not in a publicly visible state.

---

## 3. Selling — listing a piece

Implements the listing lifecycle (`listing_state`) and the locked decision that **branch options are named at listing** (schema §7; open-questions §3).

### `POST /listings`  (create draft)
- **gate:** `trade_allowed` (verified + not suspended — the first-sale gate fires here, Part 1 §2.2) · **idempotent:** required · **audited:** no (customer action, not privileged)
- **Body:**
  ```json
  {
    "category": "gold"|"diamond"|"gold_with_diamond",
    "piece_type_id": 12,
    "karat_code": 21,               // required unless pure diamond
    "stated_weight_g": "15.500",    // required unless pure diamond
    "making_charge_per_g": "85.0000", // gold making charge
    "asking_price": "…",            // stones / whole-piece ask
    "description": "…",
    "branch_option_ids": [1, 3],    // >=1; the willing set
    "photo_tokens": ["…"], "video_token": "…"|null,
    "invoice_token": "…"|null, "stone_certificate_token": "…"|null,
    "ownership_declaration_accepted": true,
    "ownership_legal_doc_id": 7
  }
  ```
- **201:** `{ "listing_id", "state": "draft" }`
- **Validation & errors:**
  - `gold_needs_karat_weight` (422) — mirrors the schema CHECK: non-diamond requires karat + weight.
  - `branch_options_required` (422) — at least one branch option; each must be an enabled branch.
  - `ownership_declaration_required` (422) — the ownership declaration must be accepted; the acceptance is written to both `agreement_acceptance` (context `list_piece`) and `listing_ownership_declaration` in the same transaction.
  - `category_stopped` (409) — if a `category_control` at `stop_new_listings` or higher is active for this category (schema §14).
- **Note:** creating a listing also creates its `listing_queue_seq` row (needed before any buy request can take a position).

### `POST /listings/{id}/submit`
Moves `draft → in_review` (listing_transition).
- **gate:** `trade_allowed` · **idempotent:** required
- **Errors:** `illegal_listing_transition` (409) if not in `draft`/`changes_requested`; `photo_required` (422) if no photo media attached.

### `PATCH /listings/{id}`
Edit a `draft` or `changes_requested` listing (e.g. after a reviewer asks for a better photo).
- **gate:** `trade_allowed` · **idempotent:** required
- **Errors:** `listing_not_editable` (409) if state is not `draft`/`changes_requested`.

### `POST /listings/{id}/withdraw`
Seller takes a listing down. Allowed from `live` or `reserved` (listing_transition `live/reserved → withdrawn`) — note a `reserved` withdrawal is only permitted because no price is *locked at the listing level*; each queued buyer holds their own locked price and is refunded on release (see §4 release semantics).
- **gate:** `trade_allowed` · **idempotent:** required · **audited:** no
- **Effect:** any active `buy_request` rows are released and their deposits refunded (the release path in §4.4), in one transaction.
- **Errors:** `illegal_listing_transition` (409) if not `live`/`reserved`.

### Admin listing review (Operations / founders)

### `GET /admin/listings?state=in_review`
- **permission:** *Approve or reject a new listing* (CEO/COO/Operations — Part 1 §4.1) · **idempotent:** n/a
- Staff connections bypass customer RLS (Part 1 §3.3) and see across sellers.

### `POST /admin/listings/{id}/approve`
`in_review → live`. Sets `listed_at`.
- **permission:** Approve/reject a listing · **audited:** yes · **reason:** none · **idempotent:** required
- **Errors:** `illegal_listing_transition` (409).

### `POST /admin/listings/{id}/request-changes`
`in_review → changes_requested`, with a message to the seller (e.g. "clearer hallmark photo").
- **permission:** *Ask a seller for a better photo* (CEO/COO/Operations) · **audited:** yes · **reason:** required (the message) · **idempotent:** required

### `POST /admin/listings/{id}/takedown`
Take a live listing down (`live/reserved → withdrawn`), releasing/refunding any queue.
- **permission:** *Take a live listing down* (CEO/COO/Operations) · **audited:** yes · **reason:** required · **idempotent:** required

---

## 4. Buying — the queue (buy requests)

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

Wallet **balances** for the customer are their own (RLS-scoped) read. This is distinct from staff wallet access, which is CEO+Finance only and enforced by grant (Part 1 §5.2). A customer always sees their own two figures.

### `GET /me/wallet`
- **gate:** none (signed in; RLS to own rows) · **idempotent:** n/a
- **200:** `{ "available": "…", "held": "…" }` — read from `customer_wallet` (derived from the ledger; never a stored balance).

### `GET /me/wallet/transactions`
Customer's own ledger history (their postings, human-labelled by `event_kind`).
- **gate:** none (signed in) · **idempotent:** n/a · keyset paginated.

### `POST /me/wallet/topup`
Adds funds (`event_kind = topup`): `bank +amount`, buyer `cust_available +amount` (balanced). Payment-gateway integration detail is Part 4; this endpoint records the resulting ledger movement on confirmed settlement.
- **gate:** none (signed in) · **idempotent:** required · **audited:** no

### Payout accounts & withdrawals
Money leaves only to an account in the customer's own name; a payout-account change pauses withdrawals for the setting window (48h) and the email second-check gates every withdrawal (Part 1 §2.4; schema §12).

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

## 9. Admin — money (CEO + Finance only)

Every endpoint here is wallet-touching: the matrix marks them CEO/Finance (Part 1 §4.2) **and** the Postgres grant makes the wallet views/writes unreachable to any other role (Part 1 §5.2). The COO, though a founder, cannot call these — a `wallet_access_denied` (403) from a COO session is a bug or a probe (Part 1 §9).

### `GET /admin/withdrawals?state=requested,under_review`
The review queue. · **permission:** *View a wallet* / *Release a withdrawal* (CEO/Finance) · **idempotent:** n/a

### `POST /admin/withdrawals/{id}/review`
Claim for review (`requested → under_review`). · **permission:** CEO/Finance · **audited:** yes · **idempotent:** required

### `POST /admin/withdrawals/{id}/release`
Approve and send to bank (`under_review → released`). **Every withdrawal is released by a person** (schema §12).
- **permission:** *Release a withdrawal* — **CEO or Finance** (resolved decision: both may release; Finance is no longer review-only for this action). The COO, though a founder, cannot — it is a wallet-touching action (Part 1 §5.2 grant). Every release is still a person's named action, audited.
- **audited:** yes · **reason:** optional · **idempotent:** required
- **Ledger** (`event_kind = withdrawal`): customer hold **−amount**, `bank` **−amount** (money leaves the bank); balanced against the pending-hold account opened at request. Records `release_txn_id`, `released_at`, `reviewed_by`.
- **Errors:** `withdrawals_paused` (409, re-checked at release), `illegal_withdrawal_transition` (409).

### `POST /admin/withdrawals/{id}/reject`
`under_review → rejected`; the held funds return to the customer's available. · **reason:** required · **audited:** yes

### `POST /admin/transfers/match`
Match an incoming bank transfer to a customer top-up (`event_kind = topup`). · **permission:** *Match an incoming transfer* (CEO/Finance) · **audited:** yes · **idempotent:** required

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

### `POST /admin/gold-price/manual`
Enter a manual gold price / correction (when Evolve is unavailable — Part 4).
- **permission:** *Enter a gold price manually* / *Set the correction* (CEO/Finance) · **audited:** yes · **reason:** required · **idempotent:** required
- Deviation above the setting → `manual_price_confirm_required` (409) until a `confirm=true` second call.

### `POST /admin/karats/{code}/toggle`
Turn a karat on/off (a row toggle on `karat.is_enabled`, not a release — locked "data not code").
- **permission:** *Turn a karat on or off* (CEO/Finance) · **audited:** yes · **idempotent:** required

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
- **permission:** *Suspend / reinstate a user account* — **both founders** (open-questions §3 governs: COO has full permissions except wallets; suspension is not a wallet action). · **audited:** yes · **reason:** required · **idempotent:** required
- Sets `is_suspended` + `suspended_by` + `suspended_reason` (the `suspended_needs_actor` CHECK enforces the reason + actor).

### `POST /admin/identity-documents/{id}/review`
Approve/reject an ID (`verification` or founders). **Viewing the document image writes a `document_view_log` row in the same transaction as the read** (Part 1 §5.4) — a view that cannot be logged must fail.
- **permission:** *Approve an ID or passport* (CEO/Verification) · **audited:** yes · **reason:** required on reject · **idempotent:** required
- **As built** (dashboard surface, Spatie permissions `identity.view` / `identity.review`, both held by `ceo` and `verification`):
  - `GET /api/v1/dashboard/identity-documents[?status=pending|approved|rejected&per_page=]` — review queue, oldest first, paginated; metadata only. `identity.view`.
  - `GET /api/v1/dashboard/identity-documents/{id}` — review metadata, never the image or `storage_ref`; logs nothing. `identity.view`.
  - `GET /api/v1/dashboard/identity-documents/{id}/image` — the decrypted image (`Cache-Control: no-store`). Writes a `document_view_log` row **and** an `identity.document.viewed` audit row in the same transaction as the read, before returning any byte; if either cannot be written, or the object cannot be read, the transaction rolls back and nothing is returned. `410 document_image_deleted` after `image_deleted_at`. `identity.view`.
  - `POST /api/v1/dashboard/identity-documents/{id}/review`, body `{ decision: "approved"|"rejected", reason }` — `reason` required for `rejected` (422 otherwise; kept in `audit_log.reason`, the schema has no column on the document). `pending → approved|rejected` exactly once (`409 illegal_document_transition` after that). Approval sets `customer.is_verified`; rejection leaves the customer unchanged. Document, customer and audit row commit together. `identity.review`.
  - The document id is matched as a UUID in the route and loaded inside the Action (no route-model binding), so a caller without the permission gets `403` for existing and missing ids alike.

### `POST /admin/payout-accounts/{id}/verify`
Verify a payout account's name against the ID (`active`).
- **permission:** *Verify a payout bank account* (CEO/Finance/Verification) · **audited:** yes · **idempotent:** required

### `POST /admin/staff` · `PATCH /admin/staff/{id}` · founder security
Create staff, change permissions (both founders); the IGI account is branch-bound (`igi_has_branch` CHECK). Founder device-approval and freeze/unfreeze endpoints implement Part 1 §8:
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

These are scheduled workers that drive deadline-based transitions. They are listed here because they produce the same audited, ledgered effects as endpoints and must obey the same one-transaction rule. Each runs as a system actor recorded in the audit log.

- **Seller-reply deadline sweep.** Requests past `seller_reply_deadline` while still `queued` → `released_expired`, deposit refunded. (Per request; FIFO integrity preserved.)
- **Reach-branch deadline sweep.** Orders past `reach_branch_deadline` in `awaiting_delivery` → `cancelled_seller` path (or a distinct no-deliver cancellation), buyer refunded; counts toward seller suspension.
- **Balance-payment deadline sweep.** Orders past `balance_due_deadline` in `awaiting_balance` → order `cancelled_buyer_nopay` (terminal accounting record) **and** the **piece returns to the seller**: listing `settling/at_inspection/accepted → awaiting_seller_return`, and a `seller_return` row is opened with `return_deadline = now() + deadline.seller_return_weeks` (working hours). **Deposit forfeiture** (`event_kind = deposit_forfeit`): the buyer's held deposit is split — `deposit.seller_forfeit_share_pct` (50%) to the seller's `cust_available` (recorded as `seller_return.compensation_txn_id`), remainder to `dahab_*` — drafted as **agreed compensation, not a penalty** (open-questions §1, for the legal clinic; the split ratio is a setting). Full workflow (incl. the seller-return collection and the `awaiting_seller_return → seller_unclaimed` escalation) is in Part 3. Because the seller was **not** yet paid (the buyer never paid, so settlement never fired), there is no seller settlement to reverse here.
- **Seller-return deadline sweep.** Returned pieces past `seller_return.return_deadline` while still uncollected (`collected_at IS NULL`) → listing `awaiting_seller_return → seller_unclaimed`; a status is shown to the seller ("window passed, not our liability"). Disposition (hand over / compensate) is a **manual decision** when the seller makes contact (decision #3; Part 3). The order stays `cancelled_buyer_nopay` throughout — the afterlife rides on the listing, never the order.
- **Collection-deadline sweep.** Orders past `collect_deadline` in `ready_to_collect` → listing `sold → uncollected_expired`; the order stays terminal (`completed` once handed over, but here it was never handed over — it remains `ready_to_collect` as the *order* accounting state while the *listing* carries `uncollected_expired`). Fire a buyer notification. **Disposition is a manual decision when the buyer makes contact** (decision #4 — hand over the piece, or refund from `escrow`): the money source for a refund is the escrowed price, which is why §7 keeps escrow on the path. The system provides the state, the notification, and the manual hand-over/refund actions; it does **not** auto-dispose. *(This was OI-2.1; decision #4 resolves the disposition to a manual path. The commercial choice per case — hand over vs refund — is made by an operator, not automated.)*
- **Withdrawal-pause expiry.** `on_hold_account_change → under_review` once `pause_until` elapses.
- **Notify-when-free.** When a listing returns to `live` with zero active requests, notify buyers who left with `notify_when_free = true` (they rejoin at the back — locked decision).
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
| `not_in_queue` / `not_queue_head` / `queue_empty` | 409 | withdraw / accept |
| `branch_not_in_options` | 409 | accept / change-branch |
| `illegal_listing_transition` / `illegal_order_transition` / `illegal_withdrawal_transition` | 409 | any guarded state change |
| `wrong_branch` | 403 | IGI actions outside own branch |
| `karat_rule_violation` | 422 | inconsistent inspection submit |
| `not_the_buyer` / `not_the_seller` | 403 | caller-identity checks |
| `balance_deadline_passed` | 409 | pay-balance |
| `invalid_collection_code` | 401 | handover |
| `proxy_details_missing` | 422 | proxy handover |
| `email_confirmation_required` | 403 | withdrawal (Part 1 §2.4) |
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
