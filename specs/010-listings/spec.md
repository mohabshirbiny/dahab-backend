# Feature Specification: Listings — selling a piece and browsing the market

**Feature Branch**: `feature/listings` (backend and dashboard; the Customer App has no repository yet). Spec work runs on the worktree branch `claude/listings-spec-lifecycle-b5c3e6`.

**Created**: 2026-09-30

**Status**: Draft

**Input**: User description: "Listings (spec 010) — selling a piece and browsing the market. Customer: create a draft listing and edit it (draft / changes_requested), submit for review, withdraw a live listing, list my own listings. Media (photos, video, private invoice, stone certificate) via POST /customer/me/uploads with new purposes (encrypted private storage; invoice never public before the sale). Branch options named at listing (>=1 enabled branch). Ownership declaration accepted at listing. Public market: browse live listings with filters, sort and cursor, plus detail; never expose seller_id, contact details or private media; current_price is the live sell-side price from the spec 005 calculator, labelled indicative. Dashboard: listing review queue — approve (in_review → live, sets listed_at), request changes (message to seller), take down (reason); audited, idempotent, behind new dynamic permission codes seeded per Technical Spec Part 1 §4.1 (CEO / COO / Operations). Flutter: sell-a-piece flow, my listings, market list and detail on the live API instead of mocks. State moves only along allowed transitions (guard trigger + illegal_listing_transition 409), each recorded in listing_transition. Out of scope: buy requests / queue / deposit holds, orders, IGI, settlement, market maker approval, category_control."

## Context

- **Sources**:
  - Technical Spec Part 2 §2 (Browsing): `GET /market/listings` and `GET /market/listings/{id}` — public, no account; filters `category`, `karat`, `piece_type`, `branch`, `min_g`, `max_g`, `is_market_maker`; sort `newest` / `price_asc` / `price_desc`; keyset cursor; `current_price` is the live sell-side price, indicative until a buy request locks it; never exposes `seller_id`, seller contact or private media.
  - Part 2 §3 (Selling): create draft, submit, edit (draft / changes requested), withdraw (live / reserved), and the staff review: list, approve (sets `listed_at`), request changes (message), take down (reason). Codes: `gold_needs_karat_weight`, `branch_options_required`, `ownership_declaration_required`, `category_stopped`, `photo_required`, `listing_not_editable`, `illegal_listing_transition`.
  - Part 2 §1 `POST /me/uploads`: purposes `listing_photo`, `listing_invoice`, `stone_certificate` are named but not built ("only `identity` is accepted until the other purposes have a consumer").
  - Part 1 §4.1: *Approve or reject a new listing*, *Ask a seller for a better photo*, *Take a live listing down* — CEO, COO, Operations. Part 1 §2.2: listing is a trade action (verified and not suspended). Part 1 §5.3: public browsing never leaks seller identity; served outside the owner's row-level policy.
  - Part 3 §2 as amended by spec 005: the buyer's price for gold is `buyers_pay(karat) × weight + making charge × weight`; diamond and gold-with-diamond are the seller's fixed asking price.
  - Schema `04_schema_market.sql` §7: `listing`, `listing_media`, `listing_branch_option`, `listing_ownership_declaration`, `listing_queue_seq`; `01_schema_core.sql`: `listing_state`, `piece_category`; `05_schema_security.sql`: `listing_transition` (the **table of allowed moves**, not a history), the planned owner policy `seller_id = current customer`, and the note that the public read is a dedicated view or separate policy; `02_schema_identity.sql`: `legal_document`, `agreement_acceptance`.
  - Dashboard design, "Listings to review": chips (Waiting, Changes asked, Approved today, Rejected), a Waiting-for-review table (piece, seller, asking, waiting time; oldest first; Export) and a review panel — photos, video, original invoice ("Buyers never see this"), seller name / reference / masked phone, "Sent back on this piece", "Sent back across all their listings", stated weight and karat, making charge and its share of the gold value, description, and three actions: *Approve and publish*, *Ask for changes* (tick-box reasons + free text that "goes to the seller"), *Reject*.
  - Customer prototype: *Sell your piece* (3 steps: piece and price → photos, video, invoice, description → review, promo code, ownership tick, *Send for approval*), *My listings* (All / Live / In review / Sold; "Waiting for approval", "Changes needed" with the reviewer's message and *Fix and resend*, Live with "Your making charge", "You would receive", *Change making charge*, *Take it down*), *Browse* (search, chips, "Yours" marker) and the piece page (photos + video, price that "moves with the gold rate until you send a request", breakdown, specifications, seller's description, "Seller 4417 · Identity verified", share, report, save).
