# Implementation Plan: Buy requests — the queue, the deposit hold and the seller's answer

**Branch**: `feature/buy-requests` (backend + dashboard; the Customer App has no repository). Spec work is on `claude/buy-requests-spec-011-969c24`. | **Date**: 2026-09-30 | **Spec**: [spec.md](./spec.md)

## Summary

The second half of the marketplace's front door: a buyer asks for a live piece, puts down a deposit and waits in line;
the seller answers the first in line.

- **Send** — a verified, non-suspended buyer sends a request on a live/reserved listing that is not theirs, confirming
  the price they saw (within `buyrequest.price_tolerance_pct`, else `price_moved`) and accepting the deposit terms
  (`deposit_agreement` v1). One transaction: lock the listing, take the next position from `listing_queue_seq`, hold
  `deposit.buyer_pct` of the locked price through the money service (`deposit_hold`), insert the request, record the
  acceptance, move the listing `live → reserved` on the first request.
- **Follow / leave** — the buyer lists their requests (place in line, locked price, deposit, deadline, order once
  accepted) and leaves a queued one (`deposit_release`), optionally asking to be told when the piece is free again.
- **Answer** — the seller reads the queue (buyer display refs only), **accepts the head** choosing an enabled branch
  option — the `"order"` row is created with `order_ref`, locked price and the reach-branch deadline from the spec 004
  resolver; every other queued request is released and refunded; listing `reserved → accepted` — or **declines the
  head** (refund; next moves up; empty → live).
- **Nobody's money stranded** — a per-minute sweep expires requests past `seller_reply_deadline`; staff take-down and
  seller withdrawal now work from `reserved` (queue released); suspending a seller holds their reserved listings and
  releases the queues; a suspended buyer cannot be accepted; staff with the new `order.cancel` cancel an acceptance
  (order `cancelled_staff`, deposit refunded, listing relisted or withdrawn) — the only exit for held money until the
  orders spec.
- **Guarantees in the data** — `buy_request_transition` + guard (DH005), a deferred money check (every request has its
  hold; every release its refund), a deferred listing/queue consistency check, forced RLS on `buy_request` and
  `"order"`, a narrow `queue` database scope for the two-customer operations.
- **Apps** — Dashboard: read-only queue and order in the listing review panel, *Reserved* / *Accepted* chips,
  take-down from reserved, *Cancel acceptance* on the order box, the new setting and permission. Customer App: piece page, send, *Request sent*, *You need a little more*,
  leave, my requests, the seller's *Decide today* with Accept (branch pick) / Decline.

## Impact analysis

```
Backend:          YES — 1 migration, 3 enums (+ extended SettingKey; AuditEvent and LedgerEventKind unchanged), 2 models, ~9 Actions, Requests, Resources, 2 controllers (+2 changed), 1 notification, 1 job, 1 command, DB scope, 13 error codes, throttle, seeders, factories, tests, Postman
Database:         YES — order_state type, buy_request, "order", order_ref_seq, buy_request_transition, order_transition, 5 triggers, RLS policies, ledger FKs, 3 new columns (011), 1 setting, 1 legal document
API:              YES — 4 buyer + 3 seller customer endpoints + 1 dashboard endpoint (order cancel); changed: seller withdraw, seller ListingResource (+order), market detail (+deposit_amount), dashboard listing show (+queue, +order) and index (states reserved/accepted, counts), takedown, suspend
Dashboard:        YES — listing review panel queue/order, cancel-acceptance modal, chips, take-down modal text, types/services, permission string, audit category, rates page setting, error codes
Customer App:     YES — models, BuyRequestsApi, piece page, buy screens, orders (buying/selling cards), fake backend
Auth:             YES (small) — a new non-elevated DB scope `queue`; no change to sign-in, tokens or gates
Permissions:      YES — new order.cancel (ceo, coo, operations; Part 1 §4.1 "Cancel an order"); existing listing.* for reads and take-down; customer gates trade/verified
API models/types: YES — Dashboard src/types/listing.ts (+queue, order, states); Flutter lib/models/buy_request.dart, listing.dart, piece.dart
```

