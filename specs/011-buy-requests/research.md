# Research: Buy requests (spec 011)

Design decisions for the plan. Each: **Decision / Rationale / Alternatives**. Product decisions are in the spec's
Clarifications; these are the engineering choices under them.

## R1 — Where the endpoints live

**Decision**: follow the surfaces spec 010 established, not the Technical Spec's bare paths.

| Technical Spec | Built as |
|---|---|
| `POST /listings/{id}/buy-requests` | `POST /customer/me/buy-requests` (body carries `listing_id`) |
| `GET /me/buy-requests`, `/{id}` | `GET /customer/me/buy-requests` (filters `state`, `listing_id`), `GET /customer/me/buy-requests/{id}` |
| `POST /me/buy-requests/{id}/withdraw` | `POST /customer/me/buy-requests/{id}/withdraw` |
| `GET /listings/{id}/queue` | `GET /customer/me/listings/{listing}/buy-requests` |
| `POST /listings/{id}/accept` | `POST /customer/me/listings/{listing}/accept` (`buy_request_id`, `branch_id`) |
| — (decline, clarification) | `POST /customer/me/listings/{listing}/decline` (`buy_request_id`) |
| `POST /admin/listings/{id}/takedown` | unchanged `POST /dashboard/listings/{listing}/takedown`, now also from `reserved` |

**Rationale**: every customer write is under `/customer/me/*` (the `me` owner is the caller); the buyer's request is
theirs even though the listing is not. `listing_id` in the body keeps `/customer/me/listings/{id}` meaning "my
listing". **Alternatives**: `/customer/listings/{id}/buy-requests` — a second, non-`me` customer prefix for one route.

## R2 — Row-level security for a queue that spans two customers: a `queue` scope

**Problem**: a join is the buyer's action but must lock and move the **seller's** listing and its `listing_queue_seq`
row, and the trigger that recounts the queue must see **other buyers'** requests. An accept is the seller's action
but moves **other buyers'** requests and releases their deposits. Under the existing policies the buyer sees only
their own requests and no listing that is not theirs (the market scope is read-only and has no customer), and the
seller sees no request.

**Decision**: a new non-elevated scope **`queue`**, pushed only by the buy-request Actions through
`DatabaseActor::queue(Closure)` (keeps the current customer id, like `ledger`). Policies added by the migration:

- `buy_request`: `buy_request_isolation` (elevated OR `buyer_id = me`); `buy_request_queue_read` FOR SELECT
  `USING (scope = 'queue')` — the queue service must count a listing's line and a buyer's place in it;
  `buy_request_queue_move` FOR UPDATE `USING/WITH CHECK (scope = 'queue' AND (buyer_id = me OR EXISTS (SELECT 1 FROM
  listing l WHERE l.listing_id = buy_request.listing_id AND l.seller_id = me)))` — only the buyer or the listing's
  seller moves a request in this scope (staff and the system act elevated). Inserts pass the owner policy
  (`buyer_id = me`). No recursion: `listing` policies never reference `buy_request`.
- `listing`: `listing_queue_read` FOR SELECT `USING (scope = 'queue' AND listed_at IS NOT NULL)` (a buyer's summary of
  a piece they asked for, in any later state); `listing_queue_move` FOR UPDATE `USING (scope = 'queue' AND state IN
  ('live','reserved')) WITH CHECK (scope = 'queue' AND state IN ('live','reserved'))` — the buyer's own flips only.
  The seller's own moves pass the existing owner policy.
- `listing_queue_seq`: FOR SELECT and FOR UPDATE `USING (scope = 'queue')` (no insert or delete: spec 010 creates the row).
- `listing_state_change`: INSERT in `queue` scope only with `actor_customer_id = me` and no staff actor; SELECT in
  `queue` scope (the deferred history check reads it at commit).
