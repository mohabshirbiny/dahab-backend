# Research: Listings (spec 010)

Decisions taken while planning. Each: **Decision**, **Rationale**, **Alternatives considered**.
Product-owner answers are in [spec.md](./spec.md) "Clarifications"; the items below are how they are built.

## R1 — Three surfaces; the public one is new

**Decision**:

| Surface | Prefix | Auth | Used for |
|---|---|---|---|
| Public (new) | `/api/v1/market/*`, `/api/v1/reference/*` | none (a customer access token is optional on `/market/*` and only sets `is_mine`) | market list, detail, public media; karats, piece types, branches, the current ownership declaration |
| Customer | `/api/v1/customer/me/listings*` | `auth:customer`, `customer.gate` | the seller's own listings |
| Dashboard | `/api/v1/dashboard/listings*` | `auth:staff`, `staff.permission` | review queue and decisions |

The Technical Spec paths (`/listings`, `/admin/listings`) are rewritten to these, as earlier specs did for their modules.

**Rationale**: Clarification Q1. `docs/platform/api-contract.md` gains the Public surface row; both apps are its consumers.

**Alternatives**: market under `/customer/market/*` — rejected by the product owner. Reference reads inside the market responses only — rejected: the sell form needs the lists before any listing exists.

## R2 — Public read: a read-only `market` database scope, no view

**Decision** (Clarification Q2, a product-owner deviation from Part 1 §5.3):

- A new scope `market` in `DatabaseActor::SCOPES`. It is **not** in `ELEVATED` and `dahab_rls_elevated()` does not list it.
- A second policy on `listing`: `listing_market_read FOR SELECT USING (dahab_rls_scope() = 'market' AND state IN ('live','reserved'))`. There is no `INSERT/UPDATE/DELETE` policy for the scope, so it can write nothing.
- Child tables reach the market only through the listing: `listing_branch_option` and `listing_media` policies test `EXISTS (SELECT 1 FROM listing …)` (the inner select is itself filtered by the listing policies), and `listing_media` adds `NOT is_private` for the scope. `listing_ownership_declaration`, `listing_state_change` and `listing_queue_seq` are owner-or-elevated only; a customer-scope insert into `listing_state_change` must also name the current customer as `actor_customer_id` with no staff actor (analysis U3).
- Route middleware `db.market` (`App\Http\Middleware\UseMarketScope`) pushes the scope for the request, keeping the customer id when a valid customer access token was sent. It is declared only on the `/market/*` group; `MarketScopeTest` pins the exact route list (like `ElevationTest`).
- `MarketListingResource` and `MarketListingDetailResource` are the only shapes the market returns; `seller_id` is read once, to compute `is_mine`, and never serialised. `MarketLeakTest` walks every market response recursively and fails on any key or value that is a seller id, display reference, name, phone or email, or a private media id.

**Rationale**: the owner chose the simplest construction. The scope still gives an engine-level guarantee about *rows* (only live/reserved, only public media, no writes); the *column* guarantee (no `seller_id`) moves from a view to the Resource plus a build-failing test. Part 1 §5.3 and the `05_schema_security.sql` note are rewritten in the same change (Constitution III).

**Alternatives**: a view without `seller_id` — recommended, not chosen. A separate low-privilege database role and connection — not chosen (deployment setup).

## R3 — State machine in the data: `listing_transition` + guard trigger + history check

**Decision**:

- `listing_state` is created with every value in `01_schema_core.sql` **plus `rejected`** (Clarification Q9).
- `listing_transition` is created and seeded with every row in `05_schema_security.sql` **plus `('in_review','rejected','rejected by the reviewer')`**.
- `trg_listing_guard BEFORE UPDATE OR DELETE ON listing`: refuses a delete; refuses a change of `seller_id` or `created_at`; when `state` changes, refuses a move that is not in `listing_transition` (SQLSTATE `DH004` → 409 `illegal_listing_transition`), stamps `state_changed_at = now()`, and sets `listed_at = now()` on the first move to `live`.
- `trg_listing_change_recorded` — a **deferred constraint trigger** `AFTER INSERT OR UPDATE OF state ON listing`: at commit, a `listing_state_change` row must exist for this listing, this `to_state` and this transaction (`txid = txid_current()`); otherwise `DH004`. This is what makes "every move is recorded" true for any code path.
- One writer: `App\Actions\Listings\Concerns\MovesListing::move(Listing, ListingState $to, actor, ?string $note)` locks the row `FOR UPDATE`, checks the move against `listing_transition` (cached per request), updates the state and inserts the history row. Every Action that changes state uses it. Two competing moves serialise on the row lock; the loser finds the state changed and gets 409.