- **What exists**: karats, piece types (seeded per category), branches with an enabled flag (spec 004); the price calculator and the current gold price (spec 005); the customer verified / trade gate (spec 002); forced row-level security for customer tables with a build test (spec 003); the private encrypted uploads store with single-use tokens for `identity` and `topup_receipt` (specs 001, 009); the shared `Idempotency-Key` layer (spec 007); the audit log (spec 006); SMS + email notifications after commit (spec 009); customer suspension (spec 007). The Dashboard has a placeholder route "Listings to review". The Customer App's sell flow, My listings, Browse and piece page run on mocks.
- **What does not exist** (found while reading the code): no `listing*` tables; no `legal_document` / `agreement_acceptance` tables; no unauthenticated route other than health; no customer-facing read of karats, piece types or branches; uploads accept images (and PDF for receipts) up to 8 MB only, and nothing stored there is ever served to customers.
- **Why now**: listings are the first half of the marketplace; buy requests (the next module) need live listings to attach to.

## Clarifications

### Session 2026-09-30

- Q: Where do the endpoints live, given the codebase convention (`/customer/*`, `/dashboard/*`) and no public routes besides health? → A: Seller: `/api/v1/customer/me/listings*`. Staff: `/api/v1/dashboard/listings*`. Market: a **new public surface** `/api/v1/market/listings` and `/{id}`, no token needed; a customer token is optional and only marks the caller's own pieces. The Technical Spec's `/listings`, `/admin/listings` paths are rewritten to these.
- Q: How is the public market read kept from returning seller data (Part 1 §5.3 asks for a dedicated view and a low-privilege role)? → A: **No view and no separate role.** The market reads the listing table under a new read-only `market` row-level-security scope that sees only publicly visible listings, and the market's response shape leaves seller fields out. A test fails the build if any market response carries a seller field or private media. Part 1 §5.3 and the security schema note are rewritten to this (product-owner decision).
- Q: With no buy-request queue yet, do withdraw and take-down work from live only? → A: Yes. Only live → withdrawn is reachable; reserved → withdrawn (with releases and refunds) is wired by the buy-request spec.
- Q: `legal_document` and `agreement_acceptance` do not exist — how is the ownership declaration recorded? → A: Create both tables as the schema defines them; seed `ownership_declaration` version 1 (English + Arabic, published by the system actor); the app reads the current text and its id; each acceptance is written to both `agreement_acceptance` (context `list_piece`) and `listing_ownership_declaration`. No Dashboard document management in this feature.
- Q: Is the market-maker flag and filter in scope? → A: No. No field, no filter, until market-maker approval.
- Q: Which media limits and submit rules apply? → A: Photos: JPEG/PNG/WebP, 8 MB each, at most 6; to submit, at least 2 for gold and 3 for diamond or gold-and-diamond. Video: optional, one, MP4/MOV/WebM, up to 50 MB. Invoice and stone certificate: one each, image or PDF, 8 MB. Description 40 to 2,000 characters, required to submit.
- Q: Is the seller told of staff decisions? → A: Yes — SMS, plus email when the customer has one, in their language, sent only after the change commits: approved; changes requested (with the message); taken down (with the reason); and rejected (with the reason). No in-app inbox yet.
- Q: Can a withdrawn listing be relisted? → A: No. Withdrawn is final; the seller creates a new listing, which is reviewed.
- Q: The design has *Reject* and the permission says "Approve or reject", but there is no rejected state — what is reject? → A: A new final state **`rejected`** and the move in review → rejected, with a required reason the seller sees; audited, idempotent, under the review permission. The schema enum, the allowed moves and the Technical Spec are updated.
- Q: `listing_transition` is the table of allowed moves, not a history — where are each state change and its message kept? → A: A new append-only **listing history** (`listing_state_change`): listing, old state, new state, actor (customer or staff), message/reason, time. It feeds the seller's "changes needed" message, the take-down and rejection reasons and the Dashboard's "sent back" counters. Staff actions are still written to the audit log.
- Q: The sell form and filters need karats, piece types and branches, but no customer-facing endpoint returns them — what is added? → A: Unauthenticated read endpoints on the public surface for enabled karats, enabled piece types (per category, English/Arabic, typical weights) and enabled branches (name, address).
- Q: Diamond details (carat, clarity, cut, lab), origin and a custom type name have no columns — what happens? → A: No new columns. Sellers write them in the description; the app keeps its on-screen diamond guide but does not send those values.
- Q: Is the stone certificate public once the listing is live? → A: Yes, shown on the piece page like a photo. Only the invoice is private. Part 2 §2 is corrected.
- Q: What happens to a suspended customer's live listings? → A: Suspending moves them to `suspended_hold` (off the market) in the same operation; reinstating returns them to live. The spec 007 suspend and reinstate actions change.
- Q: Which figures beyond `current_price` are returned? → A: The market detail adds the calculator's parts for gold (rate per gram, gold value, making charge total). The seller's own listings add "you would receive" (seller proceeds after commission and VAT, indicative). No views, no seller information, no deposit figure.
- Q: Which Dashboard extras from the design are in? → A: State counts for the chips, "sent back on this piece", "sent back across all their listings" and the seller's number of listings. No export and no listing reference number.
- Q: (after analysis) Are the planner's ten additional choices accepted? → A: Yes, all ten: a rejection notifies the seller with its reason; `seller_suspended` (409) on approving a suspended seller's listing; the columns `listing_media.mime`, `listing_media.position`, `listing.state_changed_at`; the database CHECKs on `listing` (price shape per category, amounts, description length, `listed_at` shape) beside application validation; the public `GET /reference/legal-documents/{code}`; `customer.uploads` at 20 per minute; an optional customer token on `/market/*` for `is_mine`, with the market still open without one; the branch choice collected in the Customer App's sell flow before sending; the `listings` audit category; a Live chip in the Dashboard as the way to the take-down.
- Q: (analysis C1) Does the public read get a database view after all? → A: No. The recorded deviation stands: public market → `market` row-level-security scope → listings → public response shape, with tests proving seller and private fields cannot leak. It is written in the Constitution Check and the PR notes.
- Q: (analysis I1) Which permission opens the queue, a listing and its media, invoice included? → A: Any of the three listing permissions.
- Q: (analysis I2) May public media be cached? → A: No — `no-store`, so a withdrawn or taken-down piece's media is gone within the 5-second rule.
- Q: (analysis U1) Does the 50 MB video limit stay, given whole-file encryption in memory? → A: It stays. Listing media is encrypted and served as a stream of chunks, never held whole in memory; PHP, nginx and Docker upload limits are set; a real 50 MB upload and playback test proves it within a memory bound.
- Q: (analysis I3) Which weight field name do responses use? → A: `stated_weight_g` in the seller and staff shapes (as in requests); `weight_g` on the market (Part 2 §2).
- Q: (analysis U3) May a seller-scope write add any history row for their listing? → A: No. In the customer scope a history row must name the current customer as its actor.
- Q: (analysis U4) What if the listing's karat was turned off while it waited for review? → A: Approval is refused with a conflict (`karat_disabled`); the listing stays in review and can be sent back or rejected.
- Q: (final state rules) Which moves exist in this feature? → A: `in_review → live`, `in_review → changes_requested`, `in_review → rejected`, `live → withdrawn`, `live → suspended_hold`, `suspended_hold → live` (plus the seller's `draft → in_review` and `changes_requested → in_review`). `rejected` and `withdrawn` are final: there is no `withdrawn → in_review` and no `withdrawn → live`. Staff take-down and seller withdrawal are reachable from `live` only. Unclaimed-upload clean-up stays a follow-up.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A seller lists a piece and sends it for review (Priority: P1)

A verified customer opens *Sell your piece*. They choose what they are selling (gold, diamond, or gold and diamond), the type of piece, and — for gold — the karat, weight and making charge, or the asking price for stones. They add photos, optionally a short video, the original invoice (kept private) and a stone certificate, write a description, name the branch or branches they are willing to bring the piece to, tick the ownership declaration and send it for approval. The piece is saved as theirs and waits for Dahab's review; it is not on the market yet.

**Why this priority**: nothing else in the marketplace exists without a listing.

**Independent Test**: As a verified customer, upload two photos, create a gold listing (21K, 8.000 g, making charge 250, one enabled branch, ownership accepted), submit it, and see it in *My listings* as waiting for approval. Repeat as an unverified and as a suspended customer — both are refused and nothing is recorded.

**Acceptance Scenarios**:

1. **Given** a verified, non-suspended customer, **When** they create a listing with a category, an enabled piece type of that category, the fields that category needs, at least one enabled branch, their uploaded media and the accepted ownership declaration, **Then** a draft listing is recorded as theirs, with its media, branch options and the declaration (who, when, which version of the text).
2. **Given** a gold or gold-and-diamond listing without a karat or a weight, **When** it is created, **Then** it is refused as `gold_needs_karat_weight` and nothing is recorded.
3. **Given** no branch, a branch that is not enabled, or an unknown branch, **When** the listing is created or edited, **Then** it is refused as `branch_options_required`.
4. **Given** the ownership declaration is not accepted, **When** the listing is created, **Then** it is refused as `ownership_declaration_required`.
5. **Given** a draft with at least the required photos, **When** the seller submits it, **Then** it moves to in review; a draft without them is refused as `photo_required`.
6. **Given** a customer awaiting verification or rejected, **When** they try to create, edit, submit or withdraw a listing, **Then** they are refused as verification required; a suspended customer is refused as account suspended.
7. **Given** the same create or submit is sent twice with the same idempotency key, **Then** exactly one listing (or one move) exists and both responses are the same.
8. **Given** an upload token that is unknown, expired, already used, for another purpose or another customer's, **When** it is attached to a listing, **Then** the request is refused and nothing is recorded.

---

### User Story 2 - Dahab reviews the listing before it goes live (Priority: P1)

An Operations staff member (or a founder) opens *Listings to review*. They see the pieces waiting, oldest first. They open one: every photo, the video, the private invoice and certificate, the stated karat, weight, making charge or asking price, the description, and who the seller is. They approve it — it goes live at once — ask for changes with a message the seller will read, or reject it with a reason. Whatever they choose is logged against their name.

**Why this priority**: a piece goes live only after someone approves it; without the review nothing reaches the market.

**Independent Test**: With a listing in review, an Operations user approves it; it becomes live with its listing time set, appears on the public market, and the audit log shows the approval. With another, they ask for changes with a message; the seller sees the message on the listing in *My listings*. A Finance user (default roles) is refused.

**Acceptance Scenarios**:

1. **Given** a staff member with any listing permission, **When** they open the queue, **Then** they see listings in review, oldest first, across all sellers, with the piece, the seller's reference, the price and how long it has waited; they can filter by state.
2. **Given** a listing in review, **When** they approve it, **Then** it becomes live, its listing time is set, it is visible on the market, and an audit row names the staff member.
3. **Given** a listing in review, **When** they ask for changes with a message, **Then** it becomes changes requested, the seller can read the message, and an audit row carries the message as the reason. A missing message is refused.
4. **Given** a listing that is not in review, **When** they approve or ask for changes, **Then** they are refused as `illegal_listing_transition` and nothing changes.
5. **Given** two staff act on the same listing at the same moment, **Then** exactly one succeeds; the other is refused as `illegal_listing_transition`.
6. **Given** a staff member without the permission (Finance, Verification, IGI on default roles), **When** they call any review endpoint, **Then** they are refused as permission denied and the denial is audited.
7. **Given** the same approve / request-changes is sent twice with the same idempotency key, **Then** it takes effect once.
8. **Given** the reviewer opens the private invoice, **Then** it is served to staff with any listing permission (and to its seller) only — never on the market.
9. **Given** a listing in review, **When** they reject it with a reason, **Then** it becomes rejected for good, the seller sees the reason and is told, and an audit row carries it; a missing reason is refused.
10. **Given** a listing in review whose seller is suspended, **When** they approve it, **Then** it is refused as `seller_suspended`; **Given** one whose karat has been turned off since it was submitted, **Then** approval is refused as `karat_disabled` and it stays in review.
11. **Given** any staff decision (approve, ask for changes, reject, take down), **Then** the seller is sent an SMS, and an email when they have one, after the change is saved.

---

### User Story 3 - Anyone browses the market and opens a piece (Priority: P1)

Anyone — without an account — opens the market and sees the live pieces: photos, type, karat, weight, making charge and the current price. The price of a gold piece follows the gold rate; it is shown as indicative. They filter by category, karat, type, branch and weight, sort by newest or price, scroll through pages, and open a piece to see its photos, video, description and the branches it can be inspected at. Nothing tells them who the seller is.

**Why this priority**: the market is the reason sellers list; it is also the public face of Dahab.

**Independent Test**: With three live listings and one in review, call the market list with no token: exactly the three live ones are returned, with a price computed from the current gold price, and no seller field anywhere in the response. Open one by id; open the in-review one and get not found.

**Acceptance Scenarios**:

1. **Given** no token, **When** the market list is requested, **Then** only publicly visible listings are returned, newest first by default, each with category, piece type, karat, weight, making charge, current price, public photos, branch options and the queue count.
2. **Given** a gold listing, **Then** its current price is the spec 005 sell-side price (`buyers_pay(karat) × weight + making charge × weight`) at the current gold price, marked indicative; a diamond or gold-and-diamond listing shows the seller's asking price.
3. **Given** filters and a sort, **Then** only matching listings are returned in that order, a page at a time, with a cursor to the next page; a malformed cursor or filter is a validation error.
4. **Given** a listing that is a draft, in review, changes requested, withdrawn or otherwise not publicly visible, **When** it is opened on the market, **Then** the answer is not found.
5. **Given** any market response, **Then** it never contains the seller's id, reference, name, phone or email, nor the invoice or any private media.
6. **Given** a public photo or video of a live listing, **Then** it can be viewed without an account; the same file stops being served when the listing leaves the market.
7. **Given** there is no usable gold price (none yet, or the karat's prices are inverted), **Then** gold listings are still listed, with no current price and a flag saying the price is unavailable.
8. **Given** a signed-in customer browsing, **Then** their own live listings are marked as theirs.

---

### User Story 4 - The seller follows, fixes and withdraws their listings (Priority: P2)

A seller opens *My listings* and sees each piece with its state: waiting for approval, changes needed (with the reviewer's message), live, rejected (with the reason), withdrawn, on hold. For a piece sent back, they fix what was asked — replace a photo, correct the weight, extend the description — and resend it. For a live piece they no longer want to sell, they take it down.

**Why this priority**: the review loop only works if the seller can see the request and answer it; withdrawing is the seller's exit.

**Independent Test**: A seller with a changes-requested listing edits its description, swaps a photo, resubmits and sees it waiting for approval again. A seller with a live listing withdraws it; it disappears from the market and shows as withdrawn in *My listings*. Another customer cannot see, edit or withdraw it.

**Acceptance Scenarios**:

1. **Given** a seller with listings, **When** they open *My listings*, **Then** they see only their own, newest first, each with its state, details, media, branch options, the reviewer's latest message when changes were requested, and — while it can be priced — what they would receive if it sold now.
2. **Given** a draft or changes-requested listing, **When** the seller edits it, **Then** the changes are saved; a listing in any other state is refused as `listing_not_editable`.
3. **Given** a changes-requested listing, **When** the seller resubmits it, **Then** it returns to in review.
4. **Given** a live listing, **When** the seller withdraws it, **Then** it becomes withdrawn and leaves the market at once; any other state is refused as `illegal_listing_transition`.
5. **Given** another customer's listing id, **When** a customer reads, edits, submits or withdraws it, **Then** the answer is not found — enforced by the database, not only by the application.
6. **Given** a suspended seller, **Then** they can still read their own listings but cannot create, edit, submit or withdraw.

---

### User Story 5 - Staff take a live listing down (Priority: P2)

Operations find a live listing that should not be on the market (photos taken from elsewhere, a complaint, a wrong description). They take it down with a reason. It leaves the market at once, the seller is told, and the action is logged.

**Why this priority**: the market needs a way to remove a piece after it went live; less frequent than review.

**Independent Test**: With a live listing, an Operations user takes it down with a reason; the market no longer returns it, the seller sees it as taken down with the reason, and the audit log shows the action.

**Acceptance Scenarios**:

1. **Given** a live listing and a staff member with the take-down permission, **When** they take it down with a reason, **Then** it becomes withdrawn, leaves the market, and an audit row carries the reason.
2. **Given** no reason, **Then** the request is refused as a validation error.
3. **Given** a listing that is not live, **Then** it is refused as `illegal_listing_transition`.
4. **Given** the same take-down is sent twice with the same idempotency key, **Then** it takes effect once.

---

### User Story 6 - The Customer App and the Dashboard use the real thing (Priority: P2)

The Customer App's *Sell your piece*, *My listings*, *Browse* and piece page, and the Dashboard's *Listings to review*, stop using mock data and work against the API above.

**Why this priority**: the feature is delivered to people only through the two apps; they depend on Stories 1–5.

**Independent Test**: In the Customer App, list a piece end to end, see it waiting in *My listings*; in the Dashboard approve it; back in the app the piece is on *Browse* without signing in, and its page opens.

**Acceptance Scenarios**:

1. **Given** the Customer App, **When** a seller completes the sell flow, **Then** the photos are uploaded, the listing is created and submitted, and failures (validation, gate, lost connection) are shown on the right step without losing what was entered.
2. **Given** the Customer App with no session, **When** Browse or a piece page opens, **Then** they load from the public market.
3. **Given** the Dashboard, **When** a staff member without the listing permissions signs in, **Then** *Listings to review* is not shown and its route is refused.
4. **Given** the Dashboard review panel, **Then** approve, ask for changes, reject and take down use confirmation modals (the shared modal component) and an idempotency key per action.

### Edge Cases

- **Seller edits while staff review**: a listing in review cannot be edited; the seller waits for the outcome.
- **Seller withdraws while staff take it down / approve while seller…**: exactly one move wins; the other is refused as `illegal_listing_transition`.
- **Karat or piece type turned off after the draft was created**: submitting is refused as a validation error naming the field; a listing already live carries on ("Turning a karat off stops new listings; pieces already listed carry on").
- **Branch disabled after the draft was created**: submitting is refused as `branch_options_required` unless at least one named branch is still enabled; a live listing keeps its branch options.
- **Media removed by an edit**: a photo dropped from the listing is no longer served anywhere.
- **Upload never attached**: an upload whose token expires unclaimed is never served and belongs to no listing.
- **Gold price changes between two pages of a price-sorted list**: each page is priced at the moment it is served; the cursor only positions the page, so a piece may appear twice or be skipped across pages when the rate moves, never with a wrong price.
- **Price cannot be computed** (no gold price, inverted karat): the listing stays listed without a price and sorts last in price order.
- **Idempotency key reused with a different body**: refused by the shared idempotency layer.
- **A seller suspended while a listing is live**: the listing moves to suspended hold and leaves the market; it returns to live when they are reinstated. A listing of theirs waiting for review stays in review and cannot be approved while they are suspended; it can be sent back or rejected.
- **Seven photos, a second video, a 60 MB video, an executable renamed .jpg**: refused as validation errors.
- **The declaration text gets a new version between opening the form and sending**: the listing is refused as `ownership_declaration_required`; the app shows the new text.
- **Piece type does not belong to the chosen category**: validation error.
- **Weight or money with too many decimals, zero or negative**: validation error (weight > 0 with at most 3 decimals; making charge ≥ 0 and asking price > 0 with at most 2 decimals).

## Requirements *(mandatory)*

### Functional Requirements

**Creating and editing (seller)**

- **FR-001**: A customer who passes the trade gate (verified and not suspended) MUST be able to create a draft listing with: category; a piece type that is enabled and belongs to that category; for gold — karat (enabled), stated weight and making charge per gram; for diamond — an asking price; for gold-and-diamond — karat, stated weight and an asking price; a description; one or more branch options; media; and the accepted ownership declaration.
- **FR-002**: A listing that is not pure diamond MUST have a karat and a weight (`gold_needs_karat_weight`), enforced by the data as well as by validation.
- **FR-003**: A listing MUST name at least one branch, each an enabled branch (`branch_options_required`). The set is what the seller is willing to deliver to; the final branch is chosen later from that set (out of scope).
- **FR-004**: Creating a listing MUST require the ownership declaration to be accepted (`ownership_declaration_required`). The acceptance MUST be recorded, in the same all-or-nothing operation as the listing, as evidence: who, when, which version of the declaration text, tied to the listing, and in the general record of agreement acceptances with the context "list a piece".
- **FR-005**: Creating a listing MUST also create its queue counter so that buy requests (next module) can take positions.
- **FR-006**: The seller MUST be able to edit a listing only while it is a draft or changes requested (`listing_not_editable` otherwise): any of the FR-001 fields, the branch options and the media.
- **FR-007**: Create, edit, submit and withdraw MUST each require an idempotency key.
- **FR-008**: Customers awaiting verification or rejected MUST be refused with `verification_required` and suspended customers with `account_suspended` on create, edit, submit and withdraw. Reading one's own listings requires verification only (a suspended seller may read).

**Media**

- **FR-009**: Listing media MUST be uploaded through the existing customer uploads endpoint with new purposes — listing photo, listing video, listing invoice, stone certificate — stored encrypted in the private store, and attached to a listing by single-use upload tokens bound to the customer and purpose. Uploading listing media requires the trade gate.
- **FR-010**: The invoice MUST be private: it is never returned or served on the market or to another customer; its seller can view it, and so can staff holding any listing permission. The stone certificate is public once the listing is publicly visible, like a photo.
- **FR-011**: Photos and the video of a publicly visible listing MUST be viewable without an account, and only while the listing is publicly visible; the seller and staff holding any listing permission can view them in every state. Public media responses MUST NOT be cacheable, so media is gone as soon as the listing leaves the market.
- **FR-012**: Media limits MUST be enforced: photos — JPEG, PNG or WebP, at most 8 MB each, at most 6 per listing; video — at most one, MP4, MOV or WebM, at most 50 MB; invoice and stone certificate — at most one each, an image or PDF, at most 8 MB. File types are checked by content, not by name. Listing media MUST be encrypted, stored and served as a stream, so that a 50 MB video is uploaded and played back without the server holding the whole file in memory.

**Submitting, withdrawing and the state machine**

- **FR-013**: Submitting MUST move a draft or changes-requested listing to in review, and MUST be refused as `photo_required` with fewer than 2 photos (gold) or 3 photos (diamond, gold-and-diamond), and as a validation error without a description of 40 to 2,000 characters. Submitting re-checks that the karat, piece type and at least one branch option are still enabled.
- **FR-014**: The seller MUST be able to withdraw their own live listing; it becomes withdrawn and leaves the market immediately.
- **FR-015**: A listing's state MUST change only along the allowed moves held as data (`listing_transition`); the database itself MUST refuse any other move, and the API answers `illegal_listing_transition` (409). Moves MUST be safe under concurrency: of two competing moves exactly one wins.
- **FR-016**: This feature uses only these moves: draft → in review; in review → changes requested; changes requested → in review; in review → live; in review → rejected (new); live → withdrawn; live → suspended hold and back (FR-037). The remaining allowed moves (reserved, accepted, inspection, settlement, category holds) are seeded but not reachable until their modules exist. **Withdrawn and rejected are final**: there is no move out of either (no withdrawn → in review, no withdrawn → live), and a withdrawn or rejected piece is sold again only as a new listing. Seller withdrawal and staff take-down are reachable from live only.
- **FR-017**: Every state change MUST be recorded with the listing, the old and new state, who did it (customer or staff), when, and the message or reason when there is one, in an append-only listing history written in the same all-or-nothing operation as the move, so that the seller can read the reviewer's message and staff can see how many times a piece was sent back. History rows are never edited or deleted.

**Seller's own listings**

- **FR-018**: A seller MUST be able to list their own listings, newest first, a page at a time, optionally filtered by state, and open one; each carries its state, fields, media (with private items marked), branch options, the times it was created and listed, the latest staff message when changes were requested, it was rejected or it was taken down, its current price and — while it can be priced — what the seller would receive if it sold now (the calculator's seller proceeds after commission and VAT, marked indicative).
- **FR-019**: Row-level security MUST isolate listings and everything attached to them (media, branch options, declaration, history) by seller: a customer can never read or change another customer's listing through the seller endpoints, even if application code forgets the filter.

**Public market**

- **FR-020**: Anyone, without an account, MUST be able to list publicly visible listings (live, and reserved once the queue exists) and open one. The market reads under its own read-only database scope that sees only publicly visible listings and can write nothing; its responses MUST NOT contain the seller's id, reference, name, phone, email, the invoice or any private media, and an automated test MUST fail the build if any market response does.
- **FR-021**: The market list MUST support filters — category, karat, piece type, branch (any of the listing's options), minimum and maximum weight — and sorts — newest (default), price ascending, price descending — with keyset pages (default 20, at most 100).
- **FR-022**: Each market item MUST carry: listing id, category, piece type, karat, weight, making charge per gram, current price, public photos, branch options, queue count (0 until buy requests exist), whether the price is indicative, and — for a signed-in customer — whether the piece is their own. The detail adds the description, the video, the stone certificate, when it was listed and, for gold, the parts of the price: the rate per gram, the gold value and the making charge total. There is no market-maker flag or filter in this feature.
- **FR-023**: `current_price` MUST come from the single price calculator (spec 005): for gold, the sell-side total at the current gold price; for diamond and gold-and-diamond, the asking price. It is never stored. When it cannot be computed it is null with a flag, and the listing is still returned.
- **FR-024**: A listing that is not publicly visible MUST answer not found on the market, whatever its id.
- **FR-025**: The public market MUST be rate limited.

**Staff review**

- **FR-026**: Three new permission codes MUST gate the staff side, each seeded to CEO, COO and Operations (Part 1 §4.1) and editable from the Dashboard like every other code: **review listings** (see the queue and a listing with all its media; approve; reject — Part 1's "Approve or reject a new listing"); **ask a seller for changes**; **take a live listing down**.
- **FR-027**: Staff holding any of the three listing permissions MUST be able to list listings across sellers filtered by state (default: in review, oldest first), a page at a time, and open one with all its fields, all its media including the private ones, the seller (reference, name, masked phone), its history, how many times this piece was sent back, how many of the seller's listings were ever sent back out of how many they have submitted, and which listing of the seller's this is. The queue also returns the number of listings per state for the chips (waiting, changes asked, approved today, rejected). There is no export.
- **FR-028**: Approving MUST move in review → live and set the listing time, in one all-or-nothing operation with its audit row.
- **FR-029**: Asking for changes MUST move in review → changes requested with a required message that the seller can read; audited with the message as the reason.
- **FR-030**: Taking down MUST move live → withdrawn with a required reason; audited with the reason. The seller can see that Dahab took it down and why.
- **FR-030a**: Rejecting MUST move in review → rejected with a required reason the seller can read; audited with the reason. A rejected listing is final.
- **FR-030b**: Approving MUST be refused as a conflict (`seller_suspended`) while the seller is suspended, so a suspended customer's piece never reaches the market.
- **FR-030c**: Approving MUST be refused as a conflict (`karat_disabled`) when the listing's karat has been turned off since it was submitted; the listing stays in review.
- **FR-031**: Approve, ask for changes, reject and take down MUST each require an idempotency key and MUST be audited with the staff member as the actor, the listing, and the state before and after.

**Notifications**

- **FR-032**: The seller MUST be sent a message when their listing is approved, when changes are requested (with the message), when it is rejected (with the reason) and when staff take it down (with the reason). Channel: SMS, plus email when the customer has one, in their preferred language. Messages are sent in the background only after the change commits; a failed message never undoes the change. No message for the seller's own actions or for a suspension hold.

**Suspension, reference data and the declaration text**

- **FR-037**: Suspending a customer MUST move every live listing of theirs to suspended hold, off the market, in the same all-or-nothing operation as the suspension; reinstating MUST return every suspended-hold listing of theirs to live. Both are recorded in the listing history with the staff member as the actor. Listings in other states are untouched.
- **FR-038**: Anyone, without an account, MUST be able to read the enabled karats, the enabled piece types (category, English and Arabic names, typical weights) and the enabled branches (English and Arabic name and address). These reads expose nothing else and are rate limited.
- **FR-039**: The current ownership declaration (its id, version and English and Arabic text) MUST be readable by the app, and the listing MUST be refused as `ownership_declaration_required` if the accepted id is not the current version.

**Apps**

- **FR-033**: The Customer App MUST run the sell flow, *My listings*, *Browse* and the piece page on the API; anything the API does not provide (views, saved pieces, share link, report, promo codes, queue, buy request) stays on its mock or is hidden, and is listed in the report.
- **FR-034**: The Dashboard MUST replace the "Listings to review" placeholder with the queue and the review panel, shown only to staff holding the permissions, using the shared modal component for every confirmation.

**Quality and contract**

- **FR-035**: Create, edit, submit, withdraw, approve, ask for changes, reject, take down and the suspension hold MUST each be covered by feature tests through the HTTP boundary asserting on persisted rows, the response, the audit row, idempotent replay and the refusal paths (gate, permission, illegal move, other seller's listing); the market MUST be tested for the absence of seller data and private media.
- **FR-036**: Every new endpoint MUST carry OpenAPI annotations and a Postman request; Technical Spec Part 1 §4.1 / §5.1 / §5.3, Part 2 §1–§3 and §12, the market and security schema docs and `docs/platform/api-contract.md` MUST be updated in the same change.

### Key Entities

- **Listing**: a piece put up for sale by one seller — category, piece type, karat and stated weight (not for pure diamond), making charge per gram (gold), asking price (stones), description, state, queue count, when created and when it went live.
- **Listing media**: a photo, a video, the original invoice or a stone certificate attached to a listing; each marked private or public; stored encrypted.
- **Branch option**: a branch the seller is willing to bring the piece to; one or more per listing.
- **Ownership declaration**: the seller's acceptance, for this piece, of a specific version of the ownership text — who and when.
- **Legal document / agreement acceptance**: the versioned texts customers agree to and the permanent record of each acceptance (schema §6).
- **Allowed listing moves** (`listing_transition`): the table of legal state changes that the database enforces.
- **Listing history**: one permanent row per state change — old and new state, actor (customer or staff), time, message or reason.
- **Queue counter**: one per listing, created with it, used by buy requests later.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A verified seller with photos at hand can go from opening *Sell your piece* to "sent for approval" in under 5 minutes.
- **SC-002**: A reviewer can approve or send back a waiting listing in under 1 minute from opening the queue.
- **SC-003**: An approved listing is visible on the market, and a withdrawn or taken-down one is gone, within 5 seconds of the action.
- **SC-004**: 0 market responses ever contain a seller's identity, contact details or a private document.
- **SC-005**: 0 listings reach the market without an approval recorded in the audit log under a staff member's name.
- **SC-006**: 0 listing state changes happen outside the allowed moves, including under simultaneous actions.
- **SC-007**: The first page of the market opens in under 2 seconds for a visitor with no account.
- **SC-008**: 0 customers who are unverified or suspended can create or submit a listing; 0 staff without the permissions can review or take down.

## Assumptions

- The customer gate for every seller write is **trade** (Part 2 §3); reading one's own listings is **verified**, like the wallet (a suspended customer may read).
- `category_control` (`category_stopped`) is out of scope: no category stop exists yet, so creating a listing is never refused for it.
- Withdrawing from **reserved**, releasing queued requests and refunds are out of scope: there is no queue, so only **live → withdrawn** is reachable.
- The market-maker flag and filter are out of scope.
- Approving does not re-price or lock anything; the price only locks when a buy request is placed (next module).
- *Change making charge* on a live listing (prototype `editprice`) is out of scope: Part 2 §3 allows edits only in draft / changes requested.
- Diamond grading details (carat, clarity, cut), the certificate's lab, the piece's origin and a free-text "other" piece type are not in the schema; they go in the seller's description.
- Views, saved pieces, sharing, reporting a listing, promo codes at listing, payout averages and the Rapaport suggestion stay mocked or hidden in the Customer App.
- The photo minimum counts photos only; the Backend does not know which photo is the hallmark or the full piece (the schema has no photo label) — the app's slots guide the seller and the reviewer checks.
- A video's length (the prototype's 10 to 15 seconds) is guidance in the app, not checked by the Backend.
- `seller_suspended` (FR-030b) and `karat_disabled` (FR-030c) are new conflict codes introduced by this spec (accepted by the product owner): the first so that the suspension hold cannot be bypassed by an approval, the second so that a piece in a karat Dahab stopped quoting cannot go live.
- Unclaimed uploads are not cleaned up by this feature (follow-up).
- The `suspended_hold` state is shared with category pauses (out of scope); until those exist, every held listing is held because its seller is suspended.
- The market list is not searchable by free text; the prototype's search box filters the loaded page on the device.
- Seed data for local development adds a few listings in each state; the ownership declaration text is seeded as version 1.