**Classification**: new endpoints and optional fields are **non-breaking**. **Potentially breaking** for the
Dashboard: the permission union (+`order.cancel`) and the audit category/event lists (+`orders`, +`order.cancelled`); `meta.counts` and state chips gain `reserved` / `accepted` (the `ListingState` type is already open-ended);
`can_take_down` becomes true for reserved listings. For both apps: the listing states `reserved` / `accepted` now occur
on the seller's own listings and `reserved` on the market (Flutter maps unknown states; checked in tasks).
Take-down/withdraw/suspend keep their request/response shapes and gain side effects.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary Dependencies**:
  - Backend: `PostLedgerEntryAction` + `LedgerEntry` / `LedgerLine` / `Account::forCustomerKind` (spec 008);
    `ListingPricer` / `ListingQuote` over `PriceCalculator` and `Settings` (specs 005, 010); `WorkingHoursResolver`
    (spec 004); `MovesListing`, `ListingTransitions`, `HoldListingsOfCustomerAction`, `DecideListingAction`,
    `WithdrawListingAction`, `LegalDocument::current` (spec 010); `SuspendCustomerAction` / `ReinstateCustomerAction`
    (spec 007); `idempotent` middleware; `RecordAuditLogAction` (spec 006); `DatabaseActor` (+`queue`), `SystemActor`;
    `SmsChannel`, `ListingDecisionNotification` as the pattern.
  - Dashboard: TanStack Vue Query, `useIdempotencyKey`, `usePermissions`, `DModal`.
  - Flutter: `ApiClient` (idempotency header), `ListingsApi`, `MarketApi`, `WalletApi`.
- **Storage**: PostgreSQL 16 (types, triggers, deferred constraint triggers, forced RLS, a sequence). Redis for queue,
  throttles, idempotency.
- **Testing**: Pest feature tests through HTTP (rows, ledger lines and balances, listing state and history, order,
  audit, `Notification::fake`, idempotent replay, every refusal); schema tests (guard, frozen columns, deferred money
  and consistency checks, RLS in each scope); concurrency tests on two connections (50 joins; accept vs leave; accept vs
  sweep; take-down vs join); a reconciliation test; a leak test (no buyer name/phone/email to the seller or on the
  market); the build tests (`CustomerTableIsolationTest`, `CustomerRouteGateTest`, `ElevationTest` + queue scope
  guard, `PermissionCatalogueTest` unchanged).
- **Performance Goals**: send < 300 ms p95 with 50 queued requests on the listing; sweep handles 1,000 due requests in
  one run under a minute.
- **Constraints**: bcmath only; named actor on every move and entry; one transaction per operation with the lock order
  in research R4; buyers never identified beyond `display_ref`; no order life after acceptance.
- **Scale/Scope**: 7 new endpoints, 6 changed; 1 command; 1 Dashboard panel; ~5 Customer App screens.