**Rationale**: FR-015, FR-017, SC-006; Constitution I ("by database constraint, not by application convention"). The schema already ships the same guard for orders (`assert_order_transition`).

**Alternatives**: PHP-only checks — rejected (no backstop). Writing the history row from the trigger — rejected: the trigger does not know the message, and the actor would have to be read from session settings.

## R4 — `listing_state_change` is the history; the audit log is still written for staff

**Decision** (Clarification Q10): table `listing_state_change` (`change_id`, `listing_id`, `from_state` NULL for creation, `to_state`, `actor_customer_id` XOR `actor_staff_id`, `note`, `changed_at`, `txid`), append-only (`trg_listing_state_change_immutable`, SQLSTATE `DH004`). Notes: the reviewer's message (changes requested), the reason (rejected, taken down), `account_suspended` / `account_reinstated` for holds. Staff decisions also write an `audit_log` row in the same transaction.

**Rationale**: the seller must read the message (prototype "Changes needed"), staff need "sent back N times" — both are queries on this table. The audit log is closed to customers by RLS and is not a domain record.

**Alternatives**: two columns on `listing` — not chosen.

## R5 — Row-shape CHECKs on `listing`

**Decision**: besides the schema's `gold_needs_karat_weight` and `queue_count_nonneg`:

- `listing_price_shape`: gold ⇒ `making_charge_per_g IS NOT NULL AND asking_price IS NULL`; diamond and gold-with-diamond ⇒ `asking_price IS NOT NULL AND making_charge_per_g IS NULL`.
- `listing_amounts`: `stated_weight_g > 0`; `making_charge_per_g >= 0`; `asking_price > 0`; money with at most 2 decimals (`x = round(x, 2)`), stored `NUMERIC(18,4)` like everywhere.
- `listing_description_len`: `char_length(description) <= 2000`.
- `listing_listed_shape`: `listed_at IS NOT NULL` unless the state is `draft`, `in_review`, `changes_requested` or `rejected`.

Pure diamond: karat and weight are **prohibited by validation** (not by CHECK, the schema allows them).

**Rationale**: Part 2 §3's create body is complete from the first save, so a draft is always a whole, priceable row; the calculator never meets a gold listing without a making charge. These are constraints on existing columns, added to `04_schema_market.sql` first.

## R6 — Two columns the schema lacks on `listing_media`, one on `listing`

**Decision**: `listing_media.mime TEXT NOT NULL` (needed to serve the decrypted bytes with a type, as `topup.receipt_mime`), `listing_media.position SMALLINT NOT NULL DEFAULT 0` (photo order; the first photo is the card image), and `listing.state_changed_at TIMESTAMPTZ NOT NULL DEFAULT now()` (queue order "oldest first", the "waiting" time, "approved today"). All three go into `04_schema_market.sql` in the same change.

**Rationale**: storage/ordering facts with no other home. No business field is invented: diamond grading, origin and a custom type name stay out (Clarification Q12).

**Alternatives**: deriving the waiting time from `listing_state_change` on every list query — rejected (a join and a sort on a history table for the hottest staff query).

## R7 — Media: the existing private encrypted store, four new upload purposes

**Decision**:

