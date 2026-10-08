# Feature Specification: After collection — free relist and rating

**Feature Branch**: `feature/after-collection` in all three repositories — **not created by this task**. Base: `main` of each repo — Backend `9cb3fe1`, Dashboard `f73065f`, Flutter `88539b6`. **Verified 2026-10-08** against the GitHub `origin/main` of all three (Backend `9cb3fe1` = spec 017 merge; Dashboard `f73065f`; Flutter `88539b6`). The first discovery pass ran on a stale Backend clone at `3df0a58` and was redone against `9cb3fe1`; D17 lists what spec 017 changed.

**Created**: 2026-10-07 · **Clarified**: 2026-10-07 (one round, Q1–Q19 answered)

**Status**: **Final specification — ready for `/speckit-plan`.** Implementation has NOT started. Nothing is committed or pushed.

**Input**: Spec 018 — After Collection: Free Relist and Rating — all three projects.

## How to read this document

| Label | Meaning |
|---|---|
| **[DECIDED Qn]** | Decided by the product owner in the clarification round (answer *n*). Binding. |
| **[CONFIRMED]** | Stated by a source document or by the built code (source named). |
| **[CONSTRAINT]** | Follows from an established pattern of specs 001–016 or from a built guard; not a new product decision. |
| **[PROPOSED]** | A name or mechanism chosen here so the plan has something concrete; the plan may rename it. Not a business rule. |
| **[ASSUMPTION]** | Not stated anywhere; listed again in *Assumptions* so none is hidden. |

## Discovery (what the repository says)

