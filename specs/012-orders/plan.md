# Implementation Plan: Orders — delivery, inspection, balance, settlement and collection

**Branch**: `feature/orders` (backend and dashboard, both from `feature/buy-requests` 6dab96c / c288c0e; spec 011 is not merged to `main`). The Customer App has no repository. | **Date**: 2026-10-01 | **Spec**: [spec.md](./spec.md)

## Summary

This plan covers the order's whole life after acceptance:

- **Delivery**:
  - Staff at the branch (`order.receive`, branch-scoped) mark the piece received.
  - The seller may cancel. A per-minute sweep cancels orders that miss the reach-branch deadline.
  - Both cases refund the buyer, record a seller cancellation and withdraw the listing.
  - The cancellation threshold (counted since the seller's last reinstatement) suspends the seller automatically, through a sweep pass within a minute (system actor, its own transaction).
- **Levers**: staff change the branch (the clock keeps running, with an optional extension) and extend the reach-branch, balance or collection deadline. Every change is audited.
- **Inspection**: the inspector (`inspection.enter`, branch-scoped, sees no prices) records an immutable result, and the server derives the outcome:
  - **Pass**: the order awaits the balance.
  - **Weight adjust**: the buyer decides on the price recomputed on the measured weight.
  - **Stone regrade**: staff propose a price, then the buyer decides.
  - **Karat or counterfeit**: the buyer is refunded and the seller suspended.
  - A correction is a new superseding result, allowed only before money moves.
- **Balance and settlement**:
  - The buyer pays from the wallet before a 10-day deadline. In one balanced `balance_payment` transaction, escrow passes the price through to the seller's proceeds, commission, VAT and spread.
  - The rates are locked: the buyer's at the request, the seller's at acceptance (new column). Everything is recomputed on the IGI weight, reproducing Part 3 §3.3 and §3.4 to the piastre.
  - A collection code is issued (hashed, plus encrypted for the owner's view).
- **No-pay**: the sweep forfeits the deposit, 50% to the seller and the rest to `dahab_commission`. The piece goes back to the seller with a code; they collect it or relist.
- **Collection**:
  - Staff hand over against the code. No money moves.
  - Five wrong tries lock the order for 15 minutes.
  - Past the window the listing becomes `uncollected_expired`, and a return past its window becomes `seller_unclaimed`.
- **Guarantees in the data**:
  - The order guard has its own SQLSTATE `DH006`.
  - `order_state_change` records every move with a named actor.
  - A deferred money check covers each ending, with one payment and one forfeit per order.
  - Forced RLS covers the new tables, plus a narrow `order` scope for two-customer actions.
- **Apps**:
  - **Dashboard**: the Orders, Inspections and new Buy requests pages go live, with every action in `DModal`.
  - **Customer App**: the buyer's and seller's order life runs on the API.

## Impact analysis

```
Backend:          YES — 1 migration; ~16 Actions (Orders/Customer, Orders/Staff, Orders/Sweeps, Inspections), OrderSettlement + PriceCalculator::lockedBreakdown, StaffBranchScope, CollectionCodes, 1 command (orders:sweep), OrderNotification, 7 Requests, 8 Resources, 4 controllers, ~13 error codes, seeders, factories, tests, Postman
Database:         YES — 8 tables (7 from the schema + order_state_change), "order"/collection/seller_return/customer columns, 2 order transitions, DH006 guard, trg_order_money, trg_order_change_recorded, unique indexes, RLS + `order` scope, suspension reason, 1 backfill
API:              YES — 6 customer endpoints, 12 dashboard endpoints (new); changed: order guard error code, AcceptBuyRequestAction stores locked_seller_unit_rate, ReinstateCustomerAction stamps cancellations_reset_at, the existing seller ListingResource / buyer BuyRequestResource show the new states
Dashboard:        YES — Orders, Inspections, Buy requests pages; types/services/endpoints; 8 permission strings; audit events; error codes; nav
Customer App:     YES — OrdersApi + models; order cards and flows for both roles; fake backend
Auth:             YES (small) — new non-elevated DB scope `order`, audited on every customer write that uses it; no elevation in any customer path; no change to sign-in, tokens or gates
Permissions:      YES — 8 new codes (Part 1 §4.1): order.view, order.receive, inspection.enter, order.price_adjust, order.change_branch, order.extend_deadline, order.handover, buy_request.view
API models/types: YES — Dashboard src/types/order.ts (new), buyRequest.ts (new), staff.ts; Flutter lib/models/order.dart (API models added beside the card blocks)
```

**Classification**: the new endpoints are **non-breaking**. These are **potentially breaking**:

- the order guard's error code (`illegal_buy_request_transition` → `illegal_order_transition`, seen only by the spec 011 staff cancel);
- the new listing and order states that now appear in existing resources (`at_inspection`, `settling`, `sold`, `awaiting_seller_return`, `seller_unclaimed`, `uncollected_expired`) — the Dashboard and Flutter state maps are checked in the tasks;
- the permission union and the audit event list.

Nothing is renamed or removed.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary dependencies**:
  - **Backend**:
    - `PostLedgerEntryAction`, `LedgerEntry`/`LedgerLine`, `Account::forCustomerKind` (spec 008)
    - `DepositLedger` (spec 011)
    - `PriceCalculator`, `PricingContext`, `Settings`, `Money` (spec 005)
    - `WorkingHoursResolver` (spec 004)
    - `MovesListing`, `ListingTransitions`, `HoldListingsOfCustomerAction` (spec 010)
    - `ReleasesRequests`, `AcceptBuyRequestAction`, `CancelAcceptanceAction` (spec 011)
    - `SuspendCustomerAction`/`ReinstateCustomerAction` (spec 007)
    - `RecordAuditLogAction` (spec 006)
    - the `idempotent` middleware; `DatabaseActor` (+`order`), `SystemActor`
    - `BuyRequestNotification` as the pattern for notifications
  - **Dashboard**: TanStack Vue Query, `useIdempotencyKey`, `usePermissions`, `DModal`.
  - **Flutter**: `ApiClient`, `BuyRequestsApi`, `WalletApi`.
- **Storage**: PostgreSQL 16 (tables, deferred constraint triggers, forced RLS) and Redis (queues, idempotency).
- **Testing**:
  - Pest feature tests through HTTP: rows, the ledger lines and balances, the listing and order state with their history, audit, `Notification::fake`, idempotent replay, every refusal.
  - Schema tests: the guard, frozen columns, `inspection_no_update`, the deferred money and history checks, RLS in each scope.
  - The sweeps run by command, with the results read through the API.
  - Concurrency on two connections: receive vs reach sweep, seller cancel vs receive, pay vs no-pay sweep, decision vs decision sweep, two handovers.
  - Part 3 §3.3 and §3.4 to the piastre, plus a randomised zero-residue property.
  - The reconciliation test.
  - Leak tests: no counterparty contact details to customers; no prices or contact details to inspectors.
  - The build tests (`CustomerTableIsolationTest`, `CustomerRouteGateTest`, `ElevationTest` + `order` scope guard, `PermissionCatalogueTest`).
  - Run sequentially on `dahab_wt012`.
- **Performance goals**: pay-balance < 300 ms p95; each sweep pass handles 1,000 due orders in under a minute; `GET /dashboard/orders` < 300 ms with 10,000 orders.
- **Constraints**:
  - bcmath only; a named actor on every move and entry; one transaction per operation with the lock order in R4.
  - Customers identified to each other and in staff lists by `display_ref` only.
  - The inspector never sees money.
  - No first-sale advance, disputes, proxy, invoices.
- **Scale/scope**: 18 new endpoints, 1 command with 6 passes, 3 Dashboard pages, about 6 Customer App flows.

No open NEEDS CLARIFICATION: there are 16 clarifications in the spec; the engineering choices are R1–R24 in [research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Every order move writes `order_state_change` with its actor (deferred check). Listing moves write `listing_state_change`. Every ledger entry names the buyer, a staff member or the system actor. Every staff action is audited in the same transaction. The sweeps run as the system actor. The automatic suspension is its own operation by the system actor (threshold, sweep pass 0) or part of the inspector's own request (karat/fake), so every request has exactly one actor (analysis C2). | ✅ |
| II. Isolation by the engine; staff authz by data | Forced RLS on the 8 new tables (party to the order, or elevated). Two-customer customer writes use the new non-elevated `order` scope with narrow policies (R2), and each writes an audit row naming the customer (`order.seller_cancelled`, `order.decided`, `order.paid`, `order.relisted` — analysis C1), so the cross-customer touch is explicit, documented and audited. Customer reads stay under plain isolation. Column privacy is proven by `OrderLeakTest` and `InspectorLeakTest`. Staff use 8 new catalogue codes. Branch scope comes from the staff member's assigned branch, never a role name. | ✅ — **recorded deviation** below |
| III. Docs first | Updated before the migration: `04_schema_market.sql` §9–§11 (the new tables as built, the order columns, `order_state_change`, the money and history triggers, the code columns, the `seller_return` deadline comment), `05_schema_security.sql` (order transitions, the DH006 guard, RLS and the `order` scope, the listing-guard relist exception), `03_schema_ledger.sql` (unique indexes, `deposit_release_allowed`), `02_schema_identity.sql` (the suspension reason, `cancellations_reset_at`), `00_schema_full.sql`. Also: Technical Spec Part 1 §4.1 and §3.4, Part 2 §5–§7, §11, §12 (including the 422 for a wrong code), Part 3 §1.3, §3, §5, §7, §9.1, §10, §12; `api-contract.md`; `docs/features/orders.md`; CLAUDE.md "Current state". | ✅ |
| IV. Foundation before modules | The migration mirrors the updated schema. Every endpoint has a Request, Resource, Action, Pest tests (happy path and refusals) and `#[OA]`. | ✅ |
| V. Test the boundary and the ledger | Every movement of money is asserted through HTTP (wallets, the order detail's ledger, the settlement figures). The sweeps are run by command and observed through the API. A reconciliation test covers every order. | ✅ |
| Reversible migrations | `down()` refuses once any order has moved beyond acceptance or any new table has rows (append-only ledger ties). Otherwise it drops in reverse. Recorded in the PR note; a second reviewer is required. | ✅ (documented refusal) |

**Recorded deviation (Constitution II)**: the `order` database scope (research R2). A party's action on an order reads the counterparty's request, display reference and listing, and moves the seller's listing (buyer pays or declines), without an elevation. It is limited to orders the caller is a party to, pushed only from `app/Actions/Orders/Customer/*`, never used for a customer's own reads, and **audited**: every write under it records an `order.*` audit row with the customer as actor (analysis C1). No customer path elevates; the threshold suspension is a sweep pass (analysis C2).

**PR note** (paste into the backend PR body): *Constitution Principle II — deviation by design (spec 012 research R2): the non-elevated `order` RLS scope lets a party's order Action read the counterparty's request and display reference and move the listing of an order they are a party to; `OrderScopeTest`, `OrderLeakTest` and `InspectorLeakTest` prove the limits, and every customer write under the scope is audited (`order.seller_cancelled`, `order.decided`, `order.paid`, `order.relisted`). The seller's automatic suspension at the cancellation threshold is a separate sweep pass by the system actor, never part of a customer request. Migration reversal: `down()` refuses once any order has moved past acceptance, because those rows are tied to append-only ledger entries — reviewer 2 please confirm. `docs/` is updated in this PR.*

**Recorded deviations from the schema / Technical Spec** (fixed in the docs in the same change):

- `collection.code_encrypted` and `seller_return.code_encrypted` (the owner sees the code, R10);
- `invalid_collection_code` is 422, not 401 (R10);
- `seller_return.return_deadline` uses calendar weeks (Part 3 §1.3 beats the schema comment);
- the order guard uses `DH006`;
- `inspection_result` gains two inspector flags;
- the reasons on `order_branch_change`/`order_deadline_extension` become NOT NULL;
- `seller_cancellation` gains UNIQUE(order) and `by_sweep`;
- `settlement_decision` gains UNIQUE(inspection);
- the new `order_state_change` table and the order columns;
- the listing-guard exception for relisting with measured figures;
- the settlement posts a negative `dahab_spread` when the locked rates cross (Clarification).

## Project Structure

### Documentation

```text
specs/012-orders/{spec,plan,research,data-model,quickstart}.md, contracts/orders-api.md, checklists/requirements.md, tasks.md (next)
docs/features/orders.md
```

### Source Code

```text
backend
  docs/Database schema/{00,01,02,03,04,05}_*.sql · docs/Technical Spec/part{1,2,3}.md · docs/platform/api-contract.md · docs/features/orders.md · CLAUDE.md
  database/migrations/2026_10_05_000010_create_orders_lifecycle.php
  database/seeders/LocalOrderSeeder.php (+ DatabaseSeeder, local only) · database/factories/{InspectionResultFactory,…}.php
  config/dahab-orders.php
  app/Enums/{OrderEvent,InspectionOutcome,DeadlineKind}.php · OrderState (+helpers) · SuspendedReason (+REPEATED_CANCELLATIONS) · StaffPermission (+8) · AuditEvent (+12)
  app/Models/{OrderStateChange,OrderBranchChange,OrderDeadlineExtension,SellerCancellation,InspectionResult,SettlementDecision,Collection,SellerReturn}.php · Order (+relations, casts) · Customer (+cancellations_reset_at)
  app/Support/DatabaseActor.php (+order scope)
  app/Support/Orders/{OrderTransitions,OrderSettlement,StaffBranchScope,CollectionCodes,OrderCursor,OrderTimeline,DeadlinePolicy}.php
  app/Support/Pricing/PriceCalculator.php (+lockedBreakdown)
  app/Actions/Orders/Concerns/MovesOrder.php
  app/Actions/Orders/Customer/{ListOwnOrdersAction,ShowOwnOrderAction,CancelOrderBySellerAction,DecideAdjustmentAction,PayBalanceAction,RelistReturnedPieceAction}.php
  app/Actions/Orders/Staff/{ListOrdersAction,ShowOrderAction,ReceivePieceAction,ChangeOrderBranchAction,ExtendOrderDeadlineAction,ProposePriceAction,HandoverPieceAction,HandoverReturnedPieceAction}.php
  app/Actions/Orders/{SuspendSellerAction,OpenSellerReturnAction,ForfeitDepositAction,ReleaseOrderDepositAction}.php
  app/Actions/Inspections/{RecordInspectionResultAction,ListInspectionsAction,InspectionWorkListAction}.php
  app/Actions/BuyRequests/ListBuyRequestsForStaffAction.php · AcceptBuyRequestAction (+locked_seller_unit_rate) · Orders/CancelAcceptanceAction (order_state_change) · Customers/ReinstateCustomerAction (+cancellations_reset_at)
  app/Console/Commands/SweepOrders.php · routes/console.php (every minute)
  app/Notifications/OrderNotification.php
  app/Exceptions/DomainApiException.php (+13) · bootstrap/app.php (DH006)
  app/Http/Requests/Customer/Order/{ListOrdersRequest,DecideAdjustmentRequest}.php · Dashboard/Order/{ListOrdersRequest,RecordInspectionResultRequest,ProposePriceRequest,ChangeBranchRequest,ExtendDeadlineRequest,HandoverRequest,ListInspectionsRequest,ListBuyRequestsRequest}.php
  app/Http/Resources/Customer/{CustomerOrderResource}.php · Staff/{StaffOrderResource,StaffOrderDetailResource,InspectionResultResource,WorkListItemResource,StaffBuyRequestResource}.php
  app/Http/Controllers/Api/V1/Customer/OrderController.php · Dashboard/{OrderController (+),InspectionController,BuyRequestController}.php · routes/api.php · CustomerRouteAccess (gates)
  postman/Dahab-Backend.postman_collection.json (folder "Orders": customer + dashboard; "Buy requests" + staff list)
  tests/Feature/Order/{OrderSchemaTest,OrderTransitionGuardTest,CustomerOrdersReadTest,ReceivePieceTest,SellerCancelTest,ReachBranchSweepTest,CancellationSuspensionTest,ChangeBranchTest,ExtendDeadlineTest,InspectionResultTest,InspectionCorrectionTest,ProposePriceTest,AdjustmentDecisionTest,DecisionSweepTest,PayBalanceTest,SettlementMathTest,StoneSettlementTest,ForfeitureSweepTest,SellerReturnTest,HandoverTest,CollectionSweepTest,RemindersTest,StaffOrdersListTest,InspectionsListTest,WorkListTest,StaffBuyRequestsTest,OrderIsolationTest,OrderScopeTest,OrderLeakTest,InspectorLeakTest,OrderConcurrencyTest,OrderReconciliationTest,OrderNotificationTest,OrderPermissionsTest}.php
  tests/Unit/Pricing/LockedBreakdownTest.php
  tests/Feature/BuyRequest/{AcceptBuyRequestTest,CancelAcceptanceTest} (adjusted)
dashboard
  src/api/endpoints.ts · src/types/{order,buyRequest}.ts · src/types/staff.ts (+8) · src/services/{order,inspection,buyRequest}.service.ts · src/services/errors.ts · src/mock/nav.ts (unhide by permission) · src/router/index.ts
  src/pages/{orders,inspections,buy-requests}/index.vue
  src/components/orders/{OrdersTable,OrderDetailDrawer,OrderTimeline,ReceiveModal,ChangeBranchModal,ExtendDeadlineModal,ProposePriceModal,HandoverModal}.vue · components/inspections/{InspectionsTable,WorkList,InspectionResultModal}.vue · components/buy-requests/BuyRequestsTable.vue
  audit category/event labels · listing/order state labels
flutter (no git)
  lib/models/order.dart (+Order API models) · lib/services/api/orders_api.dart (live) · lib/services/repositories.dart · lib/services/app_session.dart
  lib/features/orders/{orders_screen,order_flows,order_detail_screen,pay_balance_screen,inspection_result_screen,collection_code_screen}.dart · lib/features/shared/order_card.dart
  lib/core/i18n (states, errors, en/ar) · test/{test_app,flows_test}.dart (fake /customer/me/orders*)
```

**Structure decision**: the existing layering:

- Every order state change goes through `MovesOrder` (`order_state_change` + guard).
- Every listing move goes through `MovesListing`.
- Money goes only through `DepositLedger` (releases), `OrderSettlement` → `PostLedgerEntryAction` (payment) and `ForfeitDepositAction` (forfeit).
- Prices come only from `PriceCalculator`.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R24)
- [data-model.md](./data-model.md)
- [contracts/orders-api.md](./contracts/orders-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no new violations. The `order` scope is justified below, and the schema deviations are recorded and fixed in `docs/` in the same change.

## Follow-ups (not in this feature)

- The first-sale advance (needs promo codes, Part 3 §6); disputes and freezing (`disputed`); proxy collection; tax invoices and ETA filing (Part 4); the free 0% relist after collection; the manual post-window refund from escrow; market makers.
- Seller-initiated extension requests (the prototype's *Ask for more time*, the design's *Extension requests*).
- Staff approval of inspection messages (design: "Nothing is sent until you approve it").
- IGI file attachments on results (design: certificate PDF, scale photo).
- The Dashboard Overview figures fed by orders (Open orders, held on orders, past deadline).
- *People to watch* (pattern flags, OI-3.4).
- Withdrawals (the seller's proceeds can be spent on buying, not yet withdrawn to a bank).

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `order` database scope + narrow policies | The buyer's payment and decline move the seller's listing; the seller's cancel refunds the buyer (R2) | Elevating to `system` exposes every customer table; reusing `queue` blurs two audited deviations |
| `order_state_change` table + deferred check | Named actor and timeline for every order move (Principle I) | Reading the audit log for history mixes business logic with the audit trail; customer moves are not audited |
| `locked_seller_unit_rate` on `"order"` | The seller's figure must be fixed at acceptance (Clarification), and settlement must never read a live price | Recomputing from the live price at payment makes the seller's proceeds drift and the spread unbounded |
| `code_encrypted` beside `code_hash` | The buyer and the seller see their code in the app (Clarification) | Hash only means the code is SMS-only and lost if the SMS is lost |
| Deferred `trg_order_money` + unique indexes | Each ending has exactly its money, on every code path | An Action-only rule has no backstop |
| `DH006` for the order guard | Order and request guard errors must be distinguishable | Keeping `DH005` answers `illegal_buy_request_transition` for order moves |
| Listing-guard exception for relisting | A relisted piece must carry the IGI-measured figures (Clarification) | A new listing would lose the seller's photos and history |
| One sweep command with six passes | One schedule entry, one overlap lock, shared due-id pattern | Six commands multiply the schedule and the overlap locks for the same pattern |