- `UploadPurpose` gains `LISTING_PHOTO = 'listing_photo'`, `LISTING_VIDEO = 'listing_video'`, `LISTING_INVOICE = 'listing_invoice'`, `STONE_CERTIFICATE = 'stone_certificate'` (the names Part 2 §1 already uses; `listing_video` is new there).
- Types and sizes (Clarification Q6), content-sniffed: photo — jpg/png/webp, 8 MB; video — mp4/mov/webm, 50 MB; invoice and certificate — jpg/png/webp/pdf, 8 MB. `UploadPurpose::maxKilobytes()` is added beside `allowedMimes()`; config `dahab-listings.php` holds the numbers.
- All four are trade-gated inside `StoreUploadRequest` (the spec 009 pattern: the route stays open for identity uploads).
- Files go through `IdentityDocumentStorage::storeChunkedAt('listing-media', …)` on the `identity_private` disk, encrypted application-side in chunks (below). The token keeps the mime (already supported).
- Attaching: `UploadTokenStore::resolveEntry()` per token, under the listing's row lock; the token is forgotten after commit. An unknown / expired / used / wrong-purpose / other customer's token → 422 `upload_token_invalid` (existing code).
- Per-listing limits (6 photos, 1 video, 1 invoice, 1 certificate) are checked under the row lock.
- **Chunked encryption (analysis U1)**: listing media never passes through the whole-file `Crypt::encryptString` path. `IdentityDocumentStorage::storeChunkedAt()` reads the upload in 1 MiB chunks, encrypts each chunk with the application cipher and appends it as a length-prefixed frame (`DHC1` magic, then `uint32 length + ciphertext` per chunk) to a temporary file that is then written to the disk as a stream; `readChunked()` yields decrypted chunks. Peak memory is a few chunks, whatever the file size. Identity documents and top-up receipts keep their existing format.
- Serving: three endpoints stream the decrypted chunks (`StreamedResponse`) with the stored mime — public (`/market/listings/{listing}/media/{media}`: public media of a publicly visible listing), seller (`/customer/me/listings/{listing}/media/{media}`: all of their own) and staff (`/dashboard/listings/{listing}/media/{media}`: all). Every media response is `Cache-Control: no-store` (analysis I2), so nothing outlives a withdrawal or take-down. Responses carry these paths as `url`.
- Limits in the stack: `docker/php/uploads.ini` (`upload_max_filesize = 64M`, `post_max_size = 70M`) copied by the `Dockerfile`; `client_max_body_size 70m` and a longer `fastcgi_read_timeout` in `docker/nginx/default.conf`; `memory_limit` stays at the default.
- Proof: `ListingVideoStreamingTest` uploads a real 50 MB video through HTTP and plays it back through the market endpoint, asserting byte equality by hash and a peak-memory increase under 32 MB for each direction.
- A media row removed by an edit is deleted; its object is deleted after commit.
- `customer.uploads` rises from 10 to 20 per minute: one listing can need 9 uploads.

**Rationale**: the product owner asked for encrypted private storage through `POST /customer/me/uploads`. One store, one token mechanism, one place to swap for object storage later.

**Remaining limits (documented)**: media is served without HTTP range requests (a player downloads the file from the start; acceptable at ≤ 50 MB), and unclaimed uploads are not garbage-collected (follow-up, analysis U5). Pre-signed object storage remains the later swap ("changes this endpoint only", Part 2 §1).

**Alternatives**: a public disk for photos — rejected (the owner asked for private storage; a public URL outlives a take-down). Leaving video out — offered in clarify, not chosen.

## R8 — Price: one pricer over the spec 005 calculator

