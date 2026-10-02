# Feature Specification: Buy requests — the queue, the deposit hold and the seller's answer

**Feature Branch**: `feature/buy-requests` (backend and dashboard; the Customer App has no repository yet). Spec work runs on the worktree branch `claude/buy-requests-spec-011-969c24`.

**Created**: 2026-09-30

**Status**: Draft

**Input**: User description: "Buy requests (spec 011) — a buyer asks to buy a live listing, a deposit is held, requests queue per listing, and the seller accepts or declines. Customer (buyer): send a buy request on a live listing; deposit held from the wallet via the spec 008 ledger money service; see my requests and my queue position; leave the queue (deposit released). Customer (seller): see requests on my listing in arrival order; accept or decline; listing price/fee locks while a request is open. Listing state moves through the existing listing_state machine. Dashboard: staff view/act (to be clarified). Flutter: replace the mock buy request / request sent / queue / accept-decline flows with the live API. Out of scope: IGI inspection, balance payment, settlement, orders after acceptance, disputes, market maker."

## Context

- **Sources**:
  - Technical Spec Part 2 §4 (Buying — the queue): join the queue (`confirm_locked_price`, deposit agreement, `price_moved`, `already_in_queue`, `insufficient_funds`, `listing_not_purchasable`, `category_paused`, `deposit_agreement_required`), the buyer's own requests with position and countdown, leave the queue with `notify_when_free` (`not_in_queue`), the seller's view of the queue (buyer display refs only). Part 2 §5 (Acceptance): accept the head only (`not_queue_head`, `queue_empty`), branch chosen from the listing's options (`branch_not_in_options`), the order created with `order_ref` and the reach-branch deadline in working hours, every other active request released and refunded in the same operation. Part 2 §11: the seller-reply deadline sweep and the notify-when-free job. Part 2 §12: the error codes. Part 2 §3 take-down: "From `reserved` — with the queue released and refunded — once the buy-request module exists."
  - Technical Spec Part 3 §4 (the queue's concurrency model): one per-listing lock, race-free positions from `listing_queue_seq`, one active request per buyer per listing, the price locked at join (sell-side total, `deposit.buyer_pct` of it), accept takes the head and releases the rest atomically, withdraw, the seller-reply deadline in **clock** hours. Part 3 §1: the working-hours resolver (built by spec 004). Part 3 §9.2: suspension stops new trading; promises already made are honoured.
  - Technical Spec Part 1 §2.2: buying is a trade action (verified and not suspended).
  - Schema `04_schema_market.sql` §8: `buy_request`, `one_active_request_per_buyer_listing`, `listing_queue_seq` (created by spec 010), `sync_listing_queue` / `trg_sync_queue` (to be created by this module, which must also write the listing history row for `live ↔ reserved`); §9 `order`, `assert_branch_in_options`. `01_schema_core.sql`: `buy_request_state`, `order_state`, ledger kinds `deposit_hold` / `deposit_release`, settings `deposit.buyer_pct` (20), `deadline.seller_reply_hours` (48), `deadline.reach_branch_working_hours` (12). `05_schema_security.sql`: `listing_transition` (`live → reserved`, `reserved → live`, `reserved → accepted`, `reserved → withdrawn`, `reserved → suspended_hold`), `buy_request_transition` (the five moves out of `queued`), the planned RLS owner predicates for `buy_request` and `"order"`. `03_schema_ledger.sql`: `ledger_transaction.buy_request_id` / `order_id` (FKs added by this module). `02_schema_identity.sql`: `legal_document`, `agreement_acceptance` (context `buy_request`).
  - Customer prototype: the piece page ("Send buy request", "2 buyers are already in the queue… you would be next after them", "Your request is in the queue. There is 1 buyer ahead of you", "Leave the queue and get my deposit back"); *Request sent* ("Held from your wallet", "Your place in the queue — 2nd, 1 ahead of you", "The seller replies before…", "Your price is fixed at…"); *You need a little more* (deposit needed, in your wallet, add at least…, *Add funds*); *Leave the queue?* (*Leave and notify me*); the seller's card "Decide today — A buy request at your making charge — You would receive — Reply before — Accept / Decline"; *Decline this request* ("The buyer's deposit is released and the piece goes back on the market"); the branch pick after accepting ("Your deadline is counted in working hours at the branch you pick").
  - Dashboard design: Orders page row "Waiting for the seller — 4417 → 6620, 3 in queue, first shown — held 11,640 — Overdue 4h — Chase"; the customer file's Listings table "3 in the queue — Seller takes the first in line"; Overview "Held on open orders".
- **What exists**: live listings, the listing state machine with its guard and history, `listing_queue_seq` rows created with each listing, the public market with `queue_count` (spec 010); the ledger and the money service `PostLedgerEntryAction` (holds are `available → held` on the customer's own two accounts), the customer wallet (spec 008); top-up (spec 009); the price calculator and the settings above (spec 005); the working-hours resolver (spec 004); `legal_document` / `agreement_acceptance` with the ownership declaration (spec 010); the idempotency layer, audit log, customer suspension and its listing hold (specs 006, 007, 010); the trade/verified gates (spec 002); forced RLS with the build test (spec 003); SMS + email after commit (specs 009, 010).
- **What does not exist**: no `buy_request`, no `"order"` table, no `trg_sync_queue`; no deposit terms text; no `category_control`; the Customer App's buy flow, *Request sent*, the queue note and the seller's Accept / Decline run on mocks; the Dashboard's Orders page is mock.

## Clarifications

### Session 2026-09-30

- Q: What does acceptance create, given that orders after acceptance are out of scope? → A: The **order record**, as the Technical Spec says: an `"order"` row with its `order_ref` (`DH-YYYY-NNNNNN`), the chosen branch (one of the listing's options), `reach_branch_deadline` from the spec 004 working-hours resolver (`deadline.reach_branch_working_hours` at that branch), state `awaiting_delivery`, the locked price copied from the request; the accepted deposit stays held. Nothing moves the order afterwards (no delivery sweep, seller cancel, branch change, IGI) until the orders spec.
- Q: When does a request's seller-reply clock start, and in what hours? → A: At the buyer's join, in **clock** hours: `seller_reply_deadline = requested_at + deadline.seller_reply_hours` (48), per request (Technical Spec). When it passes, the request is released as expired and refunded.
- Q: How may the seller decline? → A: The **head of the queue only**, with **no reason**; the buyer is told the seller declined. The next buyer moves up; with nobody left the piece is live again.
- Q: How is the deposit computed, and what if the balance is too low? → A: `deposit.buyer_pct` (spec 005 setting, 20) × the locked total price, rounded half-up to the piastre, read live. Too little available balance → 409 `insufficient_funds` with the deposit needed, the available balance and the shortfall; the app shows *You need a little more* → *Add funds* (spec 009). Nothing is recorded.
- Q: What happens to requests when Dahab suspends someone? → A: **Seller suspended**: each reserved listing moves to `suspended_hold` and every queued request on it is released and refunded, buyers told; reinstating returns the listing to live with an empty queue (spec 010's hold/restore). **Buyer suspended**: their queued requests stay — they may still leave, the seller may decline them or let them expire — but a suspended buyer cannot be accepted (`buyer_suspended`, 409). Accepted requests and their orders are untouched either way.
- Q: Which limits apply, and may a seller buy their own piece? → A: No caps on queue length or requests per buyer (the balance is the natural limit), plus a rate limiter on sending; requesting one's own listing → 409 `cannot_buy_own_listing` (new code).
- Q: Which notifications ship? → A: SMS, plus email when they have one, after commit. Seller: a new request on their piece (with the reply deadline). Buyer: accepted, declined, not chosen, expired, and released because the piece was withdrawn or its seller suspended. None for a customer's own action. Plus notify-when-free.
- Q: What does the Dashboard get? → A: **Read-only queue views** behind the existing listing permissions: the queue count and the queue (buyer reference, position, locked price, deposit, reply deadline) and, once accepted, the order reference, branch and deadline; the take-down from reserved releases the queue. No new page, no *Chase*; the Orders page stays mock until the orders spec.
- Q: The customer file has no listings panel (spec 010 follow-up) — where does the staff queue show? → A: **In the listing review panel only** (the Listings page gains Reserved and Accepted chips to reach those listings). No customer-file listings panel in this feature.
- Q: How close must the confirmed price be to the fresh price? → A: A new operations setting `buyrequest.price_tolerance_pct` (default 0.5, changed from *Deadlines and deposits*). Within it, the buyer locks the **fresh server price**; outside it, 409 `price_moved` with the new price and deposit.
- Q: Does the buyer accept deposit terms? → A: Yes. Seed `legal_document` `deposit_agreement` version 1 (English + Arabic, drafted as agreed compensation, marked for the legal clinic), readable on the public reference surface; a request requires the current version's id (422 `deposit_agreement_required` otherwise) and records an `agreement_acceptance` with context `buy_request`, in the same operation.
- Q: May a listing with buyers in line be taken down, and by whom? → A: **Staff and seller**: staff take-down (`listing.takedown`, reason, audited) and the seller's own withdrawal work from reserved; every queued request is released as declined with its refund and the buyers are told the piece was withdrawn; the listing moves reserved → withdrawn (final).
- Q: What do buyer and seller see after acceptance? → A: No order endpoints. The buyer's accepted request and the seller's listing show the `order_ref`, the branch and the reach-branch deadline; order tracking after that stays mock in the app.
- Q: (analysis H3) With no order life yet, how does money held on an accepted order ever come back? → A: A **staff cancel-acceptance** action: staff with a new permission `order.cancel` ("Cancel an order", Part 1 §4.1: CEO, COO, Operations) cancel an order in `awaiting_delivery` with a required reason. One operation: the order moves to a new final state `cancelled_staff`; the buyer's deposit is refunded in full; the listing moves `accepted → live` (back on the market with an empty queue) or `accepted → withdrawn` (final), as the staff member chooses; audited; buyer and seller told. It does not count as a seller cancellation.
- Q: (analysis M1) Which new conflict codes does this feature add? → A: `cannot_buy_own_listing`, `buyer_suspended`, `price_unavailable` (gold piece with no usable price), `branch_hours_unavailable` (the chosen branch's hours cannot produce a reach-branch deadline) and `order_not_cancellable` (staff cancel on an order that is not awaiting delivery).
- Q: (analysis M3) Does the market show the deposit? → A: Yes — the piece detail returns the indicative deposit for the current price, so the app never computes it.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A buyer sends a buy request and a deposit is held (Priority: P1)

A verified buyer opens a live piece on the market. They see the price (which follows the gold rate for gold), how many buyers are already in line and the deposit they need. They tap *Send buy request*, agree to the deposit terms, and the price is locked for them. The deposit is set aside from their wallet — still their money, but not spendable — and they join the back of the queue. They see their place in line, the locked price, the deposit held and when the seller must reply.

**Why this priority**: this is the first step of every purchase; without it nothing is bought.

**Independent Test**: A verified buyer with 20,000 EGP available sends a request on a live 21K gold piece priced 58,200 EGP; their wallet shows 11,640 held and 8,360 available; the request shows position 1 and a reply deadline 48 hours later; the listing shows as reserved with a queue of 1 on the market. A second buyer joins and gets position 2.

**Acceptance Scenarios**:

1. **Given** a verified, non-suspended buyer with enough available balance and a live or reserved listing that is not theirs, **When** they send a buy request with the price they were shown and the current deposit terms accepted, **Then** a request is recorded at the next position in that listing's line with the locked unit rate, the locked total price, the deposit and the seller-reply deadline; one balanced ledger entry moves the deposit from their available to their held balance; the acceptance of the terms is recorded; all in one all-or-nothing operation.
2. **Given** the first request on a live listing, **Then** the listing becomes reserved with a queue count of 1 and a listing history row; it stays on the market with the queue count.
3. **Given** the fresh price differs from the confirmed one by more than the tolerance, **Then** the request is refused as `price_moved` with the new price and deposit, and nothing is held; within the tolerance, the fresh price is the one locked.
4. **Given** the buyer's available balance is below the deposit, **Then** the request is refused as `insufficient_funds` with the deposit needed, the available balance and the shortfall, and nothing is recorded.
5. **Given** the buyer already has an active request on this listing, **Then** it is refused as `already_in_queue`.
6. **Given** a listing that is not live or reserved, **Then** it is refused as `listing_not_purchasable`; one that is not publicly visible at all answers not found.
7. **Given** the buyer is the listing's seller, **Then** it is refused as `cannot_buy_own_listing`.
8. **Given** a customer awaiting verification or rejected, **Then** they are refused as `verification_required`; a suspended one as `account_suspended`.
9. **Given** the deposit terms were not accepted, or the accepted id is not the current version, **Then** it is refused as `deposit_agreement_required`.
10. **Given** a gold listing and no usable gold price, **Then** it is refused as a conflict (`price_unavailable`) and nothing is held.
11. **Given** the same request is sent twice with the same idempotency key, **Then** exactly one request and one hold exist and both responses are the same.
12. **Given** many buyers send requests on the same listing at the same moment, **Then** each gets a distinct position in arrival order with no gap or reuse, and each has exactly one hold.

---

### User Story 2 - The buyer follows their requests and can leave the queue (Priority: P1)

The buyer opens their requests and sees each one: the piece, the state (in line, accepted, declined, not chosen, expired, left), their place in line and how many are ahead, the locked price, the deposit held and the reply deadline; once accepted, the order reference, the branch and the deadline to reach it. While still in line they can leave; the deposit comes back to their wallet at once. They can ask to be told if the piece later comes back to the market with nobody in line.

**Why this priority**: the buyer's money is held; they must always see where it is and be able to take it back while waiting.

**Independent Test**: A buyer in position 2 leaves the queue; their held balance returns to available in full; the listing stays reserved while one request remains. When the last buyer leaves, the listing is live again, and a buyer who left with "tell me" is told.

**Acceptance Scenarios**:

1. **Given** a buyer with requests, **When** they list them, **Then** they see only their own, newest first, each with the listing's piece summary (never the seller), state, place in line (1 = next to be answered) and number ahead while queued, locked total price, deposit, requested time, reply deadline, the time it ended and — when accepted — the order reference, branch and reach-branch deadline.
2. **Given** a queued request, **When** the buyer leaves the queue, **Then** it becomes withdrawn by the buyer, one balanced ledger entry returns the deposit from held to available, and the listing's queue count drops; if no request remains the listing becomes live again with a history row; all in one operation.
3. **Given** a request that is not queued, **When** the buyer tries to leave, **Then** it is refused as `not_in_queue` and nothing changes.
4. **Given** the buyer asked to be told when leaving, **When** the listing later returns to live with no active request, **Then** they are told once.
5. **Given** another customer's request id, **Then** the answer is not found — enforced by the database, not only by the application.
6. **Given** a buyer who left, **When** they send a new request on the same listing, **Then** they join at the back with a new position and a newly locked price.
7. **Given** a suspended buyer, **Then** they can still read and leave their queued requests.

---

### User Story 3 - The seller sees the queue and answers the first in line (Priority: P1)

The seller sees that their piece has buy requests. They see the line in arrival order — each buyer only as a display number — with the price each one locked, what they would receive, and when they must reply. They accept the first in line, choosing the branch they will bring the piece to from the ones they named; an order is opened with its reference and the deadline to reach the branch, and the other buyers are released and refunded at once. Or they decline the first in line; that buyer is refunded and the next one moves up.

**Why this priority**: the seller's answer is what turns a request into a sale.

**Independent Test**: With three queued requests, the seller declines the first (refunded), then accepts the new first choosing Nasr City on a Thursday at 16:00: that request is accepted with its deposit still held, an order `DH-2026-…` exists in awaiting delivery with a reach-branch deadline on Sunday per the branch's hours, the third buyer is released and refunded, and the listing is accepted and off the market.

**Acceptance Scenarios**:

1. **Given** a seller with a reserved listing, **When** they open its requests, **Then** they see the queued requests in arrival order with the buyer's display number only (never name, phone or email), position, locked total price, requested time and reply deadline, with — once for the listing — what the seller would receive if it sold now (indicative).
2. **Given** the head of the queue, **When** the seller accepts it and picks one of the listing's enabled branch options, **Then** in one operation: an order is created (reference, branch, locked price, buyer, seller, awaiting delivery, reach-branch deadline in working hours at that branch); the head becomes accepted and keeps its deposit held; every other queued request becomes released-not-chosen with its own refund; the listing moves reserved → accepted with a history row and leaves the market.
3. **Given** a request that is not the head, **When** the seller accepts it, **Then** it is refused as `not_queue_head`; with no queued request, `queue_empty`.
4. **Given** a branch that is not one of the listing's options, or is no longer enabled, **Then** acceptance is refused as `branch_not_in_options`.
5. **Given** the head's buyer is suspended, **Then** acceptance is refused as `buyer_suspended`; the seller may decline them.
6. **Given** the head of the queue, **When** the seller declines it (no reason), **Then** it becomes released-declined with its refund and the next request becomes the head; if none remains the listing is live again.
7. **Given** a request that is not the head, **When** the seller declines it, **Then** it is refused as `not_queue_head`.
8. **Given** another customer's listing, **Then** its queue cannot be read or answered (not found).
9. **Given** the buyer leaves, or the sweep expires the head, at the same moment the seller accepts, **Then** exactly one wins and a deposit is never both refunded and kept.
10. **Given** the same accept or decline is sent twice with the same idempotency key, **Then** it takes effect once.
11. **Given** an accepted listing, **Then** the seller's listing shows the order reference, branch and reach-branch deadline.

---

### User Story 4 - Unanswered requests expire on their own (Priority: P2)

If the seller does not reply in time, a waiting buyer is not stuck: once their reply deadline passes, their request is released and the deposit returned, without anyone having to act.

**Why this priority**: protects buyers' money from an unresponsive seller.

**Independent Test**: With the reply window at 1 hour, a request is placed and the clock moves 61 minutes; the sweep releases it as expired, the deposit is back in available, the buyer is told and the listing is live again.

**Acceptance Scenarios**:

1. **Given** a queued request past its reply deadline, **When** the sweep runs, **Then** it becomes released-expired with its refund, recorded against the system actor, one request per operation; the others keep their positions.
2. **Given** the sweep runs twice, or the seller answers while the sweep runs, **Then** each request ends exactly once and is refunded exactly once.

---

### User Story 5 - Take-down, suspension and staff cancellation never strand a deposit (Priority: P2)

When a reserved piece is taken down — by the seller or by Dahab — or its seller is suspended, every buyer in line gets their deposit back at once. When an accepted sale cannot go ahead, Dahab cancels the acceptance and the buyer gets their deposit back.

**Why this priority**: the queue must never leave money held against a listing that can no longer be sold.

**Independent Test**: A reserved listing with two requests is taken down by staff with a reason: both requests are released and refunded, the buyers are told, the listing is withdrawn, the audit row names the staff member. A seller with a reserved listing is suspended: the listing is on hold, its queue released and refunded; reinstating puts it back live with an empty queue.

**Acceptance Scenarios**:

1. **Given** a reserved listing, **When** staff take it down with a reason, or the seller withdraws it, **Then** every queued request is released as declined with its refund and the listing becomes withdrawn, in one operation; staff actions are audited.
2. **Given** a seller with reserved listings, **When** they are suspended, **Then** each reserved listing moves to suspended hold and its queued requests are released with their refunds, in the same operation as the suspension; **When** reinstated, the listing returns to live with an empty queue.
3. **Given** a buyer is suspended, **Then** their queued requests stay queued and their accepted requests are untouched.
4. **Given** an accepted listing whose order is awaiting delivery, **When** a staff member with `order.cancel` cancels the acceptance with a reason and chooses to relist or withdraw, **Then** in one operation the order becomes `cancelled_staff`, the buyer's deposit is refunded in full, the listing becomes live (empty queue) or withdrawn, an audit row names the staff member with the reason, and buyer and seller are told. Without the permission it is refused and audited; an order in any other state is refused as `order_not_cancellable`; the same key twice takes effect once.

---

### User Story 6 - The Customer App and the Dashboard use the real thing (Priority: P2)

The Customer App's *Send buy request*, *Request sent*, *You need a little more*, the queue note on the piece page, *Leave the queue*, the buyer's requests and the seller's *Accept* (with the branch pick) / *Decline* run on the API above. The Dashboard shows staff the queue of a listing.

**Why this priority**: people reach the feature only through the apps.

**Independent Test**: In the Customer App, buyer A sends a request and sees *Request sent* with position 1; buyer B sees "1 buyer already in the queue"; the seller accepts A at Nasr City; B's wallet shows the deposit back. In the Dashboard, the listing's panel shows the queue with buyer references.

**Acceptance Scenarios**:

1. **Given** the Customer App, **When** a buyer without enough balance taps *Send buy request*, **Then** *You need a little more* shows the deposit, the available balance and the shortfall from the API, with *Add funds*.
2. **Given** the piece page, **Then** the queue count and, for a buyer already in line, their place come from the API.
3. **Given** the seller's piece with requests, **Then** Accept (with the branch pick) and Decline call the API with an idempotency key and show the result.
4. **Given** the Dashboard, **When** a staff member with a listing permission opens a reserved or accepted listing from the Listings page, **Then** they see the queue count and the queue, read-only; take-down confirms that the queue will be released, in the shared modal component.

### Edge Cases

- **Gold price moves between the piece page and the tap**: within the tolerance the fresh price is locked; beyond it `price_moved` with the new figures; the app shows them and asks again.
- **Deposit rounding**: computed exactly, half-up to the piastre; the buyer never holds a fraction of a piastre.
- **Buyer's balance changes between check and hold** (concurrent top-up credit or another hold): the money service locks the accounts; the result is a hold or `insufficient_funds`, never a negative balance.
- **Seller withdraws / staff take down while a buyer joins**: exactly one wins; a request never ends up queued on a withdrawn listing.
- **Seller accepts while the sweep expires the head, or the buyer leaves**: exactly one wins.
- **Head's buyer suspended**: acceptance refused as `buyer_suspended`; the seller declines them or waits for expiry.
- **Seller suspended with a reserved listing**: queue released and refunded, listing on hold; reinstated → live, empty queue.
- **Accepted buyer**: their deposit stays held against the order; the request is final.
- **Branch disabled after listing**: acceptance at that branch is refused as `branch_not_in_options`; another named, enabled branch can be chosen; if none is enabled the seller cannot accept (decline or expiry remain).
- **Branch hours missing or all closed** (the resolver cannot find working time): acceptance is refused as a conflict and nothing changes.
- **Karat turned off after listing**: the listing carries on (spec 010) and remains purchasable while it can be priced.
- **Deposit terms get a new version between opening the page and sending**: `deposit_agreement_required`; the app shows the new text.
- **Idempotency key reused with a different body**: refused by the shared layer.
- **The same buyer on many listings**: allowed, each with its own deposit, limited only by their balance.

## Requirements *(mandatory)*

### Functional Requirements

**Sending a request (buyer)**

- **FR-001**: A customer who passes the trade gate MUST be able to send a buy request on a listing that is live or reserved and not their own (`cannot_buy_own_listing`, 409), carrying the total price they were shown and the id of the current deposit terms they accepted.
- **FR-002**: The request MUST lock, for this buyer, the unit rate and total price computed by the single price calculator at that instant (gold: the sell-side total on the stated weight; stones: the asking price). If the confirmed price differs from the fresh one by more than `buyrequest.price_tolerance_pct` (new operations setting, default 0.5) of the fresh price, it MUST be refused as `price_moved` (409, with the fresh price and deposit); otherwise the fresh price is locked. A gold listing with no usable price MUST be refused as `price_unavailable` (409).
- **FR-002a**: The market's piece detail MUST return the indicative deposit (`deposit.buyer_pct` of the current price, half-up to the piastre; null when there is no price), so the Customer App never computes it.
- **FR-003**: The deposit MUST be `deposit.buyer_pct` (read live) of the locked total price, rounded half-up to the piastre, held by one balanced ledger entry (`deposit_hold`: buyer available −deposit, buyer held +deposit) through the money service, tied to the request and written in the same all-or-nothing operation. `insufficient_funds` (409) when the available balance cannot cover it, with `deposit_amount`, `available` and `shortfall`.
- **FR-004**: A request MUST take the next position in its listing's line from the listing's counter under a per-listing lock: positions strictly increase in arrival order, are never reused, and concurrent requests never share one.
- **FR-005**: A buyer MUST have at most one active (queued or accepted) request per listing (`already_in_queue`, 409), enforced by the database. A buyer who left or was released may join again, at the back. There is no other cap on queue length or requests per buyer; sending is rate limited.
- **FR-006**: The request MUST record `seller_reply_deadline = requested_at + deadline.seller_reply_hours` (clock hours, read live).
- **FR-007**: The first queued request MUST move the listing live → reserved, and the last one to end without an acceptance MUST move it reserved → live, along the allowed listing moves with a listing history row; the listing's queue count MUST always equal its number of queued requests.
- **FR-008**: The deposit terms MUST be a versioned legal text (`deposit_agreement`, v1 seeded in English and Arabic) readable on the public reference surface; a request whose accepted id is not the current version MUST be refused as `deposit_agreement_required` (422), and each acceptance MUST be recorded (who, when, which version, context `buy_request`) in the same operation.

**The buyer's requests**

- **FR-009**: A buyer MUST be able to list their own requests, newest first, a page at a time, optionally by state, and open one; each carries the listing's piece summary (never the seller), state, position and number ahead while queued, locked unit rate and total, deposit, requested time, reply deadline, resolved time, the notify-when-free choice and — when accepted — the order reference, branch and reach-branch deadline.
- **FR-010**: A buyer (suspended or not) MUST be able to leave a queued request (`withdrawn_by_buyer`), with a balanced `deposit_release` refund (held −deposit, available +deposit) in the same operation, optionally asking to be told when the piece is free again; `not_in_queue` (409) otherwise.
- **FR-011**: When a listing returns to live with no active request, every buyer who left it with notify-when-free MUST be told once (a scheduled job or an after-commit event; each buyer at most once per leave).
- **FR-012**: Row-level security MUST isolate buy requests by buyer and orders by buyer or seller — a buyer's own reads of their requests run under that isolation, never under a wider scope; the seller reads the requests on their own listing only through the seller's queue, which exposes the buyer's display reference and nothing else about them.

**The seller's answer**

- **FR-013**: A seller MUST be able to read the queued requests on their own listing in arrival order: position, buyer display reference, locked total price, requested time, reply deadline; plus, once for the listing, what the seller would receive if it sold now (the spec 010 indicative figure from the spec 005 calculator — the seller's side is not locked per request).
- **FR-014**: A seller who passes the trade gate MUST be able to accept the head of the queue only (`not_queue_head`, `queue_empty`, 409), naming a branch among the listing's options that is still enabled (`branch_not_in_options`, 409), refused as `buyer_suspended` (409) when the head's buyer is suspended. In one operation: create the order (`order_ref` `DH-YYYY-NNNNNN`, listing, request, buyer, seller, branch, `accepted_by` = seller, `accepted_at`, locked total price, state `awaiting_delivery`, `reach_branch_deadline` from the working-hours resolver with `deadline.reach_branch_working_hours` at that branch); the head becomes accepted with its deposit still held; every other queued request becomes released-not-chosen with its own refund; the listing moves reserved → accepted with a history row. If the resolver cannot produce a deadline, nothing changes and it is refused as `branch_hours_unavailable` (409).
- **FR-015**: A seller who passes the trade gate MUST be able to decline the head of the queue only, with no reason: it becomes released-declined with its refund; the next request becomes the head; the listing returns to live if none remains.
- **FR-016**: Only the listing's seller may read its queue, accept or decline; for anyone else the listing is not found.
- **FR-017**: The accepted listing, in the seller's own listings, MUST show the order reference, branch and reach-branch deadline. No order endpoints are added; the order's life after acceptance is the orders spec.

**Deadlines, take-down, suspension**

- **FR-018**: A scheduled job MUST release every queued request past its reply deadline as `released_expired` with its refund, one request per operation, as the system actor, safe to run twice and concurrently with seller actions.
- **FR-019**: Staff take-down (`listing.takedown`, reason, audited) and the seller's own withdrawal MUST also work from reserved: every queued request becomes released-declined with its refund, and the listing moves reserved → withdrawn (final), in one operation.
- **FR-020a**: Staff holding `order.cancel` (new permission, seeded to CEO, COO and Operations, editable from the Dashboard) MUST be able to cancel an order in `awaiting_delivery` with a required reason (10–1000 characters) and a choice `relist` (true: listing `accepted → live`, empty queue; false: `accepted → withdrawn`, final). In one operation: the order moves to `cancelled_staff` (new final order state, `awaiting_delivery → cancelled_staff`); the accepted request's deposit is released in full to the buyer (`deposit_release`, actor the staff member, tied to the request and the order); the listing moves with a history row carrying the reason; an audit row (`order.cancelled`) names the staff member with the reason. Any other order state → `order_not_cancellable` (409). It is not a seller cancellation and does not count toward seller suspension. Idempotent.
- **FR-020**: Suspending a seller MUST, in the same operation as the suspension, move each reserved listing of theirs to suspended hold and release every queued request on it (released-declined, refunded); reinstating returns each to live with an empty queue. Suspending a buyer MUST leave their requests as they are; a suspended buyer cannot be accepted (FR-014).

**Every request state change and every movement of money**

- **FR-021**: A request's state MUST change only along the allowed moves (`buy_request_transition`), enforced by the database; the API answers a conflict otherwise. `accepted` and every released / withdrawn state are final (a cancelled order leaves its request `accepted`; the order carries the cancellation).
- **FR-022**: Every hold and every release MUST be one balanced ledger entry through the money service, tied to the request, written in the same operation as the state change. For every request: holds − releases = the deposit while queued, or accepted with its order not cancelled; and 0 once released, withdrawn, or its order cancelled.
- **FR-023**: Send, leave, accept, decline, the seller's withdrawal and the staff cancellation MUST each require an idempotency key. Staff actions are audited with the staff member as actor; scheduled releases carry the system actor; every ledger entry names its actor.

**Notifications**

- **FR-024**: After commit, by SMS plus email when the customer has one, in their language: the seller is told of a new request (with the reply deadline); the buyer is told when their request is accepted (with the order reference, branch and deadline), declined, not chosen, expired, or released because the piece was withdrawn or its seller suspended; buyer and seller are both told when staff cancel an acceptance (with the reason); plus notify-when-free (FR-011). No message for a customer's own action. A failed message never undoes the change.

**Staff (Dashboard)**

- **FR-025**: Staff holding any listing permission MUST see, read-only, in the listing review panel (reached from the Listings page, which gains Reserved and Accepted state chips), a listing's queue count and its queued requests (buyer reference, position, locked total price, deposit, reply deadline) and, when accepted, the order reference, branch and deadline. There is no new page and no customer-file listings panel; the staff actions are take-down (existing permission) and cancel acceptance (FR-020a, on the order box, for holders of `order.cancel`).

**Apps**

- **FR-026**: The Customer App MUST run *Send buy request* (with the deposit terms), *Request sent*, *You need a little more*, the piece page's queue note and *Leave the queue*, the buyer's request list and the seller's queue with *Accept* (branch pick) / *Decline* on the API; anything after acceptance (order tracking, balance, collection) stays on its mock and is listed in the report.
- **FR-027**: The Dashboard MUST show FR-025, the take-down-from-reserved confirmation and the cancel-acceptance form (reason; relist or withdraw) using the shared modal component.

**Quality and contract**

- **FR-028**: Send, leave, accept, decline, expiry, take-down and withdrawal from reserved, the suspension effect and the staff cancellation MUST be covered by feature tests whose effects are asserted through the HTTP boundary (the expiry job is run by its command and its results read back through the API) asserting on the rows, the ledger entries and balances, the listing state and history, the order, idempotent replay and every refusal; concurrency tests MUST prove positions, single holds and single outcomes under simultaneous actions; a reconciliation test MUST prove FR-022 over every request.
- **FR-029**: Every new endpoint MUST carry OpenAPI annotations and a Postman request; Technical Spec Part 1 §4.1 (`order.cancel`), §5.1, Part 2 §3–§5, §11, §12, Part 3 §4, §9.2, §12, the market / core / security / ledger / identity schema docs and `docs/platform/api-contract.md` MUST be updated in the same change.

### Key Entities

- **Buy request**: one buyer's place in one listing's line — position, state, locked unit rate and total price, deposit, the ledger entry that held it, requested time, reply deadline, resolved time, notify-when-free.
- **Order**: created at acceptance — reference, listing, the accepted request, buyer, seller, chosen branch, accepted by / at, locked total price, reach-branch deadline, state (`awaiting_delivery`, or `cancelled_staff` after a staff cancellation, in this feature).
- **Queue counter**: one per listing (exists), gives the next position.
- **Deposit hold / release**: balanced ledger entries tied to the request (`deposit_hold`, `deposit_release`).
- **Allowed request moves** (`buy_request_transition`): the table of legal request state changes.
- **Deposit terms**: legal document `deposit_agreement` (versioned) and the record of each acceptance.
- **Listing** (exists): moves live ↔ reserved → accepted / withdrawn / suspended hold with history rows; its queue count mirrors the queued requests.
- **Setting** `buyrequest.price_tolerance_pct` (new, operations group).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A buyer with enough balance goes from the piece page to *Request sent* in under 30 seconds.
- **SC-002**: 0 requests exist without exactly one hold, and 0 ended requests keep a held deposit (reconciliation over every request).
- **SC-003**: Under 50 simultaneous requests on one listing, positions are 1..50 with no gap or duplicate and every hold matches its request.
- **SC-004**: A released, declined, expired or left deposit is spendable again within 5 seconds of the event; an expired request is released within one scheduler minute of its deadline.
- **SC-005**: 0 responses to a seller, to another buyer or on the market contain a buyer's name, phone or email.
- **SC-007**: 0 cancelled orders keep a held deposit.
- **SC-006**: 0 unverified or suspended customers can send a request; 0 sellers can accept or decline anyone but the head; 0 suspended buyers are accepted.

## Assumptions

- The customer gate for send, accept, decline and the seller's withdrawal is **trade**; reading one's own requests and the seller's queue, and leaving a queue, are **verified** (a suspended customer may read and leave).
- `category_control` does not exist: `category_paused` / `category_stopped` are never raised by this feature.
- Market-maker purchases, IGI, balance payment, settlement, the order's life after acceptance (delivery sweep, seller cancel, branch change, extensions), customer order endpoints and disputes are out of scope.
- The locked total price is the gold price formula frozen at join; the final payable trues up at settlement (Part 3 §4.3) — not in this feature.
- The seller's making charge cannot change while a request is open: listing edits are limited to draft and changes requested (spec 010), and changing the fee on a live listing is not built.
- Buyers are identified to sellers and staff by their existing customer display reference.
- New conflict codes (accepted by the product owner): `cannot_buy_own_listing`, `buyer_suspended`, `price_unavailable`, `branch_hours_unavailable`, `order_not_cancellable`; plus `illegal_buy_request_transition` for the database guard.
- New, used only by the staff cancellation: the final order state `cancelled_staff` and the listing moves `accepted → live` and `accepted → withdrawn`.
- Wording: the buyer *leaves the queue* (the endpoint keeps the Technical Spec's `/withdraw`); a listing is *withdrawn* by its seller or *taken down* by staff.
- Released-by-take-down and released-by-seller-suspension use the existing `released_declined` state (no new state); the notification text says why.
- The deposit terms text is a draft pending the legal clinic (open-questions §1), like the forfeiture wording.