- `listing_branch_option`, `listing_media` (public only): SELECT in `queue` scope (the buyer's piece summary).
- `customer`: SELECT `USING (scope = 'queue' AND EXISTS (buy_request with buyer_id = customer_id))` — the buyer's
  `display_ref` and suspension for the seller's queue and `buyer_suspended`.
- `"order"`: `order_isolation` (elevated OR seller or buyer = me), no queue policy needed (the seller inserts).
- `ledger_*`: unchanged — the money service pushes `ledger` itself.

Column privacy is the Resources' job (buyer display ref only), proven by a leak test as for the market (spec 010 R2).

**Where the queue scope is NOT used (C1)**: a buyer's own reads (`GET /customer/me/buy-requests*`) load the request
rows in the ordinary **customer** scope, so `buy_request_isolation` alone decides which rows exist — no application
`WHERE buyer_id` is relied on. Only then, inside `DatabaseActor::queue()`, the Action loads the piece summaries for
those listing ids and the "ahead" counts for those requests (aggregates, never rows of other buyers). The seller's
queue first proves ownership by loading the listing in the customer scope (owner policy), then reads that listing's
queued requests in the queue scope.

**Commit happens inside the scope (H1)**: every queue operation is written
`DatabaseActor::queue(fn () => DB::transaction(fn () => …))` — the scope is the **outer** frame, so the deferred
constraint triggers run at commit with the `queue` scope still set. Belt and braces, every deferred trigger this
feature adds (`trg_buy_request_money`, `trg_listing_queue_consistent`) switches the scope itself for its reads, as
spec 008's `assert_txn_balanced` does (`set_config('app.rls_scope', 'queue' | 'ledger', true)` and restore), and the
spec 010 `listing_change_recorded()` gets the same treatment (switch to `queue`, restore) so a listing moved by one
customer on another's listing can never fail the history check for a visibility reason. `QueueScopeTest` includes a
case that commits a join with the outer frame set to the plain customer scope and proves it still commits.

**Recorded deviation (Constitution II)**: the `queue` scope lets a customer's request read rows of other customers
(requests on a listing for counting; buyers' `display_ref` / suspension flag for the seller; the seller's listing for
the buyer) without an elevation. It is narrower than any elevation, never used for a customer's own reads, pushed only
by the buy-request Actions and the two listing paths that release a queue (checked by a build test), and column
privacy is proven by `BuyRequestLeakTest`. The PR body carries the note in plan.md.

**Rationale**: the narrowest thing that works. `ledger` is the precedent: a scope that only the money service
pushes, which sees what it must and nothing more. **Alternatives**: elevating to `system` for every queue action —
exposes every customer table and loses the customer actor; `SECURITY DEFINER` functions — `FORCE RLS` still applies to
the owner, and the logic would move into SQL; denormalising the seller onto `buy_request` — the policies would recurse
between `listing` and `buy_request` (Postgres refuses recursive policies) and the schema has no such column.

**Guard**: `ElevationTest`-style test — `queue` is pushed only from `app/Actions/BuyRequests/*` and
`app/Actions/Listings/WithdrawListingAction.php` (the seller's withdrawal from reserved); staff and system paths are
elevated and never push it.

## R3 — Who moves the listing between live and reserved

**Decision**: `trg_sync_queue` keeps **only `active_queue_count`** in step. The state moves `live → reserved`,
`reserved → live` and every other move go through `MovesListing` (history row, named actor), called by the Action
right after the request change. A deferred constraint trigger `trg_listing_queue_consistent` refuses a commit where a
`live` listing has queued requests or a `reserved` one has none; it reads under a scope it sets itself (R2, H1).

**Rationale**: spec 010 made `MovesListing` the only way a listing changes state, with the history row and actor
(Principle I). The schema's trigger flips the state itself and leaves the history row to the caller ("must also write
the listing_state_change row") — two writers of one state. The deferred check keeps the schema's intent (count and
state never disagree) as a backstop. **Docs change**: `04_schema_market.sql` §8 function body and comment updated in
the same change (Constitution III). **Alternatives**: the schema trigger as written — the Action would have to detect
the trigger's flip to write the history row, and the actor would be implicit.

## R4 — Lock order (the queue lock, no deadlocks)

**Decision**: every queue operation, in one transaction, locks in this order:
1. the listing row `FOR UPDATE` (the per-listing queue lock, Part 3 §4.1);
2. `listing_queue_seq` `FOR UPDATE` (join only);
3. the affected `buy_request` rows `FOR UPDATE`, ordered by `queue_position`;
4. customer accounts — taken by `PostLedgerEntryAction` in `account_id` order, per entry.

Suspension locks the customer row, then the listings (spec 007/010 order), then as above. The expiry sweep takes one
request per transaction, listing first. Two listings are never locked by one queue operation except suspension, which
locks a seller's listings in `listing_id` order.

**Rationale**: a single first lock per listing serialises join / leave / accept / decline / expire / take-down on that
listing, so "exactly one wins" follows. Accounts come last and in a fixed order (spec 008 R5).

## R5 — Price lock and tolerance

**Decision**: `ListingPricer::quote()` (spec 010, over the spec 005 calculator) gives the fresh `currentPrice`. The
request is refused `price_moved` when `|confirmed − fresh| > fresh × buyrequest.price_tolerance_pct / 100`; otherwise
the **fresh** figure is locked. `locked_unit_rate` = the karat's sell-side per-gram rate (`buyersPay`) for gold and
gold-with-diamond; **NULL for pure diamond** (schema change: the column becomes nullable; `buy_request` has no category column, so the
Action sets it for every listing with a karat and the column comment says so). No usable price for a gold listing → `price_unavailable` (409).

**Rationale**: the buyer never locks more than the tolerance away from what they saw, and never a stale figure.
Diamonds have no rate; storing 0 would read as a price. **Alternatives**: store the asking price in
`locked_unit_rate` for stones — misleading in reports.

## R6 — Deposit arithmetic

**Decision**: `deposit = round_half_up(locked_total_price × deposit.buyer_pct / 100, 2)` with bcmath (`Money`), stored
`NUMERIC(18,4)` with two zero decimals. `insufficient_funds` details: `deposit_amount`, `available`, `shortfall`
(`deposit − available`). The pre-check reads the available balance for the details; the money service's lock and
check is the authority.

## R7 — Deposit terms

**Decision**: the migration seeds `legal_document ('deposit_agreement', 1, EN, AR, …, system actor)`, readable through
the existing `GET /reference/legal-documents/{code}`. The request body carries `deposit_legal_doc_id`; it must equal
`LegalDocument::current('deposit_agreement')->id` (else 422 `deposit_agreement_required`). One `agreement_acceptance`
row (context `buy_request`) per request. `agreement_acceptance` has no subject column, so the request points at it:
new column `buy_request.deposit_acceptance_id UUID NOT NULL REFERENCES agreement_acceptance` (schema addition; spec
010 used a link table, `listing_ownership_declaration`, for the same need — a column is enough here because there is
exactly one acceptance per request).

## R8 — Order reference

**Decision**: a Postgres sequence `order_ref_seq`; `order_ref = 'DH-' || to_char(accepted_at at Cairo, 'YYYY') ||
'-' || lpad(nextval, 6, '0')` computed in the Action. The number never resets (a gap on rollback is acceptable; the
reference is an identifier, not an invoice number). **Alternatives**: a per-year counter table — needed only if the
business wants numbering to restart each year; not asked.

## R9 — Reach-branch deadline

**Decision**: `WorkingHoursResolver::addWorkingMinutes(now, deadline.reach_branch_working_hours × 60, branch_id)`
(spec 004). `WorkingHoursUnavailable` → 409 `branch_hours_unavailable` (new code), nothing changes. The branch must be
in `listing_branch_option` (the schema's `trg_order_branch_subset` repeats it) **and** enabled (Action check).

## R10 — Request state guard

**Decision**: `buy_request_transition` seeded from the schema, plus `trg_buy_request_guard` (BEFORE UPDATE/DELETE):
moves only along the table, `queued` on insert, identity and locked columns (`listing_id`, `buyer_id`,
`queue_position`, `locked_*`, `deposit_amount`, `deposit_hold_txn_id`, `requested_at`, `seller_reply_deadline`)
frozen, no delete; stamps `resolved_at` on leaving `queued`. SQLSTATE `DH005` → 409 `illegal_buy_request_transition`.
A deferred trigger checks the money: at commit, a request that left `queued` for a released / withdrawn state has a
`deposit_release` entry tied to it in the same transaction; a new request has its `deposit_hold` entry.

**Rationale**: FR-021/FR-022 hold for any code path, like the listing guard (spec 010 R3).

## R11 — Expiry sweep

**Decision**: console command `buy-requests:expire`, scheduled every minute `withoutOverlapping()`, runs as the
system actor in the `system` scope; selects due ids (`state = 'queued' AND seller_reply_deadline <= now()`), then per
id one transaction: lock listing → lock request → re-check state and deadline → release (`released_expired`) →
refund → recount / move the listing. A request already answered is skipped. Deadlines are compared in the database
clock.

## R12 — Notify-when-free

**Decision**: a new column `buy_request.free_notified_at TIMESTAMPTZ` (schema addition). When a listing moves to
`live` with a zero queue (leave, decline, expiry, reinstatement), the Action dispatches, after commit, a job that
selects that listing's `withdrawn_by_buyer` requests with `notify_when_free` and `free_notified_at IS NULL`, skips a
buyer who already has an active request, sends the message and stamps the column — so each leave notifies at most
once.

## R13 — Notifications

**Decision**: `BuyRequestNotification` (event enum: `new_request` to the seller; `accepted`, `declined`,
`not_chosen`, `expired`, `piece_withdrawn`, `seller_suspended`, `free_again` to the buyer; `order_cancelled` to both
when staff cancel an acceptance), SMS + mail, queued,
`afterCommit`, in the customer's language, following `ListingDecisionNotification`. The buyer is never named to the
seller; the seller never to the buyer.

## R14 — Suspension and take-down

**Decision**: `ReleaseQueueAction::releaseAll(Listing, reason)` releases every queued request of a locked listing as
`released_declined` with its refund and collects the buyers to notify. Used by:
- staff take-down and seller withdraw from `reserved` — move `reserved → withdrawn` first, then release (the order
  keeps the consistency trigger satisfied at commit either way);
- `HoldListingsOfCustomerAction::hold()` — now also `reserved → suspended_hold` + release (the listing transition
  exists: "category paused"; its note becomes generic). `restore()` is unchanged: `suspended_hold → live` with an
  empty queue.

`AcceptBuyRequestAction` refuses `buyer_suspended` when the head's buyer is suspended.

## R15 — What the market and the seller's listings add

**Decision**: market detail adds `deposit_amount` (indicative, from the current price and `deposit.buyer_pct`; null
when there is no price) so the app never computes 20% itself (golden rule 5). The seller's `ListingResource` adds
`order` (`order_ref`, branch, `reach_branch_deadline`, `accepted_at`) when accepted. `queue_count` already exists.
The buyer's own place on the piece page comes from `GET /customer/me/buy-requests?listing_id=…&state=queued`.

## R16 — Place in line

**Decision**: `queue_position` is the stored monotonic number; responses add `place_in_line` (1 + queued requests
with a lower position) and `ahead_count` (= place − 1), computed per request, never stored.

## R17 — Throttles

`customer.buy_requests` 10/min on send; reads use the existing customer defaults.

## R18 — New error codes

| Code | HTTP | Raised by |
|---|---|---|
| `price_moved` (details `current_price`, `deposit_amount`) | 409 | send |
| `price_unavailable` | 409 | send (gold, no usable price) |
| `already_in_queue` | 409 | send (partial unique index) |
| `listing_not_purchasable` | 409 | send (not live/reserved) |
| `cannot_buy_own_listing` | 409 | send |
| `deposit_agreement_required` | 422 | send |
| `insufficient_funds` (+ details) | 409 | send (existing code, new details) |
| `not_in_queue` | 409 | leave |
| `not_queue_head` / `queue_empty` | 409 | accept, decline |
| `branch_not_in_options` | 409 | accept |
| `buyer_suspended` | 409 | accept |
| `branch_hours_unavailable` | 409 | accept |
| `illegal_buy_request_transition` | 409 | any guarded request move (SQLSTATE DH005) |
| `order_not_cancellable` | 409 | staff cancel on an order not in `awaiting_delivery` (R22) |

`category_paused` / `category_stopped` stay unused (no `category_control`).

## R19 — The order table

**Decision**: create from the schema only what acceptance needs: the `order_state` type, `"order"` (§9 verbatim),
`order_ref_seq`, `trg_order_branch_subset` / `assert_branch_in_options`, `order_transition` with its seed and
`trg_order_transition` / `assert_order_transition` (05 §…, so the orders spec inherits a guarded table), forced RLS
`order_isolation` (elevated OR seller or buyer = me), and the FKs `ledger_transaction.order_id` / `buy_request_id`
(`lt_order_fk`, `lt_request_fk`). Not created: `order_branch_change`, `order_deadline_extension`, inspection,
settlement and later tables. No order endpoint.

## R20 — Rollback

**Decision**: the migration's `down()` refuses (throws) while any `buy_request` row exists: those rows are tied to
ledger entries that cannot be dropped (the ledger is append-only), and dropping the requests would orphan held money.
On an empty table it drops everything in reverse. Stated in the docblock and the PR (Constitution: reversibility note).

## R21 — Apps

- **Dashboard**: `ListingQueuePanel` in `ListingReviewPanel` (queue table + order box), chips *Reserved* and
  *Accepted* on the Listings page, `can_take_down` from reserved with the modal saying the queue is released; types
  for the queue and order; *Cancel acceptance* (`DModal`: reason, relist or withdraw) on the order box for `order.cancel`; the permission string and
  the `orders` audit category; the new setting on *Commission rates → Deadlines and deposits*; error codes mapped.
- **Customer App**: `BuyRequestsApi` + models; piece page (deposit, queue count, my place, send, leave with notify);
  *Request sent*, *You need a little more* from the API; the deposit terms sheet; Orders → Buying (my requests) and
  Selling ("Decide today" per reserved listing: head request, Accept with the branch pick, Decline); the fake backend
  in `test/flows_test.dart`. Order tracking after acceptance stays mock.

## R22 — Staff cancel-acceptance (analysis H3)

**Decision**: `POST /dashboard/orders/{order}/cancel` with `{ "reason": "10–1000 chars", "relist": true|false }`,
permission **`order.cancel`** ("Cancel an order", group Orders, seeded to CEO, COO and Operations per Part 1 §4.1),
`idempotent`, audited (`order.cancelled`, new audit category `orders`, reason = the staff reason, details: order ref,
listing, relist, refund amount). One transaction in the `staff` scope: lock the listing → lock the order → state must
be `awaiting_delivery` (else `order_not_cancellable`) → order `awaiting_delivery → cancelled_staff` (new `order_state`
value and `order_transition` row) → `deposit_release` of the accepted request's deposit (buyer held −d, available +d,
actor the staff member, `order_id` + `buy_request_id`) → listing `accepted → live` (relist; empty queue, notify-when-free
fires) or `accepted → withdrawn` (final), via `MovesListing` with the reason as note (new `listing_transition` rows) →
audit → after commit `BuyRequestNotification` `order_cancelled` to buyer and seller. The request stays `accepted`
(its history is true); `trg_buy_request_money` accepts the release on an accepted request only when its order is
`cancelled_staff`.

**Rationale**: until the orders spec exists this is the only exit for money held on an accepted order (analysis H3).
A new order state keeps the truth: `cancelled_seller` means the seller cancelled or missed the deadline and counts
toward seller suspension (Part 3 §9.1); a staff decision must not. **Alternatives**: reuse `cancelled_seller` —
wrong meaning, would feed the future cancellation count; a buy-request transition `accepted → released_declined` —
rewrites history (the request *was* accepted).