**Decision**: `App\Support\Listings\ListingPricer` loads `PricingContext` once per request (current gold price, every karat's pricing, rates) and prices a listing with `PriceCalculator::breakdown()`:

| Field | Gold | Gold with diamond | Diamond |
|---|---|---|---|
| `current_price` | `buyerTotal` | asking price | asking price |
| `price_parts` (detail) | `rate_per_gram` = buyers-pay for the karat, `gold_value` = round4(rate × weight), `making_total` = round4(making × weight) | null | null |
| `you_would_receive` (seller only) | `sellerProceeds` | `sellerProceeds` (needs the gold price for the protected gold value) | `sellerProceeds` |

`no_gold_price` / `price_inverted` are caught per listing → the figure is `null` and `price_available = false`. `price_is_indicative` is `true` for gold and `false` for fixed asking prices. Nothing is stored.

**Price sort**: the list query adds a computed column — for gold `stated_weight_g × (:buyers_pay[karat] + making_charge_per_g)` with one bound literal per quotable karat (`CASE karat_code …`), else `asking_price`; unquotable rows are `NULL` and sort last. Keyset cursor = (sort value, `listing_id`); `newest` uses (`listed_at`, `listing_id`). A cursor only positions the page; prices are recomputed (api-contract rule), so a rate change between pages can repeat or skip a piece (spec Edge Cases).

**Rationale**: FR-023 — one implementation of Part 3 §2. The SQL expression mirrors the calculator's `buyerTotal` up to rounding and is used for ordering only; the figure shown always comes from the calculator.

**Alternatives**: storing a price column refreshed by the feed job — rejected (Part 2: "computed live", not stored).

## R9 — Legal documents: the two schema tables, seeded, read-only

**Decision** (Clarification Q4): migration `create_legal_documents` creates `legal_document` and `agreement_acceptance` exactly as `02_schema_identity.sql`; `agreement_acceptance` gets forced RLS (`customer_id` owner policy) and an append-only trigger. The migration seeds `('ownership_declaration', 1, body_en, body_ar, is_material = false, published_by = system actor)` — the text is the prototype's tick-box sentence. `GET /api/v1/reference/legal-documents/{code}` returns the highest version of a code. Create-listing validates `ownership_legal_doc_id` against that current row; a mismatch is `ownership_declaration_required`. The acceptance is written to both tables with the request's IP and device id.

**Rationale**: Part 2 §3 and the schema; no management UI is asked for.

## R10 — Permissions, audit events, error codes

**Decision**:

| Code | Label | Seed (besides `ceo`) | Gates |
|---|---|---|---|
| `listing.review` | Approve or reject a new listing | `coo`, `operations` | approve, reject |
| `listing.request_changes` | Ask a seller for a better photo | `coo`, `operations` | request-changes |
| `listing.takedown` | Take a live listing down | `coo`, `operations` | takedown |

Group `Listings`. The queue, a listing and its media open with **any** of the three (`staff.permission:listing.review|listing.request_changes|listing.takedown`), so a role holding only one can still see what it acts on.

Audit events (new `AuditCategory::LISTINGS`, "Listings"): `listing.approved`, `listing.changes_requested`, `listing.rejected`, `listing.taken_down`; each with before/after state and the message as `reason`. Suspend / reinstate audit rows gain `listings_held` / `listings_restored` counts. Seller actions are not audited (Part 2 §3 "audited: no"); they are in the history.

Error codes (`DomainApiException`): `illegal_listing_transition` 409 (also SQLSTATE `DH004`), `listing_not_editable` 409, `seller_suspended` 409 (new, spec FR-030b), `karat_disabled` 409 (new, spec FR-030c: approval of a listing whose karat was turned off), `gold_needs_karat_weight` 422, `branch_options_required` 422, `ownership_declaration_required` 422, `photo_required` 422. `category_stopped` is not introduced (out of scope).

**Rationale**: Part 1 §4.1 rows map one-to-one to codes (spec 002: one code per protected action). A new audit category is a new enum value for the Dashboard's audit filter — checked in the Dashboard tasks.

## R11 — Gates and limits

**Decision**: seller reads (`GET` list, one, media) → `customer.gate:verified`; seller writes → `customer.gate:trade` + `idempotent` (the middleware already handles `PATCH`: it is method-agnostic). Create also gets `throttle:customer.listings` (20/min). Market and reference routes get `throttle:public.market` (120/min per IP). Staff POSTs get `idempotent`.

**Rationale**: spec FR-007, FR-008, FR-025, FR-038; `CustomerRouteGateTest` keeps every customer route gated.

## R12 — Suspension hold

**Decision** (Clarification Q14): `SuspendCustomerAction`, inside its transaction, moves each `live` listing of the customer to `suspended_hold` through `MovesListing` (actor = the staff member, note `account_suspended`); `ReinstateCustomerAction` moves each `suspended_hold` listing of theirs back to `live` (note `account_reinstated`; `listed_at` is kept). `ApproveListingAction` refuses a suspended seller with `seller_suspended` and a listing whose karat is no longer enabled with `karat_disabled`. No seller notification for holds.

**Rationale**: the allowed moves `live ↔ suspended_hold` already exist with the note "account suspended". Until category pauses exist, every hold is a suspension hold, so reinstating may release all of the customer's holds; the category-control spec must add a hold cause before it reuses the state (recorded in plan Follow-ups).

## R13 — Notifications

**Decision** (Clarification Q7): one `ListingDecisionNotification(ListingDecision $decision, string $pieceTitle, ?string $message)` — `approved`, `changes_requested`, `rejected`, `taken_down` — SMS always, mail when the customer has an email, English/Arabic by `preferred_lang`, `ShouldQueue`, dispatched `afterCommit`. `pieceTitle` is built from piece type + karat ("Gold ring, 21K").

**Rationale**: the spec 009 pattern; one class because the four messages differ only in wording.

## R14 — Lists and cursors

**Decision**: keyset pages everywhere (`per_page`, `cursor`, `meta.next_cursor`, malformed cursor → 422), the api-contract convention — not Part 2's `{items, next_cursor}` / `limit`. Market: default 20, max 100. Seller list: newest first by (`created_at`, `listing_id`). Staff queue: `state=in_review` oldest first by (`state_changed_at`, `listing_id`); other states newest first. `ListingCursor` follows `TopUpCursor`.

Indexes: `idx_listing_state (state, state_changed_at)`, `idx_listing_seller (seller_id, created_at DESC)`, `idx_listing_market (listed_at DESC, listing_id) WHERE state IN ('live','reserved')`, `idx_listing_media_listing (listing_id, kind, position)`, `idx_listing_state_change_listing (listing_id, changed_at)`.

## R15 — Staff counters

**Decision**: from `listing_state_change` and `listing`, in the staff detail: `sent_back_count` (rows of this listing with `to_state = changes_requested`), `seller_listings_sent_back` / `seller_listings_submitted` (the seller's listings having at least one such row / having ever entered `in_review`), `seller_listing_number` (rank by `created_at` among the seller's listings). In the staff list `meta.counts`: `in_review`, `changes_requested`, `approved_today` (moves `in_review → live` since Cairo midnight), `rejected`.

## R16 — Dashboard

**Decision**: replace the `listings` placeholder route with `src/pages/listings/index.vue`: state chips with counts, the queue table, and a review panel (media grid with full-size preview and video, invoice/certificate, seller, counters, fields, description, history) with *Approve and publish*, *Ask for changes* (the design's tick-box sentences prefill the message), *Reject*, and — for a live listing — *Take down*. Every confirmation uses `DModal`. The nav item is unhidden with `anyPermission` of the three codes and a live badge from `meta.counts.in_review`. The design's Export and listing reference are not built (Clarification Q16); "making charge as % of gold value" is computed in the page from the returned figures for display only.

## R17 — Customer App

**Decision**:

- `lib/services/api/listings_api.dart` (seller) and `market_api.dart` (public market + reference) implement `CatalogRepository` and the listings half of `OrdersRepository`; `main.dart` swaps the mocks for them. Orders stay mock.
- Sell flow: step 1 reads piece types and karats from `/reference/*`; step 2 picks real files (`file_picker`) and uploads them; step 3 gains a **branch choice** (one or more enabled branches — the prototype chooses the branch at acceptance, the Backend requires it at listing) and shows the declaration text from the API. *Send for approval* = `POST` create → `POST` submit, each with its own idempotency key kept across retries. *Fix and resend* reopens the flow on the listing (`PATCH` → submit).
- The step-1 "You receive" preview stays on the prototype maths (`lib/services/pricing.dart`, a documented placeholder); after the listing exists the app shows the API's `you_would_receive`.
- Stay mock or hidden, listed in the report: views, "asked to buy", saved pieces, share, report, promo code, *Change making charge*, Accept/Decline, the diamond guide values (kept on screen, not sent).

**Rationale**: spec FR-033; the Flutter project has no git — no branch there.
