# Implementation Plan: After collection — free relist and rating

**Branch**: `feature/after-collection` in all three repositories (to be created from `main`: Backend `9cb3fe1`, Dashboard `f73065f`, Flutter `88539b6`; the SHAs were verified on 2026-10-08) | **Date**: 2026-10-08 | **Spec**: [spec.md](spec.md)

**Status**: Plan only. Nothing is implemented, no branch exists, nothing is committed.

## Summary

- **Free relist.** `HandoverPieceAction` stores `collection.free_relist_until` (handover instant + `deadline.free_relist_working_hours` working hours on the order's branch, via `WorkingHoursResolver`). A new customer action `FreeRelistAction` creates a **new** `listing` (`relisted_from_order_id` → origin order), owned by the buyer, copied from the origin (IGI-measured karat/weight, public media, branch options), moved `draft → live` through one new, guarded transition. The link **is** the waiver: settlement builds the `Piece` with `commissionWaived = true`, so commission, VAT and the minimum are 0 while the spread is untouched; `IssueTaxInvoices` skips the seller invoice when the order's commission is 0.
- **Rating.** One immutable `order_rating` row per (order, party). Customer write/read endpoints under the order; staff read only with the new `rating.view`.
- **Reads.** `free_relist` and `rating` blocks on the customer order; `free_relist` on the staff order plus a list filter; ratings block gated by `rating.view`; customer-file history events.
- **Notifications.** Through the spec 017 pipeline (SMS + email + inbox): one confirmation on relist; the buyer's `COLLECTED` notice carries the window end.
- **Dashboard / Customer App.** Panels and filter on existing pages (no new section); live offer, relist form, countdown and rating screen replace the mock ones.

## Impact analysis

```
Backend:          YES — 1 migration; Actions Orders/Customer/{FreeRelistAction, RateOrderAction}, Support/Orders/FreeRelistOffer; edits to HandoverPieceAction, ListingPricer/OrderSettlement (waiver), IssueTaxInvoices (skip -S), CustomerOrderResource, StaffOrderResource, ListOrdersAction, ShowOrderAction, ListingResource, OrderNotification (window text), AuditEvent, StaffPermission, Listing/Order/Collection models
Database:         YES — collection.free_relist_until; listing.relisted_from_order_id (+unique, guard); listing_transition draft→live; order_rating (+guard, forced RLS); waived⇒no seller invoice check; permission seed
API:              YES — 2 customer POSTs, additive fields on customer + staff orders, 1 list filter; no enum value added; no field removed or renamed
Dashboard:        YES — order detail panels, Orders filter, Listings line, Customer file events, permission string rating.view
Customer App:     YES — order screen offer + countdown + relist form, RateScreen live, mock removal, EN/AR, fake backend + tests
Auth:             NO — no new guard; relist uses the trade gate, rating the verified gate
Permissions:      YES — rating.view (CEO, COO)
API models/types: YES — Dashboard src/types/order.ts, staff.ts; Flutter lib/models/order.dart (+listing link)
```

**Classification**: non-breaking (new endpoints, optional fields, optional filter). Potentially breaking: the new permission string for exhaustive permission maps (Dashboard adds it in the same release).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 12 (Backend); Vue 3 + TypeScript, Vuetify 4, Pinia, TanStack Vue Query, Vite 8 (Dashboard); Dart ^3.11, Flutter Web, go_router, provider, http (Customer App).
**Primary Dependencies**: Sanctum, Spatie Permission (staff codes via `StaffPermission`), Horizon, l5-swagger, Pest 3; existing `WorkingHoursResolver`, `PriceCalculator`, `IssueTaxInvoices`, notification pipeline with `InboxNotification`.
**Storage**: PostgreSQL 16 with forced RLS and `DatabaseActor` scopes; tests and development on **`dahab_wt018` / `dahab_wt018_dev` only** (owned by `dahab`).
**Testing**: Pest feature tests through the HTTP boundary (Backend); `npm run type-check`, `lint`, `build` (Dashboard); `flutter analyze`, `flutter test`, `flutter build web --release` (Customer App).
**Project type**: three repositories, one platform (API + staff web + customer web).
**Performance Goals**: relist is one transaction with a bounded number of rows (≤ 1 listing, ≤ 8 media rows, ≤ 5 branch rows, 3 history/audit rows); order reads add no query beyond the already loaded `collection` and one existence check for the relisted listing.
**Constraints**: idempotent POSTs; lock order listing → order → collection; no weakening of any RLS policy; no new enum value in an existing response enum; no `commission_waived` flag column duplicating the link.
**Scale/Scope**: one extra row per collected order; ratings ≤ 2 per order.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I Named actor on every state change | Relist = customer actor (buyer); rating = customer actor; the window is stored by the staff handover (staff actor); no system write is added | PASS |
| II Customer isolation by the engine; staff authorization by permission data | `order_rating` forced RLS (author reads own; staff through the permission-gated path); relist runs in the existing non-elevated `order` scope; new code `rating.view` is permission data seeded to CEO + COO and editable | PASS |
| III Docs are the source of truth | Same change set updates the Technical Spec (Parts 1–3, each "Changed by spec 018"), schema SQL, `api-contract.md`, `docs/features/after-collection.md`, `CLAUDE.md` current state (task group 8) | PASS (gated by tasks) |
| IV Foundation before modules | Builds on specs 010–017 only; migration mirrors the schema docs; Action + Pest happy and refusal path + `#[OA]` per endpoint | PASS |
| V Test the boundary and the ledger | Every state-changing path (handover offer, relist, waiver settlement, invoice skip, rating) has an HTTP-boundary test; settlement figures are asserted against ledger rows | PASS |

Post-design re-check (after `data-model.md` and `contracts/`): unchanged, **PASS**. No waiver requested.

## Project Structure

### Documentation (this feature)

```text
specs/018-after-collection/
├── spec.md          # final specification (clarified)
├── plan.md          # this file
├── research.md      # decisions R1–R16
├── data-model.md    # tables, columns, guards, RLS
├── quickstart.md    # validation scenarios
├── contracts/after-collection-api.md
├── checklists/requirements.md
└── tasks.md         # /speckit-tasks
```

### Source code — three repositories

```text
dahab-backend/
├── database/migrations/2026_10_11_000010_after_collection.php          # new
├── app/Actions/Orders/Customer/{FreeRelistAction,RateOrderAction}.php  # new
├── app/Actions/Orders/Staff/HandoverPieceAction.php                    # store window, audit context, notice text
├── app/Support/Orders/{FreeRelistOffer,DeadlinePolicy}.php             # offer status; window end via resolver
├── app/Support/Listings/ListingPricer.php, app/Support/Orders/OrderSettlement.php   # commissionWaived from the link
├── app/Support/Invoices/IssueTaxInvoices.php                           # skip -S when commission = 0
├── app/Http/Controllers/Api/V1/Customer/OrderController.php            # 2 endpoints + #[OA]
├── app/Http/Requests/Customer/Orders/{FreeRelistRequest,RateOrderRequest}.php
├── app/Http/Resources/Customer/{CustomerOrderResource,ListingResource}.php, Staff/{StaffOrderResource,ListingResource}.php
├── app/Actions/Orders/Staff/{ListOrdersAction,ShowOrderAction}.php     # filter, panels, ratings by permission
├── app/Actions/Customers/ListCustomerActivityAction.php                # relist (+ rating for rating.view)
├── app/Models/{OrderRating,Listing,Collection}.php, app/Enums/{AuditEvent,StaffPermission,OrderEvent,RatingPartyRole?}.php
├── app/Notifications/OrderNotification.php, app/Enums/OrderEvent.php   # FREE_RELISTED, window text on COLLECTED
├── app/Exceptions/DomainApiException.php                               # free_relist_expired, already_relisted, rating_closed, already_rated, rating_not_available
├── routes/api.php, postman/*, docs/…                                   # routes, collection, docs
└── tests/Feature/{FreeRelist,Rating}/…, tests/Support/FreeRelists.php, existing Order/Invoices/Authorization/Isolation/Idempotency tests extended

dahab-dashboard/
├── src/types/{order.ts,staff.ts}, src/api/endpoints.ts, src/services/order.service.ts, src/composables/useOrders.ts
├── src/components/orders/{OrderDetailPanel.vue,OrdersTable.vue,OrderTimeline.vue} + new FreeRelistPanel.vue, OrderRatingsPanel.vue
├── src/pages/orders/index.vue (filter), src/pages/listings/index.vue + src/components/listings/ListingHistory.vue (line)
├── src/pages/customers/[id].vue (history events), src/mock/nav.ts only if a mock lists permissions
└── i18n files used by the Dashboard (verify the folder in T-D01)

dahab-flutter/
├── lib/models/order.dart (+FreeRelistOffer, OrderRating), lib/services/api/orders_api.dart, lib/services/repositories.dart, lib/services/mock_repositories.dart
├── lib/features/orders/{order_screen.dart,order_flows.dart}  # live offer card + relist form; RateScreen live
├── lib/services/app_session.dart                              # delete the mock relist timer
├── lib/widgets/mock_flag.dart (R.rate leaves mockScreens), lib/routing/{routes.dart,app_router.dart}
├── lib/core/i18n/i18n.dart (EN/AR), test/{flows_test.dart,mock_flags_test.dart,routes_smoke_test.dart} + new free_relist_test.dart
```

**Structure decision**: extend the existing order surfaces; no new Dashboard section, no new Flutter feature folder.

## Phases and order

1. **Backend foundation** (migration → models → enums/permission/audit/notification → offer support).
2. **US1 offer at handover** → **US2 relist** → **US3 waiver at settlement and invoice skip** (Backend, each with tests).
3. **US4 rating** (Backend).
4. **US5 staff reads** (Backend) → Postman, `#[OA]`, `composer swagger:generate`, API contract, Technical Spec, schema docs.
5. **Dashboard** (types → services → composables → components → i18n/permission).
6. **Customer App** (models → services → controllers/state → screens → mock removal → i18n → tests).
7. **Verify** with the gates in the spec on `dahab_wt018` / `dahab_wt018_dev`; Step 5 report.

Backend and the two clients are sequential per the platform workflow; within the Backend, US3 depends on US2, US4 is independent after the foundation.

## Risks and watch-points

- **`listing_guard` change** (draft → live) is the most sensitive edit: it must allow the move only for a listing whose `relisted_from_order_id` is set and valid; a test must prove an ordinary draft still cannot go live.
- **RLS reads in the `order` scope.** The buyer reads the origin listing and its **public** media (the `order` scope policy already excludes private media, which is how the seller's invoice is never copied); the new rows are inserted under `listing_isolation`. A test asserts both.
- **Reconciliation.** `tax_invoice_reconciled` checks only existing invoices; the new "waived ⇒ no seller invoice" check is separate so ordinary orders keep both invoices.
- **Notification text** for the buyer's `COLLECTED` notice must stay an in-app, SMS and email message with a masked inbox copy (spec 017 rule); the window time is not a secret.
- **Ratings in the customer-file history** must not leak to staff without `rating.view` (the audit presenter filters by permission).
- **Dashboard permission map** must add `rating.view` in the same release, or the UI hides a permission the Backend sends.