No open NEEDS CLARIFICATION: 13 clarifications are in the spec; engineering choices are R1–R21 in
[research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Every request move is made by a customer, a staff member or the system actor; every listing move writes a `listing_state_change` row with its actor (deferred check); every ledger entry names its actor (`ledger_txn_has_actor`); staff take-down / suspension write `audit_log` in the same transaction | ✅ |
| II. Isolation by engine; staff authz by data | Forced RLS on `buy_request` (buyer) and `"order"` (seller or buyer). Two-customer operations use the new non-elevated `queue` scope, pushed only by the buy-request Actions, with narrow policies (R2); a customer's own reads stay under plain isolation; columns shaped by Resources and a leak test. Staff: existing `listing.*` codes and the new `order.cancel`, no PG grants | ✅ — **recorded deviation** below, with the PR note |
| III. Docs first | Updated before the migration: `04_schema_market.sql` §8 (`buy_request` additions, `trg_sync_queue` count-only, guard, money and consistency triggers) and §9 (as-built note), `05_schema_security.sql` (RLS block for buy_request / order / queue scope), `03_schema_ledger.sql` (FKs, deferrable), `01_schema_core.sql` (new setting), `02_schema_identity.sql` (deposit_agreement seed), `00_schema_full.sql`; Technical Spec Part 1 §5.1, Part 2 §2–§5, §10 suspend, §11, §12, Part 3 §4, §9.2, §12; `api-contract.md`; `docs/features/buy-requests.md`; CLAUDE.md "Current state" | ✅ |
| IV. Foundation before modules | Migration mirrors the updated schema; Requests, Resources, Actions, Pest (happy + refusal) and `#[OA]` on every endpoint. Only the `"order"` row is created — no order endpoints or later tables | ✅ |
| V. Test the boundary and the ledger | Every hold, release, request and listing move and every notification asserted through HTTP. The expiry sweep has no HTTP entry point: its test runs the command and then asserts every effect through the API (`GET /customer/me/buy-requests/{id}`, `GET /customer/me/wallet`, the market, the seller's listing) — the boundary is still where the effects are observed (analysis C2) | ✅ |
| Reversible migrations | `down()` drops in reverse on an empty `buy_request`; refuses while requests exist (R20). The Constitution requires an explicit PR note and a second engineer's review for a reversal that is intentionally impossible — the PR note below; T057 asks for the reviewer | ✅ (documented refusal) |

**Recorded deviation (Constitution II)** — the `queue` database scope (research R2): a customer's queue operation
reads other customers' rows (a listing's requests for counting and place in line; the head buyer's `display_ref` and
suspension flag; the seller's listing and its public media) without an elevation. It is narrower than any elevation:
updates are limited to the buyer's own request or the requests on the caller's own listing and to the live ↔ reserved
flip; a customer's own reads never use it (they stay under plain isolation); it is pushed only by the buy-request
Actions and the seller's withdrawal from reserved (a build test); column privacy is proven by `BuyRequestLeakTest`.

**PR note** (paste into the backend PR body): *Constitution Principle II — deviation by design (spec 011
research R2): the non-elevated `queue` RLS scope lets the buy-request Actions read other customers' queue rows and
move the requests on a listing its seller owns. Customer reads of their own requests stay under plain row isolation;
`QueueScopeTest` and `BuyRequestLeakTest` prove the limits. Migration reversal: `down()` refuses while any
`buy_request` exists, because the requests are tied to append-only ledger entries — reviewer 2 please confirm.
`docs/` is updated in this PR.*

**Recorded deviations from the schema docs (fixed in docs in the same change)**: `trg_sync_queue` no longer flips the
listing state (R3); `buy_request.locked_unit_rate` nullable for pure diamond (R5); new columns
`deposit_acceptance_id`, `free_notified_at`; `lt_request_fk` deferrable; new order state `cancelled_staff` with its
columns, two listing moves out of `accepted` (R22); `listing_change_recorded()` reads under a scope it sets itself (H1).

## Project Structure

### Documentation

```text
specs/011-buy-requests/{spec,plan,research,data-model,quickstart}.md, contracts/buy-requests-api.md, checklists/requirements.md, tasks.md (next)
docs/features/buy-requests.md
```

### Source Code

```text
backend
  docs/Database schema/{00_schema_full,01_schema_core,02_schema_identity,03_schema_ledger,04_schema_market,05_schema_security}.sql
  docs/Technical Spec/{dahab-spec-part1-auth,dahab-spec-part2-api,dahab-spec-part3-logic}.md · docs/platform/api-contract.md · docs/features/buy-requests.md · CLAUDE.md
  database/migrations/2026_10_04_000010_create_buy_requests.php
  database/seeders/LocalBuyRequestSeeder.php (+ DatabaseSeeder, local only) · database/factories/{BuyRequestFactory,OrderFactory}.php
  config/dahab-buy-requests.php (throttle) · AppServiceProvider (throttle customer.buy_requests)
  app/Enums/{BuyRequestState,OrderState,BuyRequestEvent}.php · SettingKey (+BUYREQUEST_PRICE_TOLERANCE_PCT) · StaffPermission (+ORDER_CANCEL) · AuditCategory (+ORDERS) · AuditEvent (+ORDER_CANCELLED)
  app/Models/{BuyRequest,Order}.php · Listing (+buyRequests(), queuedRequests(), order()) · Customer (+buyRequests()) · ListingStateChange (+notes)
  app/Support/DatabaseActor.php (+queue scope, DatabaseActor::queue())
  app/Support/BuyRequests/{BuyRequestTransitions,DepositLedger,PlaceInLine,OrderReference,BuyRequestCursor}.php
  app/Actions/BuyRequests/Concerns/ReleasesRequests.php
  app/Actions/BuyRequests/{SendBuyRequestAction,ListOwnBuyRequestsAction,LeaveQueueAction,
                           ShowQueueAction,AcceptBuyRequestAction,DeclineBuyRequestAction,
                           ReleaseQueueAction,ExpireBuyRequestAction,NotifyWhenFreeAction}.php
  app/Actions/Orders/CancelAcceptanceAction.php · app/Http/Requests/Dashboard/Order/CancelOrderRequest.php · app/Http/Controllers/Api/V1/Dashboard/OrderController.php
  app/Actions/Listings/{WithdrawListingAction,DecideListingAction,HoldListingsOfCustomerAction,ListListingsForReviewAction}.php (reserved paths, counts)
  app/Console/Commands/ExpireBuyRequests.php · routes/console.php (every minute)
  app/Jobs/NotifyWhenFreeJob.php · app/Notifications/BuyRequestNotification.php
  app/Exceptions/DomainApiException.php (+13) · bootstrap/app.php (DH005 → illegal_buy_request_transition)
  app/Http/Requests/Customer/BuyRequest/{SendBuyRequestRequest,LeaveQueueRequest,ListBuyRequestsRequest,AcceptBuyRequestRequest,DeclineBuyRequestRequest}.php
  app/Http/Resources/Customer/{BuyRequestResource,SellerQueueItemResource,OrderSummaryResource}.php · Customer/ListingResource (+order) · Market/MarketListingDetailResource (+deposit_amount) · Staff/ListingResource (+queue, +order, can_take_down)
  app/Http/Controllers/Api/V1/Customer/{BuyRequestController,ListingQueueController}.php · routes/api.php · CustomerRouteAccess (gates)
  postman/Dahab-Backend.postman_collection.json (folder "Buy requests"; seller queue/accept/decline under "Listings")
  tests/Feature/BuyRequest/{BuyRequestSchemaTest,SendBuyRequestTest,SendBuyRequestRefusalsTest,OwnBuyRequestsTest,LeaveQueueTest,
                            SellerQueueTest,AcceptBuyRequestTest,DeclineBuyRequestTest,ExpireBuyRequestsTest,NotifyWhenFreeTest,
                            ReservedTakeDownTest,SuspensionQueueTest,BuyRequestIsolationTest,QueueScopeTest,BuyRequestLeakTest,
                            BuyRequestConcurrencyTest,DepositReconciliationTest,BuyRequestNotificationTest,CancelAcceptanceTest}.php
  tests/Feature/Listing/* and tests/Feature/Market/* (adjusted: reserved paths, deposit_amount)
dashboard
  src/api/endpoints.ts · src/types/listing.ts (+queue, order, states) · src/services/listing.service.ts (map) · src/services/errors.ts
  src/components/listings/{ListingQueuePanel,ListingOrderBox,CancelAcceptanceModal}.vue · src/types/staff.ts (+order.cancel) · audit category label · ListingReviewPanel.vue · ListingDecisionModal.vue (take-down text) · listingFormat.ts (states)
  src/pages/listings/index.vue (chips Reserved, Accepted) · src/pages/rates/index.vue (new setting)
flutter (no git)
  lib/models/{buy_request,listing,piece}.dart · lib/services/api/buy_requests_api.dart · lib/services/repositories.dart · lib/services/mock_repositories.dart · lib/services/app_session.dart
  lib/features/catalog/{detail_screen,buy_screens}.dart · lib/features/orders/{orders_screen,order_flows}.dart · lib/features/shared/order_card.dart
  lib/core/i18n (states, errors, en/ar) · test/{test_app,flows_test}.dart (fake /customer/me/buy-requests, queue, accept, decline)
```

**Structure Decision**: the existing layering. `app/Actions/BuyRequests` holds every request writer and reader; request
state changes go only through `ReleasesRequests` / the accept Action with `BuyRequestTransitions`; listing moves only
through `MovesListing`; money only through `DepositLedger` → `PostLedgerEntryAction`.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R21)
- [data-model.md](./data-model.md)
- [contracts/buy-requests-api.md](./contracts/buy-requests-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no new violations; the `queue` scope is justified below; the schema deviations are recorded and
fixed in `docs/` in the same change.

## Follow-ups (not in this feature)

- **Orders spec**: order endpoints, delivery sweep (reach-branch), seller cancel + suspension count, admin branch change
  and extensions, IGI receive, balance payment, forfeiture, settlement. Until then the only exit for an accepted
  order is the staff cancellation (R22) — the orders spec should come next.
- Category controls (`category_paused` / `category_stopped`), market makers (aged-listing gate on requests).
- Dashboard: an Orders / Buy requests page, the customer file's Listings panel, *Chase*.
- Changing the making charge on a live listing with no open request (prototype *Change fee*).
- In-app notifications inbox.

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `queue` database scope + 8 narrow policies | A join is the buyer's but moves the seller's listing; an accept is the seller's but moves other buyers' requests and money; the recount must see every request (R2) | Elevating to `system` exposes every customer table and drops the customer actor; `SECURITY DEFINER` doesn't bypass `FORCE RLS`; a denormalised seller column makes the policies recurse |
| `trg_sync_queue` counts only; deferred consistency trigger | Keep `MovesListing` the single writer of listing state with a named actor (R3) | The schema's flipping trigger leaves the history row and actor implicit |
| Deferred money trigger on `buy_request` | FR-022 must hold for every code path, not only the Actions | An Action-only rule has no backstop |
| Deferrable `lt_request_fk` | The hold is posted before the request row exists so the request can store `deposit_hold_txn_id` NOT NULL | Nullable txn id + a later update would need to unfreeze a locked column |
| `order` table without its life | Acceptance must record branch, reference and deadline (Clarification Q1) | A bare "accepted" flag would lose the branch and deadline the seller must act on |
| Migration `down()` refuses with data | Requests are tied to append-only ledger entries | Dropping them orphans held money |
| New order state `cancelled_staff` + two listing moves | A staff exit for held money before the orders spec (analysis H3, R22) without mislabelling it a seller cancellation | Reusing `cancelled_seller` would count toward seller suspension later |
| Deferred triggers that set their own read scope | A deferred check must see the rows it checks whatever scope is active at commit (analysis H1) | Relying on the caller's scope at commit fails whenever one customer moves another's listing |
