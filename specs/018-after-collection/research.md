# Research: After collection — free relist and rating

Decisions that turn the spec into a build. Each: **Decision · Rationale · Alternatives**. Facts cite the checkouts of 2026-10-08 (Backend `9cb3fe1`, Dashboard `f73065f`, Flutter `88539b6`).

## R1 — Where the window end is stored
**Decision:** a nullable `free_relist_until TIMESTAMPTZ` on `collection` (the row that already holds `collected_at` and `handover_by`), written by `HandoverPieceAction` in the handover transaction together with `collected_at`. Constraint: null, or `collected_at IS NOT NULL AND free_relist_until > collected_at`.
**Rationale:** the stored-deadline pattern (`reach_branch_deadline`, `collect_deadline`); same transaction as the handover (spec Q2); the buyer already reads their own `collection` row under the `order` scope. Nothing recomputes it, so a later setting or hours edit cannot move it.
**Alternatives:** on `order` (a wider table, more triggers); on the origin `listing` (the old MySQL design — wrong owner after the sale); recompute on read (moves the buyer's deadline when staff edit hours).

## R2 — Computing the end
**Decision:** `DeadlinePolicy::freeRelistEnd(CarbonImmutable $from, int $branchId): ?CarbonImmutable` = `WorkingHoursResolver::addWorkingMinutes($from, hours*60, $branchId)` with the setting read live through `Settings`; returns `null` (no offer) when hours = 0 or `WorkingHoursUnavailable` is thrown. The handover audit context gains `free_relist_offer: open|none` and `free_relist_none_reason: setting_zero|hours_unavailable|free_relist_sale`.
**Rationale:** the resolver is the only entry point (Part 3 §1); spec FR-002 says the handover never fails on this. The branch is `order.branch_id` (the branch the handover is scoped to).
**Alternatives:** raise `branch_hours_unavailable` like acceptance does — rejected, the owner decided the handover must succeed.

## R3 — "Free-relist sale gives no offer" (Q3 B)
**Decision:** at handover, if the origin listing has `relisted_from_order_id IS NOT NULL`, store no window. The offer reader also returns `none` for such orders (defence in depth).
**Rationale:** the rule is a property of the sale, known at handover; avoids a runtime graph walk.

## R4 — The new listing and its link
**Decision:** `listing.relisted_from_order_id UUID NULL REFERENCES "order"(order_id)` with a **partial unique index** where not null (one relist per origin order, FR-004). The link is set only on INSERT and never changes (extend `listing_guard`'s identity check). A new listing for the same origin order gets 409 `already_relisted` (the Action pre-checks under lock; the index is the backstop and its violation is mapped to the same error).
**Rationale:** it is both the idempotency anchor and the waiver marker; a second column for the waiver would be a second source of truth.
**Alternatives:** separate `free_relist` table — heavier, would still need the listing to point back; a boolean `commission_waived` — duplicates the link.

## R5 — Creating the listing live without review
**Decision:** (1) INSERT as `draft` (the guard allows only draft). (2) Add `('draft','live','free relist: no review')` to `listing_transition`. (3) Extend `listing_guard`: the move `draft → live` is allowed **only** when `NEW.relisted_from_order_id IS NOT NULL`; plus, on INSERT with a link, verify the origin order is `completed`, its `buyer_id = NEW.seller_id`, its collection's `free_relist_until >= now()`, and that the origin listing has no link (reading with the same scope-elevation pattern as `tax_invoice_reconciled`). (4) Write the `listing_state_change` rows (`NULL→draft` creation, then `draft→live` with note `free relist of order <order_ref>`), as `trg_listing_change_recorded` requires.
**Rationale:** keeps "no review" a database-enforced exception, not an application convention; ordinary drafts still cannot skip review (a test proves it). `listed_at` is stamped by the existing guard.
**Alternatives:** INSERT as `live` (the guard forbids it and loosening it is wider); `draft → in_review → live` with a system approval (adds a staff-looking actor and a transient review row).

## R6 — What is copied
**Decision:** category, `piece_type_id`; `karat_code` and `stated_weight_g` from the origin order's **latest inspection result** (`measured_karat`, `measured_weight_g`), falling back to the origin listing's stated values for a stone-only piece; photos, video, stone-certificate rows copied as new `listing_media` rows pointing at the **same** `storage_ref` (new `media_id`, same `kind`, `position`, `mime`, `is_private=false`); `listing_branch_option` rows of the origin that are still enabled (none left → `branch_options_required`). Entered: `making_charge_per_g` or `asking_price`, `description`; the ownership declaration row for the **new** listing (current `legal_doc_id`).
**Rationale:** the `order` scope's `listing_media_order_read` policy already returns only `NOT is_private` media, so the seller's invoice cannot be copied even by a bug; `RelistReturnedPieceAction` already uses measured karat/weight the same way; media objects are never deleted from the private disk, so shared references are safe (A8).
**Alternatives:** re-upload (needs the app to hold the bytes); copy the objects (cost, no benefit).

## R7 — RLS scope for the relist
**Decision:** `DatabaseActor::order(...)` (non-elevated). Reads: origin listing/media/branches via the existing `*_order_read` policies and the order/collection via `order_isolation`; writes: the new listing and its media/branches/declaration/history via the seller-owns-it policies (`seller_id = dahab_current_customer_id()`). If any insert is refused by a policy, the fix is a narrowly scoped **new** policy (`listing_state_change` has an order-scope INSERT policy that requires an order for the listing, so the new listing's history rows must use the seller-owns-listing policy from spec 010 — verify in T-B07), never an elevation.
**Rationale:** FR-060; Constitution II.

## R8 — The waiver at settlement
**Decision:** `ListingPricer::piece()` and the settlement path pass `commissionWaived: $listing->relisted_from_order_id !== null` to `Piece::gold|diamond|goldWithDiamond`. `PriceCalculator` already returns commission 0 and VAT 0 (VAT is a percent of commission) and `OrderSettlement::post()` already omits zero lines (`posting_nonzero`). The minimum commission is not applied to a waived piece (the calculator branch at `$piece->commissionWaived`). Spread is computed as before.
**Rationale:** one existing, tested branch; no new formula. `settlementNotPossible` still guards `proceeds <= 0`.
**Alternatives:** a post-hoc zeroing in `OrderSettlement` — would desync order figures from the ledger.

## R9 — Invoices
**Decision:** `IssueTaxInvoices::issue()` skips the `-S` invoice when `$f->commission` is zero (the table's `net_amount > 0` CHECK stays untouched); the `-B` invoice is unchanged. Add a **deferred** check (SQLSTATE DH012) `trg_free_relist_no_seller_invoice`: for an order whose listing has a link, no seller invoice may exist; for an order without a link, `commission_amount > 0`'s seller invoice must exist once the order is settled — implemented as a constraint trigger on `tax_invoice` insert and on `order` settlement.
`CustomerOrderResource` shows `invoice: null` and `no_fee: true` for the seller's waived sale. Credit-note paths already require a seller invoice, so none can exist.
**Rationale:** the owner chose "buyer's invoice only"; the existing reconciliation trigger checks only inserted rows, so it needs no change. **Finance sign-off pending** (spec FR-021).

## R10 — Offer status (derived)
**Decision:** `FreeRelistOffer::for(Order, Collection, ?Listing $relisted)`:
`none` if no `free_relist_until`; `used` if a listing with `relisted_from_order_id = order` exists; `expired` if `now() > until`; else `open`. Returned to the buyer only (RLS already hides the order from others) and to staff. No sweep, no job.
**Rationale:** nothing to heal; server time is the authority (the app countdown is cosmetic).

## R11 — Endpoints and gates
**Decision:** `POST /customer/me/orders/{order}/free-relist` inside the existing `customer.gate:trade` group with `idempotent`; `POST /customer/me/orders/{order}/rating` in a `customer.gate:verified` group with `idempotent` (the verified gate passes suspended, answers `account_closed` for closed). Throttle: reuse `customer.listings` for relist, a new `customer.ratings` limiter (10/min) for rating. Names are [PROPOSED] in the spec; no existing route is renamed. `CustomerRouteAccess` needs **no** change because both routes carry a gate.
**Alternatives:** put rating in `OPEN_ROUTES` — unnecessary and it would let pending/rejected customers rate.

## R12 — Rating storage and windows
**Decision:** table `order_rating(rating_id uuid PK, order_id, party_role 'seller'|'buyer', customer_id, stars smallint 1..5, note text ≤500 null, created_at)`; `UNIQUE (order_id, party_role)`; append-only guard (SQLSTATE **DH016** — DH001–DH015 are taken; UPDATE/DELETE refused) and the spec 017 `not_closed` BEFORE INSERT trigger (DH013) on `customer_id`, as the tables listed in the spec 017 migration have; CHECK via trigger that `customer_id` is the order's seller or buyer matching `party_role`. Windows are computed, not stored: seller opens at the `order_state_change` row that moved the order to `ready_to_collect`; buyer at `order.completed_at`; both close 30 calendar days later (A9). A dispute-against-sale order (`cancelled_inspection`) accepts no new rating; an existing one stays.
**Rationale:** nothing to sweep; the `order_state_change` history already holds the opening instant; immutability by the engine like `order_state_change`.
**Alternatives:** store `opens_at/closes_at` on the order — redundant columns to keep in step.

## R13 — Rating visibility and permission
**Decision:** customer reads only their own row (`order_rating_isolation`: `customer_id = dahab_current_customer_id()`); the order read returns `rating { can_rate, closes_at, given }` for the caller's own party only. Staff: `StaffPermission::RATING_VIEW = 'rating.view'` (group "Orders" or "Customers" — [PROPOSED] Orders), seeded to CEO and COO through the same defaults map as `LISTING_REPORT_HANDLE`; staff reads go through the elevated, permission-checked staff path (`ShowOrderAction` adds a `ratings` block only when the viewer holds it).
**Rationale:** FR-033/034; mirrors how spec 017 added `listing_report.handle`.

## R14 — Notifications
**Decision:** `OrderEvent::FREE_RELISTED` (customer-facing text in EN/AR, `->linkTo(InboxLinkKind::LISTING, newListingId)`), sent by `FreeRelistAction` through `TellsOrderParties::tellOrder` after commit; `OrderEvent::COLLECTED` gains an optional `deadline` (the window end) for the **buyer's** copy only; `OrderNotification::body()` adds one sentence when it is set. The inbox copy keeps the same text (it contains no code). No rating or expiry notification.
**Rationale:** FR-040–042 and the spec 017 pipeline (SMS + email + inbox with no extra channel work).

## R15 — Audit
**Decision:** new `AuditEvent::ORDER_FREE_RELISTED = 'order.free_relisted'` (customer actor, entity `order`, context: origin and new listing ids, karat/weight carried, price, no PII) and `ORDER_RATED = 'order.rated'` (stars only, **no note text**); the handover event gets the `free_relist_offer` context from R2. The customer-file activity query already includes rows where the customer is the actor, so both appear; the presenter hides `order.rated` from viewers without `rating.view`. Staff reads are not audited (A7).

## R16 — Dashboard and Customer App seams
**Decision (Dashboard):** `OrderDetailPanel.vue` gets two child components (`FreeRelistPanel.vue`, `OrderRatingsPanel.vue`, the second behind `can(permissions.ratingView)`); `OrdersTable.vue`/`pages/orders/index.vue` get a `free_relist` select filter wired through `useOrders.ts` → `order.service.ts` → `endpoints.ts`; `ListingHistory.vue` and the listing detail show the "Free relist of order …" line from `relisted_from_order`; `pages/customers/[id].vue` already renders the activity list, so only labels for the two events are added; `types/staff.ts` adds `ratingView: 'rating.view'`.
**Decision (Customer App):** `CustomerOrder` gains `freeRelist` and `rating` blocks; `orders_api.dart` gets `freeRelist(...)` and `rate(...)`; a new `FreeRelistCard` in `order_screen.dart` (completed order, buyer) replaces nothing live (the seller's `relist` is unrelated) and uses a countdown driven by `ends_at` with `DateTime` math (no timers on mock data); `RateScreen` in `order_flows.dart` becomes a live screen reached from the order screen; `R.rate` leaves `mockScreens`; `app_session.dart`'s mock relist timer is deleted; the fake backend in `test/flows_test.dart` gains the two endpoints; i18n EN/AR strings added to `lib/core/i18n/i18n.dart`.