- **D1 — Environment.** The first specification pass saw only a stale `dahab-backend`. On 2026-10-08 the Dashboard (`f73065f`) and Flutter (`88539b6`) repos were cloned read-only and the Backend was re-read at `9cb3fe1`; file paths in this spec and in `plan.md` are from those checkouts (D18). The prototype has `#s-rate`, `relistFree()` and `#relist-card`; the Flutter route constant is `R.rate` → `RateScreen` (D18).
- **D2** — `docs/*.docx` are plain markdown files with a `.docx` extension.
- **D3 — The window is only a setting.** `deadline.free_relist_working_hours` = 12 (`working_hours`) is seeded and is `SettingKey::DEADLINE_FREE_RELIST_WORKING_HOURS` (group *operations*); **no code reads it**. [CONFIRMED]
- **D4 — Sources.** Blueprint §4 *"Free relist after collecting — 12 working hours — Enough to change your mind, not enough to trade on the gold price"*; Terms draft §5.5 *"you may relist the piece with no commission within twelve working hours, returning it to the branch at your own cost"*; prototype `#relist-card` (countdown pill, *"put the piece straight back on the market with its IGI certificate and pay no commission, within 12 working hours of collecting it. Bring it back to IGI yourself, and the second inspection is free"*, button *Relist this piece, 0% commission*, toast *"Back on the market at 0% commission."* — it only toasts); Dashboard Settings *Free relist window*. Nothing defines states, price, invoice or rating. [CONFIRMED]
- **D5 — Disagreement (reported).** The original MySQL design placed `free_relist_until` / `relisted_from_id` on the **listing** and tied the window to a *returned* piece; blueprint, terms and prototype tie it to the **buyer who collected a paid piece**. The PostgreSQL schema has neither column. Resolved by the owner's answers (Q1, Q2, Q8): the buyer's right, new listing, link to the origin order. The old column names are not authoritative.
- **D6 — A sold listing cannot be reused.** `listing_transition` has no move out of `sold` except `sold → uncollected_expired`; `completed` is a final order state; "the order never re-opens" (Part 3 §10). A free relist is therefore a **new listing**. [CONFIRMED]
- **D7 — Handover.** `HandoverPieceAction` (staff, `order.handover`, branch-scoped to `order.branch_id`) does `ready_to_collect → completed`, stamps `order_collection.collected_at`, moves no money, and tells both parties (`OrderEvent::COLLECTED`). A proxy handover is the same action with `collector: proxy` (spec 014). [CONFIRMED]
- **D8 — Resolver.** `WorkingHoursResolver::addWorkingMinutes($start, $minutes, $branchId)` counts on the branch's weekly hours, its closures plus all-branch holidays, and its timezone; it throws `WorkingHoursUnavailable` when hours cannot be worked out. Spec 011 stores its result (`reach_branch_deadline`). [CONFIRMED]
- **D9 — Existing "relist" is a different feature.** `POST /customer/me/orders/{order}/relist` (`RelistReturnedPieceAction`, trade gate, idempotent, audited `order.relisted`) is the **seller** relisting an unpaid sale's returned piece. It is not reused, renamed or changed. [CONFIRMED]
- **D10 — Waiver exists only in the calculator.** `Piece::$commissionWaived` makes `PriceCalculator` return commission 0 (VAT follows); nothing sets it. Settlement stores `commission_amount`, `vat_amount`, `spread_amount` on the order. [CONFIRMED]
- **D11 — Invoice guard.** `tax_invoice.net_amount > 0` and the seller invoice's net is the commission posted, so a 0-commission sale cannot have a seller invoice as built; a deferred check (DH012) reconciles invoices with the ledger. [CONFIRMED] → handled by FR-021.
- **D12 — Inspection is free** (open-questions §2 IGI). No ledger posting exists for an inspection. [CONFIRMED]
- **D13 — Listing creation guards.** `listing_guard` accepts an INSERT only as `draft`; `draft → live` is **not** in `listing_transition`; every state move needs a `listing_state_change` row in the same transaction; `listed_at` is stamped when a listing first becomes `live`. A listing needs ≥ 1 enabled branch option (`branch_options_required`) and a current ownership declaration (`ownership_declaration_required`). Media are encrypted objects referenced by `storage_ref`; photo/video/certificate counts are limited. [CONFIRMED]
- **D14 — Rating is in no business document.** Spec 012: *"rating … stay prototype-only: the Backend has no such features."* Prototype `#s-rate`: after *"56,952 EGP is in your wallet"* (a **seller** screen at payout) → *"How did it go?"*, five stars, optional textarea *"Anything we could do better?"*, **Send**; plus an invite-a-friend card (**Spec 019, out of scope**). [CONFIRMED]
- **D15 — Patterns to follow.** `idempotent` middleware (runs last) on every POST; `RecordAuditLogAction` + `AuditEvent`; forced RLS and the non-elevated `order` scope (`DatabaseActor::order`); lock order listing → order; after-commit notifications (`TellsOrderParties`, SMS + email); the `StaffPermission` catalogue (seeded, editable in the Dashboard); Pest feature tests at the HTTP boundary; Postman mirror; `#[OA]`; "Changed by spec NNN" notes in the Technical Spec; `docs/features/<name>.md`. [CONSTRAINT]
- **D17 — What spec 017 (customer account, in `9cb3fe1`) changes for this spec.** (a) **Every notification now also lands in an in-app inbox** (`InboxNotification`, `->linkTo(InboxLinkKind::ORDER|LISTING, id)`, EN/AR rendered at send time; collection codes are masked in the inbox) — so FR-040/041 reach SMS + email + inbox with no extra channel work. (b) `customer.status` gained **`closed`**: both gates answer 403 `account_closed`; routes that must work for a suspended customer need no gate change (the `verified` gate passes suspended). `CustomerRouteAccess` default-denies any customer route that has neither a gate nor an entry in `OPEN_ROUTES`. (c) New permission `listing_report.handle` and a listing-reports page exist (Dashboard `listingReport` types/services) — the earlier "listing reports not built" note is obsolete. (d) New setting `saved.max_per_customer`. (e) Latest migration is `2026_10_10_000010_customer_account.php`; new migrations sort after it. (f) Closing an account is blocked by open orders/requests/disputes/money (`CloseBlockers`), so a closed customer cannot have an open offer that matters. [CONFIRMED from `9cb3fe1`]
- **D18 — Real Dashboard and Flutter code.** *Flutter:* `R.rate` = `'rate'` in `lib/routing/routes.dart`, `R.rate: (_) => const RateScreen()` in `lib/routing/app_router.dart`, `RateScreen` in `lib/features/orders/order_flows.dart`, and `R.rate` is in `mockScreens` (`lib/widgets/mock_flag.dart`, so the screen shows the MOCK banner); the free-relist countdown is a mock timer in `lib/services/app_session.dart` (`_relistMinutes = 12*60`, `relistMinutesLeft`, not shown by any live widget); the live seller "Put it back on the market" (`OrdersRepository.relist`, `lib/services/api/orders_api.dart`, `order_screen.dart`) is the **unrelated** spec 012 action (D9); `CustomerOrder` is in `lib/models/order.dart`; fake backend and flow tests in `test/flows_test.dart`, `test/mock_flags_test.dart`, `test/routes_smoke_test.dart`; i18n in `lib/core/i18n/i18n.dart`. *Dashboard:* orders are `src/pages/orders/index.vue` with `src/components/orders/{OrderDetailPanel,OrdersTable,OrderTimeline,HandoverModal}.vue`, `src/composables/useOrders.ts`, `src/services/order.service.ts`, `src/types/order.ts`; permission strings are in `src/types/staff.ts`; endpoints in `src/api/endpoints.ts`; listings `src/pages/listings/index.vue` + `src/components/listings/ListingHistory.vue`; customer file `src/pages/customers/[id].vue`; nav/mock `src/mock/nav.ts`. [CONFIRMED from the checkouts]
- **D16 — Dispute against the sale** ends a settled order in `cancelled_inspection` (`ResolveDisputeAction`); disputes can freeze orders only from `at_inspection` … `ready_to_collect`, never from `completed`. [CONFIRMED]

## Scope

**In:** (A) the buyer's free relist after collection; (B) a per-party rating of the experience with Dahab; the Backend, Dashboard and Customer App parts of both, in English and Arabic; replacing the matching mock/prototype behaviour.

**Out (non-goals):**
- Referral, invite-a-friend, promo codes (Spec 019) — the invite card of `#s-rate` is not part of this spec.
- Rating the other party, a public reputation, any score, average, ranking or badge shown to customers.
- A gold "IGI certificate" badge or field. [DECIDED Q9]
- Market makers or any general commission waiver; the spread is **not** waived.
- Staff extending, reopening or cancelling a free-relist offer. [DECIDED Q10]
- Reminders or expiry messages for the offer; any notification about ratings. [DECIDED Q13, Q18]
- Changes to the seller's "relist a returned piece" (D9), to commission/spread formulas, or to the origin sale's invoices and ledger.
- ETA e-invoicing (Part 4 §4).

## Terminology

- **Free relist** — the buyer of a collected piece putting it back on the market at 0% commission.
- **Free-relist window** — the `deadline.free_relist_working_hours` working hours that start at the staff handover; it is the time within which the buyer may **press Relist**. [DECIDED Q1, Q2]
- **Origin order / origin sale** — the `completed` order the piece was collected from. **Relisted listing** — the new listing the free relist creates.
- **Offer** — what the buyer sees on the origin order: `open`, `used`, `expired`, or `none`.
- **Rating** — one rating of "my experience with Dahab" by one party of one order: 1–5 stars and an optional note. [DECIDED Q14]

## Actors and permissions

| Actor | Does | Gate / permission |
|---|---|---|
| Buyer who collected | Sees the offer + countdown (even if suspended); relists; rates once the order is `completed` | Customer token; relist = **trade gate** (suspended → 403 `account_suspended`; closed → 403 `account_closed`) [DECIDED Q12]; seeing the offer and rating are **not** trade actions [DECIDED Q12, Q18] |
| Seller of the origin order | Rates once the sale is paid | As above; suspended may rate [DECIDED Q18] |
| Staff, order readers | See the Free relist panel, Free relist filter, Ratings panel's existence only if they hold `rating.view` | `order.view` for the order/relist data; **`rating.view`** (new) for ratings, seeded to **CEO and COO**, editable in Roles [DECIDED Q17] |
| Staff, listing / customer-file readers | See the "Free relist of order …" line and the customer's relist/rating events | Existing listing and customer-file permissions; rating events only with `rating.view` [PROPOSED mapping] |
| System | Stores the window end inside the handover transaction | System actor where a job is needed (none expected) |

No staff action changes an offer or a rating. [DECIDED Q10, Q17]

## User Scenarios & Testing

### User Story 1 — The buyer is offered a free relist at collection (P1)
When staff hand the piece over, the buyer's order shows a "Changed your mind?" offer with a countdown in working hours.

**Independent test:** hand over an order whose branch has hours → the order read shows `free_relist.status = open` with the stored end time; a branch with no resolvable hours → handover still succeeds and the status is `none`.

**Acceptance scenarios**
1. **Given** an order `ready_to_collect` at a branch with hours, **When** staff hand over (buyer or named proxy), **Then** the order is `completed` and the end of the window = handover instant + 12 working hours on that branch's calendar (weekends and closures skipped), stored once. [Q2]
2. **Given** a Thursday-evening handover and a branch closed Friday, **When** the end is computed, **Then** it falls on the next open working time (Sunday) per the resolver.
3. **Given** the resolver throws `WorkingHoursUnavailable`, **When** staff hand over, **Then** the handover succeeds, no window is stored, the offer is `none`, and the handover audit entry records `free_relist_offer: none` with the reason. [Q2]
4. **Given** the origin listing is itself a free relist, **When** its order is handed over, **Then** no window is stored and the offer is `none`. [Q3 B]
5. **Given** the setting is changed after the handover, **Then** the stored end does not move.
6. **Given** orders completed before this release, **Then** their offer is `none` (no backfill). [ASSUMPTION A1]

### User Story 2 — The buyer relists the piece at 0% commission (P1)
The buyer sets the price and description, re-accepts the ownership declaration, confirms, and the piece is on the market.

**Independent test:** relist inside the window → one new `live` listing owned by the buyer, linked to the origin order; the origin listing/order unchanged; a second attempt creates nothing.

**Acceptance scenarios**
1. **Given** an `open` offer and a buyer who is not suspended, **When** they submit price (making charge per gram for gold; asking price for stones), description (optional, prefilled) and the current ownership declaration, **Then** in one transaction a new listing is created with `seller = buyer`, category, piece type, **karat and weight as IGI measured them** (the origin order's latest inspection result; `stated` values if none), photos, video, stone certificate and branch options copied, and the state is `live` with no review. [Q8, Q9]
2. **Then** the listing history shows the creation and a move to `live` whose note names the origin order ("free relist of order DH-…"), `listed_at` is stamped, and the audit log has the relist event. [Q8]
3. **Then** the original seller's private invoice is **not** copied; no gold certificate field is created. [Q9]
4. **Given** the window passed, **Then** the request is refused (`free_relist_expired`) and the offer reads `expired`; it cannot be retried; the owner may still list the piece as a **normal** new listing at normal terms. [Q1, Q4]
5. **Given** a suspended buyer, **Then** relist is refused 403 `account_suspended`; the offer and countdown still display. [Q12]
6. **Given** a user who is not the buyer, **Then** 404 (RLS). **Given** the order is not `completed`, **Then** 409.
7. **Given** the origin listing's branch options are all disabled now, **Then** the request is refused with `branch_options_required` (existing rule) and nothing is created.
8. **Given** the current ownership declaration text changed since the form opened, **Then** `ownership_declaration_required` (existing rule).
9. **Given** two taps (same `Idempotency-Key`), **Then** the second replays the first answer; **given** two different keys or two devices at once, **Then** exactly one listing exists and the other request gets 409 `already_relisted`. [FR-004]
10. **Given** the free-relisted listing is withdrawn or never sells, **Then** the benefit is spent; a later listing of the same piece is a normal listing. [Q4 "once only"]

### User Story 3 — The relisted piece sells with no commission (P1)
The new sale follows the normal life; at settlement commission, its VAT and the 200 EGP minimum are 0, the spread still applies, and only the buyer gets an invoice.

**Independent test:** take a free-relisted listing through buy request → acceptance → receive → inspection → pay-balance; compare the ledger, order figures and invoices.

**Acceptance scenarios**
1. **Given** a relisted gold piece, **When** the balance is paid, **Then** `commission_amount = 0`, `vat_amount = 0`, `spread_amount` is computed as usual, `seller_proceeds = seller_gross`, and the ledger lines balance with no commission or VAT-payable line. [Q4–Q6]
2. **Then** exactly one invoice (`-B`, the buyer's) is issued; **no** seller invoice and no zero-value invoice exist; the reconciliation check accepts this; the seller's order view shows "No Dahab fee on this sale" instead of an invoice. [Q7]
3. **Given** a stone piece, **Then** commission on the stone value is 0 and nothing else changes.
4. **Given** the second inspection fails (karat mismatch, counterfeit, weight above tolerance, declined adjustment), **Then** the normal spec 012 outcomes apply (cancel, refund, suspension on karat/fake, relist as the seller's own returned piece, etc.); the free-relist offer on the origin order is **not** restored. [Q11]
5. **Given** the relisted piece is bought and collected, **Then** that order's offer is `none` (a free-relisted sale gives no free relist). [Q3 B]
6. **Then** the origin sale's invoices, commission, VAT, ledger and listing are unchanged, and no credit note is created. [Q7]
7. **Then** no ledger entry represents the "free second inspection". [D12]

### User Story 4 — Each party rates their experience with Dahab (P2)
After the sale, each side can give 1–5 stars and an optional note about their experience with Dahab, once.

**Independent test:** pay a balance → the seller can rate; complete the order → the buyer can rate; each exactly once; staff with `rating.view` read both; nobody else can.

**Acceptance scenarios**
1. **Given** a settled order (`ready_to_collect`, or `completed`), **When** the seller submits stars 1–5 and an optional note ≤ 500 characters, **Then** one immutable rating exists for (order, seller). [Q14–Q16]
2. **Given** the order is `completed`, **When** the buyer submits, **Then** one immutable rating exists for (order, buyer). The buyer cannot rate while the order is only `ready_to_collect`. [Q15]
3. **Given** 30 days have passed since that party's window opened (seller: the order entered `ready_to_collect`; buyer: `completed_at`), **Then** submitting is refused (`rating_closed`). [Q15]
4. **Given** a cancelled order (any `cancelled_*`), **Then** no rating can be submitted; a rating already given stays. [Q15, A3]
5. **Given** a rating already exists, **Then** a second submit with the same key replays; with another key it is 409 `already_rated`; there is no edit and no delete. [Q17]
6. **Given** stars missing or outside 1–5, or a note over 500 characters, **Then** 422.
7. **Given** a suspended customer with an open window, **Then** they may rate. [Q18]
8. **Given** the other party, **Then** they never see it; no average, count or score is shown to any customer anywhere. [Q17]
9. **Then** no notification is sent to the customer or to staff. [Q18]
10. **Then** a rating changes nothing else: no suspension, no "People to watch" input, no report, no risk field, no automated decision. [Q17]

### User Story 5 — Staff see free relists and ratings where they already work (P2)
**Independent test:** as CEO open an order with a free relist and ratings; as a role without `rating.view` the Ratings panel is absent and the endpoint refuses.

**Acceptance scenarios**
1. **Given** `order.view`, **When** staff open the origin order, **Then** a *Free relist* panel shows: status (open / used / expired / none), window end, a link to the relisted listing when used. Read-only. [Q19, Q10]
2. **Given** `order.view`, **When** staff open the relisted listing's own order, **Then** a line says it is a free relist (commission 0 by design). [PROPOSED]
3. **Given** the Orders list, **Then** a *Free relist* filter narrows to orders with an offer in a chosen status (open / used / expired). [Q19]
4. **Given** `rating.view`, **Then** the order detail has a *Ratings* panel (seller's and buyer's stars, note, date), staff-only; without it the panel and data are absent (403 on the read). [Q17, Q19]
5. **Given** a relisted listing in the Listings review/detail views, **Then** it shows "Free relist of order DH-…" with a link. [Q19]
6. **Given** the Customer file → History, **Then** it lists the relist event (and, with `rating.view`, the rating events). [Q19]
7. **Then** no new Dashboard section, navigation entry or page exists; no export of ratings. [Q19]

### Edge cases (all)
- Proxy collection counts for the buyer; the clock still starts at the handover. [Q2]
- Window ends exactly at the stored instant: `now > end` → expired; `now ≤ end` → open (server time; the app countdown is display only).
- Dispute against sale on an unpaid-to-collect order → `cancelled_inspection`: no new rating; an existing seller rating stays. [A3]
- A settled order in `disputed` (frozen from `ready_to_collect`): the rating window keeps its original opening instant; a rating may be submitted if still open. [A3]
- Buyer never collects → `uncollected_expired` listing: the offer never exists (no handover). When handed over late, the offer starts at that handover. [A4]
- Notification, SMS or email failure never rolls back a relist (after-commit). [CONSTRAINT]
- A relist whose copy of the media would exceed the media limits cannot happen (the origin already respected them); copy is all-or-nothing in the transaction.
- Setting `deadline.free_relist_working_hours` = 0 → no offer is created. [A5]

## Workflows (end to end)

### A. Free relist
```
Staff (Dashboard)  POST /dashboard/orders/{id}/handover  (order.handover, Idempotency-Key)
   └▶ Backend, one transaction: lock listing → order → collection; code check; order → completed;
        window_end = resolver(handover_at, setting hours, order.branch_id)   -- skipped if listing is a free relist, setting = 0, or hours unavailable
        store window_end; audit order.handed_over (+ free_relist_offer); after commit: COLLECTED notice to both parties (buyer's carries the window)
Customer App (buyer)  GET /customer/me/orders/{id}   → free_relist { status, ends_at, listing_id? }  + countdown
   └▶ "Relist this piece, 0% commission" → form (price, description prefilled, ownership declaration) → confirm
        POST …/free-relist  (trade gate, Idempotency-Key)
   └▶ Backend, one transaction (customer `order` scope): lock origin listing → origin order → (unique link guard);
        checks: caller = buyer, order completed, now ≤ window_end, not already relisted, branch options, declaration;
        create listing(draft, relisted_from_order_id) + history; copy media/branches; declaration row; draft → live (new transition) + history note;
        audit order.free_relisted; after commit: SMS + email confirmation to the buyer
   └▶ App: "Back on the market at 0% commission", opens My listings
Market: the listing is a normal live listing (queue, acceptance, reach-branch 12 working hours, IGI receive + free inspection, balance, settlement with waiver, invoice -B only, handover).
```
```
offer (derived from stored window_end, the relisted link and `now`):
   none ──────────────────────────── (no handover yet / free-relist sale / hours unavailable / setting 0 / pre-release)
   open  ──(relist)──▶ used            terminal
   open  ──(now > end)▶ expired         terminal, no retry, no job
```

### B. Rating
```
Customer App → GET /customer/me/orders/{id} → rating { can_rate, closes_at, given? }
   └▶ stars + optional note → POST …/rating (Idempotency-Key)
   └▶ Backend, one transaction (customer `order` scope): lock order; party = caller (buyer or seller); window check; unique (order, party_role); insert immutable row; audit order.rated
   └▶ Staff (rating.view): GET order detail → Ratings panel; Customer file → History event
```

### Failure, concurrency and retry matrix
| Case | Result |
|---|---|
| Invalid (wrong state, wrong user, bad input) | 404 / 409 / 422 with stable codes; nothing written |
| Expired window / closed rating | `free_relist_expired` / `rating_closed`; no retry possible |
| Duplicate tap, same key | Stored answer replayed |
| Duplicate, different key or concurrent | One survives (unique index + row locks); other 409 `already_relisted` / `already_rated` |
| Unauthorised staff | 403 (permission middleware); ratings 403 without `rating.view` |
| Suspended customer | Relist 403; offer visible; rating allowed |
| Failed inspection of the relisted piece | Normal spec 012 outcomes; offer not restored |
| Notification failure | After-commit; relist stays; existing retry/heal patterns |
| Branch hours unavailable at handover | No offer; handover unaffected |
| Terminal states | Offer: used / expired. Rating: given / closed |

## Requirements

### Free relist
- **FR-001** At handover, in the same transaction, the system MUST compute and store the end of the free-relist window = handover instant + `deadline.free_relist_working_hours` working hours using the branch working-hours resolver on **the order's branch** calendar (its hours, closures, all-branch holidays, timezone). It is stored, never recalculated, and not changed by later setting or hours edits. [DECIDED Q1, Q2; CONSTRAINT D8]
- **FR-002** If the hours cannot be resolved, the setting is 0, or the origin listing is itself a free relist, the handover MUST succeed and no window MUST be stored; the offer is `none`; the handover audit records which reason. [DECIDED Q2, Q3]
- **FR-003** The window start is the staff handover instant for both in-person and proxy collections. [DECIDED Q2]
- **FR-004** At most one free relist per origin order, enforced by a unique database constraint on the new listing's link to the origin order, with row locks in the established order and `Idempotency-Key`; a second attempt is 409 `already_relisted`. [CONSTRAINT]
- **FR-005** A free relist MUST create a **new** listing owned by the buyer, linked to the origin order; the origin listing (`sold`), the origin order (`completed`) and their histories MUST NOT change. [DECIDED Q8; CONFIRMED D6]
- **FR-006** The relisted listing MUST be created directly `live` with no review: an INSERT as `draft`, then a `draft → live` move that exists in the allowed-moves table only for this path, with a history row whose note names the origin order, and `listed_at` stamped by the existing guard. [DECIDED Q8; CONSTRAINT D13]
- **FR-007** Carried over: category, piece type, karat and weight as measured by IGI in the origin order's latest inspection result (stated values if no result), photos, video, stone certificate (all by reference to the same encrypted objects), and the origin listing's branch options that are still enabled. Entered by the owner: price (making charge per gram for gold, asking price for stones) and description (prefilled from the origin). Not copied: the original seller's private invoice. No new certificate field or badge. [DECIDED Q9]
- **FR-008** The owner MUST accept the **current** ownership declaration for the new listing (same evidence rows as any listing). [DECIDED Q9; CONSTRAINT]
- **FR-009** The relist MUST be refused when: the caller is not the buyer (404), the order is not `completed` (409), the offer is not `open` (`free_relist_expired` / `already_relisted`), the buyer is suspended (403 `account_suspended`), or no enabled branch option remains (`branch_options_required`). [DECIDED Q12]
- **FR-010** The offer MUST be visible to the buyer of the origin order only, with its status and end instant, and MUST remain visible (and counting) while the buyer is suspended. [DECIDED Q12]
- **FR-011** An expired or used offer MUST NOT be retried or reopened by anyone, including staff; the owner can still make an ordinary listing at ordinary terms. [DECIDED Q1, Q10]
- **FR-012** The 0% applies to the relisted listing for its **entire life, once**: the first sale of that listing carries the waiver; withdrawing it spends the benefit. The waiver is a persistent property of the relisted listing (derived from its link to the origin order) that settlement reads. [DECIDED Q4]
- **FR-013** A sale of a free-relisted listing MUST NOT give its buyer a free-relist offer. [DECIDED Q3 B]

### Money and invoices
- **FR-020** At pay-balance of a free-relisted listing: commission = 0, commission VAT = 0, the 200 EGP minimum not applied, the **buy/sell spread unchanged**, seller proceeds = seller gross − 0 − 0, buyer total unchanged. Order figures, the ledger transaction (no commission or VAT line) and the settlement shape checks MUST agree to the piastre. [DECIDED Q4–Q6]
- **FR-021** For such a sale the system MUST issue only the buyer's invoice; it MUST NOT create a zero-value seller invoice; the reconciliation trigger (`tax_invoice_reconciled`) only checks invoices that exist, so it needs no relaxation; a new check MUST assert that a waived order has no seller invoice and a non-waived order keeps both; the seller's order read and the Dashboard invoice views show "No Dahab fee on this sale" instead. [DECIDED Q7; CONSTRAINT D11]
- **FR-022** The origin sale's invoices, commission, VAT, ledger entries and credit notes MUST be unchanged; a relist creates no credit note and no money movement. [DECIDED Q7]
- **FR-023** The free second inspection MUST post nothing. [CONFIRMED D12]
- **FR-024** The relisted piece's second inspection and everything after follows spec 012 unchanged; failure does not restore the offer. [DECIDED Q11]
- **FR-025** The app's payout estimate for a free-relisted listing shows commission 0 and the spread, and states plainly that "the buy/sell difference still applies". [DECIDED Q5; ASSUMPTION A6 for the wording]

### Rating
- **FR-030** One rating per (order, party): `stars` 1–5 required, `note` optional ≤ 500 characters, plain text; about "my experience with Dahab" only. No rating of the counterparty. [DECIDED Q14, Q16]
- **FR-031** Seller: eligible from the moment the order enters `ready_to_collect` (balance paid); buyer: eligible when the order is `completed`; each window stays open **30 days** from that party's opening instant, then closes. No new rating on a cancelled order; ratings already given remain. [DECIDED Q15]
- **FR-032** A rating is immutable: no edit, no delete; a duplicate is replayed (same key) or 409 `already_rated`. The database refuses UPDATE and DELETE on ratings. [DECIDED Q17; CONSTRAINT]
- **FR-033** A customer reads only their own rating; the other party never sees it; there is no aggregate, average, count or score for customers or for the public. [DECIDED Q17; FR owner rule]
- **FR-034** Staff read ratings only with `rating.view` (new, seeded to CEO and COO, editable in Roles); no staff can create, change or delete one. [DECIDED Q17]
- **FR-035** A rating has no automated effect on suspension, "People to watch", reports, risk or any decision. [DECIDED Q17]
- **FR-036** A suspended customer may rate while their window is open. [DECIDED Q18]
- **FR-037** No notification, to the customer or to staff, results from a rating. [DECIDED Q18]
- **FR-038** The rating screen MUST NOT include any referral content. [OWNER RULE; Spec 019]

### Notifications
- **FR-040** When the relist is created: one SMS and one email (email when the customer has one) to the buyer-now-owner, sent after commit, through the existing notification pipeline — which since spec 017 also writes the same text to the customer's in-app inbox (link to the new listing). [DECIDED Q13; CONSTRAINT D17]
- **FR-041** The existing "collected" notification (`OrderEvent::COLLECTED`, SMS, email and inbox) gains the relist-window end for the **buyer's** copy only, only when an offer exists; the seller's copy is unchanged. [DECIDED Q13]
- **FR-042** No reminder and no expiry notification. [DECIDED Q13]

### Audit and history
- **FR-050** Audited: the handover's offer outcome (extra context on the existing handover event), the free relist (`order.free_relisted` [PROPOSED]) with origin order, new listing, price, carried karat/weight; each rating (`order.rated` [PROPOSED]) without the note text; a staff read of ratings is **not** audited [ASSUMPTION A7]. Audit entries are append-only and name the actor. [CONSTRAINT]
- **FR-051** The listing history carries the free-relist note; the Customer file History shows the relist, and ratings for `rating.view` holders. [DECIDED Q19]

### Security
- **FR-060** Forced row-level security: the offer and the relist run in the customer `order` scope (non-elevated; a customer sees only their own orders); the new listing is created under the buyer as seller; ratings are readable by their author only, written only by their author for their own party role, and readable by staff only through the permission-gated staff path; no policy is weakened. [CONSTRAINT]
- **FR-061** Every POST requires `Idempotency-Key`; relist is also throttled like other customer trade writes. [CONSTRAINT; throttle value → plan]
- **FR-062** All state changes happen in one transaction, with locks listing → order → rating/collection, never reversed. [CONSTRAINT]

### Localisation
- **FR-070** Every customer string for the offer, countdown, form, confirmations, errors, the "no Dahab fee" line and the rating screen exists in English and Arabic (including the SMS/email texts). Dashboard additions follow the Dashboard's i18n. [DECIDED owner]
- **FR-071** The stand-ins for these two features are removed from the Customer App: `R.rate` leaves `mockScreens` (no MOCK banner) and `RateScreen` becomes live without the invite card; the unused mock relist timer in `app_session.dart` is deleted; no widget shows a fixed countdown. [D18] [DECIDED owner]

## Proposed surfaces

*[PROPOSED] names; the plan fixes them. Existing patterns inspected: customer order routes under `/customer/me/orders/{order}/…`, staff under `/dashboard/orders/…`.*

| Surface | Change | Class |
|---|---|---|
| `GET /customer/me/orders` and `/{order}` | add `free_relist { status, ends_at, listing_id? }`, `rating { can_rate, closes_at, given? }`, and for the seller `invoice` absent with `no_fee: true` when waived | Non-breaking (new optional fields) |
| `POST /customer/me/orders/{order}/free-relist` | new; trade gate; `Idempotency-Key`; body price, description?, `ownership_legal_doc_id`; `201` with the new listing; errors `free_relist_expired`, `already_relisted`, `account_suspended`, `branch_options_required`, `ownership_declaration_required` | Non-breaking |
| `POST /customer/me/orders/{order}/rating` | new; `Idempotency-Key`; body `stars`, `note?`; errors `rating_closed`, `already_rated`, `rating_not_available` | Non-breaking |
| `GET /dashboard/orders`, `/{id}` | `free_relist` object; list filter `free_relist=open|used|expired`; ratings block only with `rating.view` | Non-breaking (optional field + optional filter) |
| `GET /dashboard/listings…`, customer-file activity | `relisted_from_order` reference; relist and rating events | Non-breaking |
| Staff permission catalogue | new `rating.view` | Potentially breaking only for exhaustive UI permission maps — Dashboard adds it |
| Order read values | a new `no_fee` flag; **no new enum value** is added to any existing response enum | — |

The existing `POST /customer/me/orders/{order}/relist` is untouched. [D9]

## Key entities and database requirements
*(requirements, not DDL; the plan writes migrations that mirror `docs/Database schema/*.sql`)*

- **Window end** stored once per order at handover (on the collection record or the order). Nullable; null = no offer.
- **Listing → origin order link** (nullable), **unique** where not null (one relist per origin order). Its presence is the waiver and the "this sale gives no offer" signal. Guard: the link cannot change after creation; only the free-relist path may set it.
- **New allowed move** `draft → live` (note: free relist, no review), usable only by the free-relist action's scope/guard so ordinary listings cannot skip review. Guard that a listing with a link is created only for the buyer of a `completed` origin order inside the window.
- **Rating**: (order, party_role, customer, stars 1–5, note ≤ 500 or null, created_at), unique (order, party_role), append-only guard, forced RLS (author reads own; staff via permission-gated path), CHECK that party_role/customer match the order.
- **Settlement**: commission/VAT = 0 allowed and consistent with the settlement-shape checks; a new check ties "waived" to "no seller invoice" (the existing DH012 reconciliation needs no change).
- **Permission** `rating.view` seeded by migration/seeder to CEO and COO.
- **Environments**: development and tests for this spec use only `dahab_wt018` and `dahab_wt018_dev` (owned by `dahab`, created by the owner). The main `dahab` database is never created, reset, migrated or otherwise used for this spec.

## Dashboard requirements (paths verified in D18)
Types → services (+ mocks) → composables → components. Order detail: *Free relist* panel and *Ratings* panel (gated by `rating.view`). Orders list: *Free relist* filter. Listings review/detail: "Free relist of order …" line. Customer file History: relist and (gated) rating events. Permission label for `rating.view`. Remove nothing existing; no new route, nav item or page. English and Arabic.

## Customer App requirements (paths verified in D18)
Models (`free_relist`, `rating`, relist response) → services → controllers → screens. Order screen: replace the prototype relist card with the live offer — status, end time, a **working-hours countdown that ends when the server's end time passes**, button *Relist this piece, 0% commission*, then price/description/ownership-declaration form, plain confirmation, success to My listings; errors in plain words (`free_relist_expired`, `already_relisted`, suspended notice); estimate copy that says the spread still applies. Rating screen (`R.rate`): "How did it go?", five stars, optional note ≤ 500, Send, thank-you; closed/already-given states; **no invite card**. The seller's order view shows "No Dahab fee on this sale" when waived. English and Arabic; remove the mock countdown, toasts and `MOCK` flags for these features; extend the fake backend and flow tests.

## Success criteria
- **SC-001** Within the window the buyer sees the offer and a countdown that agrees with the stored end time to the minute; after it, 100% of attempts are refused and the offer disappears from the screen.
- **SC-002** Double taps and parallel requests from two devices create exactly one relisted listing (0 duplicates in the concurrency test).
- **SC-003** For every free-relisted sale, commission and commission VAT are 0, the spread equals what the normal rule gives, and order, ledger and invoices agree to the piastre; 0 seller invoices exist.
- **SC-004** A customer rates in at most three taps; 100% of rating reads by non-authors and by staff without `rating.view` are refused.
- **SC-005** No rating changes any suspension, flag, report or price (verified by the absence of any such write path).
- **SC-006** No referral element appears in either feature; no customer-visible score exists.
- **SC-007** Every new customer string exists in English and Arabic.

## Test requirements (Pest, through the HTTP boundary, on `dahab_wt018`)
- Handover: offer stored (weekday, Thursday evening → Sunday, holiday, proxy), unavailable hours, setting 0, free-relist listing gives none, setting change does not move the end.
- Relist: happy path (every carried field, entered fields, declaration, history note, `listed_at`, audit); each refusal in FR-009; expiry boundary; double-tap replay; concurrent different keys (one survives); origin untouched; disabled branches; media copy; no private invoice; suspended buyer.
- Settlement/invoices: waiver figures, spread unchanged, ledger balanced with no commission lines, only `-B` issued, reconciliation accepts, stones, origin invoices unchanged, no credit note; failed-inspection outcomes; a free-relist sale gives no offer.
- Rating: windows (seller at `ready_to_collect`, buyer at `completed`, 30-day close, cancelled), validation, duplicates and idempotency, immutability at database level, suspended customer, the counterparty cannot read, staff with and without `rating.view`, no notification sent, no side effect.
- Isolation/RLS: customer A cannot read B's offer or rating; the forced-RLS suite extended; permission catalogue and `OrderPermissionsTest` extended; idempotency suite extended.
- Dashboard: `npm run type-check`, `lint`, `build`; component tests for the panels and filter. Customer App: `flutter analyze`, `flutter test` (fake backend flows), `flutter build web --release`.
- Backend gates: `composer test`, `./vendor/bin/pint --test`, `composer swagger:generate`, `migrate:fresh --seed` on the dedicated databases, migration rollback.

## Documentation updates
- Postman collection (`postman/Dahab-Backend.postman_collection.json`): the two new customer requests, changed dashboard requests, saved-token scripts; `postman/README.md` if variables are added.
- `#[OA]` attributes on the new/changed controllers and resources, then `composer swagger:generate`.
- `docs/platform/api-contract.md` (new fields, filter, codes).
- Technical Spec, each marked **"Changed by spec 018"**: Part 1 (permission `rating.view`; RLS notes), Part 2 (customer orders, new relist/rating endpoints, dashboard orders, handover, invoices note), Part 3 (§1.3 new working-hours deadline; §2.5 waiver; §3 settlement; §10 afterlife).
- Schema SQL: `00_schema_full.sql`, `04_schema_market.sql`, `05_schema_security.sql` (columns, move, rating table, RLS, guards, reconciliation).
- `docs/features/after-collection.md` (impact analysis, written with this spec); `CLAUDE.md` "Current state" after the implementation.
- Inventory-type tests: `Authorization/PermissionCatalogueTest`, `Order/OrderPermissionsTest`, `Pricing/SettingsCatalogueTest` (if the setting's metadata changes), route/audit-event inventories where they exist.

## Rollout and verification
1. Create `feature/after-collection` from the confirmed base in each repo only when implementation starts; same branch name in all three.
2. Order: Backend (migration → model → Action → FormRequest/Resource/controller + `#[OA]` → Pest → Postman) → API contract → Dashboard → Customer App.
3. Run the gates in *Test requirements* on `dahab_wt018` / `dahab_wt018_dev` only.
4. No backfill: orders completed before the release show no offer.
5. Finance confirms the buyer-invoice-only treatment (FR-021) before production.
6. Report in the Step 5 shape of `CLAUDE.md`. Backend→Dashboard→Customer App compatibility: all API changes are additive; the Dashboard must add `rating.view` to its permission map in the same release.

## Assumptions (every one, so none is hidden)
- **A1** Orders completed before the release get no offer (no backfill), as spec 016 did for invoices.
- **A2** The relist form's price field follows the existing listing rule (gold: making charge per gram; stones: asking price) and the origin price is only a prefill; the owner may change it.
- **A3** Rating eligibility reads the order's states: a dispute against the sale (`cancelled_inspection`) counts as cancelled (no new rating, existing stays); a settled order frozen in `disputed` keeps its original window.
- **A4** A late handover (after `uncollected_expired`) starts the window at that handover.
- **A5** A setting value of 0 means "no offer" (allowed range of the setting stays as built).
- **A6** The estimate wording "the buy/sell difference still applies" is a product copy line to be approved with the Arabic text.
- **A7** Staff reads of ratings and of the offer are not audited (customers' own reads are not either); staff listing/order reads follow the existing audit practice.
- **A8** The media files of the relisted listing share the origin's encrypted objects by reference (no re-upload), consistent with media never being deleted from the private disk.
- **A9** The 30 days are calendar days (Cairo time), not working time, because rating is not an action gated at a branch (Part 3 §1.3 principle).

## Unresolved (none blocks planning)
- (Resolved 2026-10-08) Dashboard and Flutter source were inspected (D18); the plan names real files.
- **Legal / tax**: Terms §5.5 wording for the 0% promise is not lawyer-reviewed; Finance must confirm that no seller invoice is issued for a zero-commission sale (FR-021).
- **Re-verify at implementation start:** the three `main` SHAs above (another merge before branching changes the base).
