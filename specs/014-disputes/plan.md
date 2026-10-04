# Implementation Plan: Disputes and freeze, proxy collection, the seller's request for more time

**Branch**: `feature/disputes` (backend from `main` 113815e; dashboard `feature/disputes` from `main` c1ed6fc in a dashboard worktree when implementation starts; the Customer App has no repository) | **Date**: 2026-10-03 | **Spec**: [spec.md](./spec.md)

## Summary

- **Disputes**: the buyer or the seller reports a problem on an order at inspection, waiting on the price decision, awaiting the balance or ready to collect (reason, text, 0–5 photos). The order freezes into `disputed` at once — every sweep pass ignores it (they select by state), every other order action answers `order_frozen`. Each party raises at most one dispute per order. Staff with `dispute.handle` work a queue (*Disputes and reports*), pass a case to a named colleague, and resolve it with a mandatory reply: **resume** (back to the frozen-from state, every running deadline pushed by exactly the frozen time, recorded as extension rows) or, **before payment only**, **against the sale** (`cancelled_inspection`, the deposit refunded in full, the piece returned — the inspection-cancel path; needs `order.refund`). A resolution may pay compensation to either party (`compensation` ledger kind, `compensation.pay` with the per-payment and per-day caps unless `compensation.uncapped`) and may suspend the seller (`customer.suspend`).
- **Proxy collection**: on a ready-to-collect order the buyer names one person (name, phone, ID photo, the authorisation accepted). The proxy gets one SMS without the code; the counter handover with `collector=proxy` requires the ID check (`proxy_details_missing` otherwise) and records it.
- **More time**: the seller of an order awaiting delivery sends a reason and a line; staff (`order.extend_deadline`) accept with 6/12/24/48 working hours through the spec 012 extend, or refuse; a request lapses when the order moves on. Both sides are told.
- **Guarantees in the data**: `dispute` + `dispute_transition` (DH009), `order_extension_request` + `extension_request_transition` (DH010), append-only `dispute_change` / `dispute_photo` / `compensation`, unique "one per party" and "one unresolved" indexes, the extended `collection` proxy CHECKs, forced RLS on five tables, deferred checks tying compensation to its entry.
- **Apps**: the Dashboard *Disputes and reports* page, the dispute / extension request / proxy in the order detail, *More time requested* and *Extension requests* in Orders, the handover's proxy check; the Customer App's Report a problem, the dispute status and reply on the order, Someone else collects, Ask for more time — EN/AR, live API, fake backend and flow tests.

## Impact analysis

```
Backend:          YES — 1 migration; ~14 Actions (Disputes/Customer, Disputes/Staff, Orders/Customer proxy + extension, Orders/Staff extension answer), changes to 13 order Actions (assertNotFrozen) and the handover, ~9 Requests, ~4 Resources (+2 extended), 3 controllers (+2 extended), 7 error codes, 6 OrderEvents, ~10 audit events, seeder, factories, tests, Postman
Database:         YES — dispute (extended per docs), 5 new tables (dispute_photo, dispute_change, compensation, order_extension_request, 2 transition lookups), collection + order_deadline_extension columns/CHECKs, 3 order_transition rows, DH009/DH010 guards, deferred checks, forced RLS ×5, 1 legal document, 1 sequence
API:              YES — 6 customer endpoints (+2 upload purposes), 9 dashboard endpoints; additive fields on customer/staff order list + detail and the customer file; handover body extended (defaults unchanged)
Dashboard:        YES — Disputes page (queue + detail + pass-on + resolve), order detail panels, extension requests in Orders, handover modal proxy check; types/services/endpoints/permissions/errors
Customer App:     YES — Report a problem, dispute status/reply on the order, Someone else collects, Ask for more time, `disputed` state everywhere; models/API/i18n; fake backend + flow tests
Auth:             NO — no change to sign-in, tokens or gates (existing `verified` / `trade` gates reused)
Permissions:      YES — 4 new codes: dispute.handle (CEO, COO, Operations, Finance), order.refund + compensation.pay (CEO, Finance), compensation.uncapped (CEO only)
API models/types: YES — Dashboard src/types/{dispute,order,staff}.ts; Flutter lib/models/{order,dispute}.dart
```

**Classification**: new endpoints and fields are **non-breaking**; the handover body keeps spec 012's behaviour by default. **Potentially breaking**: orders can now be in `disputed` (both apps map states — updated here), new `OrderEvent` / audit values, the permission union (+4), the upload purpose enum (+2). Nothing renamed or removed.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary dependencies**:
  - **Backend**: `MovesOrder`, `TellsOrderParties`, `OrderTransitions`, `ReleaseOrderDepositAction`, `OpenSellerReturnAction`, `ExtendOrderDeadlineAction`, `HandoverPieceAction`, `DeadlinePolicy`, `WorkingHoursResolver` (spec 004/012); `PostLedgerEntryAction`, `Account` (spec 008); `SuspendCustomerAction` (spec 007); `Settings` (`compensation.cap_*`); `LegalDocument` + `agreement_acceptance` (spec 010); `CreateCustomerUploadAction` / `UploadTokenStore` / `IdentityDocumentStorage`; `RecordAuditLogAction`; `idempotent` middleware; `DatabaseActor::order`; `SystemActor`; on-demand SMS (`Notification::route('sms', …)`).
  - **Dashboard**: TanStack Vue Query, `useIdempotencyKey`, `usePermissions`, `DModal`, the spec 012 order components.
  - **Flutter**: `ApiClient`, `ApiOrdersRepository`, the uploads API, the reference API (legal documents), go_router.
- **Storage**: PostgreSQL 16 (tables, guards, deferred checks, forced RLS); Redis (queues, idempotency, rate limits); the private encrypted disk for photos and IDs.
- **Testing**: Pest feature tests through HTTP on `dahab_wt014` as `dahab`, sequential — every endpoint and refusal; schema tests (guards, CHECKs, uniques, RLS per scope); sweep-skip tests for every pass; concurrency on two connections with committed fixtures then truncate (spec 012 technique): open vs sweep (balance and collection), open vs pay-balance, resolve vs handover, resolve vs resolve, two compensations against the day cap, accept vs receive; reconciliation over every dispute outcome; leak tests (the other party never gets text/photos/reply/proxy; the seller never sees proxy data; customers never see pass-on notes).
- **Performance goals**: open / resolve < 300 ms p95; `GET /dashboard/disputes` < 300 ms with 10,000 disputes; order detail query count unchanged ± 3.
- **Constraints**: bcmath; a named actor on every change and entry; one transaction per operation with the R12 lock order (listing → order → dispute → collection → extension request → ledger accounts); the caps always from settings and summed under a per-staff advisory lock; dispute photos and proxy IDs never to the other party or in lists; Backend files LF, Dashboard CRLF.
- **Scale/scope**: 15 endpoints + 2 upload purposes, 1 Dashboard page + 4 panels/modals, 3 Customer App screens + order-screen blocks.

No open NEEDS CLARIFICATION: 8 clarifications in the spec; engineering choices R1–R22 in [research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Every dispute change writes `dispute_change` with exactly one actor (customer opens, staff pass on / resolve); every order move goes through `MovesOrder` with its actor; the deadline give-back rows name the resolver; the deposit refund names the resolver, compensation names the payer; a lapsed request records no answerer but is caused by an order move that names its own actor; the proxy acceptance is the buyer's. | ✅ |
| II. Isolation by the engine; staff authz by data | Forced RLS on `dispute`, `dispute_photo`, `dispute_change`, `compensation`, `order_extension_request`, each readable only by the customer the row belongs to (analysis C2); customer writes only in the non-elevated `order` scope for their own rows; staff use 4 new catalogue codes plus existing ones — never role names; the cap lift is a code (`compensation.uncapped`), not a role. | ✅ |
| III. Docs first | Updated in the same change: `05_schema_security.sql` §16 (dispute columns, photos, history, compensation), §18 (3 transitions, `dispute_transition`, `extension_request_transition`, DH009/DH010, RLS); `04_schema_market.sql` §10 (`which` + `decision`, the two FKs, `order_extension_request`) and §11 (proxy columns/CHECKs); `02_schema_identity.sql` (the legal document); `00_schema_full.sql`. Technical Spec Part 1 §4.1/§4.2 (codes), Part 2 §3 (upload purposes), §5 (requests for more time), §7 handover, §9 compensation, §10 disputes, §12 errors; Part 3 §1.4 and §10.3 ("Changed by spec 014"); `api-contract.md`; `docs/features/disputes.md`; CLAUDE.md "Current state". | ✅ |
| IV. Foundation before modules | The migration mirrors the updated schema; every endpoint has a Request, Resource, Action, Pest tests (happy path and refusals) and `#[OA]`. | ✅ |
| V. Test the boundary and the ledger | The deposit refund and compensations asserted through HTTP (wallets, the order ledger); freezing proven against each sweep pass by command; reconciliation over every outcome. | ✅ |
| Reversible migrations | `down()` refuses once any dispute, compensation or request exists; otherwise drops in reverse and deletes the 3 transitions and the legal document. | ✅ (documented) |

No Constitution deviation.

**Recorded deviations from the schema / Technical Spec** (land in `docs/` in the same change; **pending the user's explicit approval** — analysis D1):

- Paths `/customer/me/orders/{order}/{disputes|extension-requests|proxy}` and `/dashboard/{disputes,extension-requests}*` instead of `/admin/*`, `/igi/*` (R1).
- `dispute` gains `dispute_no`, `raised_as`, `frozen_from`, `frozen_at`, `outcome`, `passed_on_at`, `release_txn_id`; `detail` becomes required; reason codes fixed (R9).
- New tables `dispute_photo`, `dispute_change`, `compensation`, `order_extension_request`, `dispute_transition`, `extension_request_transition`.
- Three `order_transition` rows (Clarification Q1); `order_deadline_extension.which` gains `decision`, plus `dispute_id` / `extension_request_id`.
- `collection` gains `proxy_acceptance_id`, `proxy_named_at`, `collected_by_proxy`, `proxy_id_checked_by`; the proxy is named before the counter, not typed at it; the handover body takes `collector` + `proxy_id_checked` instead of `is_proxy`, `proxy_name`, `proxy_phone`, `proxy_id_upload_token` (R14).
- Permissions: "Pay compensation — Finance up to cap" becomes `compensation.pay` + `compensation.uncapped` (R7).
- New error codes `order_frozen`, `dispute_already_raised`, `dispute_outcome_not_allowed`, `illegal_dispute_transition`, `assignee_not_eligible`, `extension_request_pending`, `illegal_extension_request_transition`.
- Upload purpose `dispute_photo` (not in the docs).

## Project Structure

### Documentation

```text
specs/014-disputes/{spec,plan,research,data-model,quickstart}.md, contracts/disputes-api.md, checklists/requirements.md, tasks.md (next)
docs/features/disputes.md (+ README index)
```

### Source Code

```text
backend
  docs/Database schema/{00,02,04,05}_*.sql · docs/Technical Spec/part{1,2,3}.md · docs/platform/api-contract.md · docs/features/{disputes.md,README.md} · CLAUDE.md
  database/migrations/2026_10_07_000010_create_disputes.php
  database/seeders/LocalDisputeSeeder.php (+ DatabaseSeeder, local only) · database/factories/{DisputeFactory,OrderExtensionRequestFactory}.php · PermissionSeeder/role seeds (+4)
  app/Enums/{DisputeReason,DisputeState,DisputeOutcome,DisputeChangeKind,CompensationReason,ExtensionRequestReason,ExtensionRequestState}.php · StaffPermission (+4) · UploadPurpose (+2) · OrderEvent (+6) · AuditEvent (+~10) · DeadlineKind (decision extendable by give-back)
  app/Models/{Dispute,DisputePhoto,DisputeChange,Compensation,OrderExtensionRequest}.php · Order (+relations) · OrderCollection (+proxy) · OrderDeadlineExtension (+FKs) · LegalDocument (+COLLECTION_PROXY_AUTHORISATION)
  app/Support/Disputes/{DisputeCursor,CompensationCaps,FrozenTime}.php
  app/Actions/Orders/Concerns/MovesOrder.php (+assertNotFrozen, lapse waiting requests)
  app/Actions/Disputes/Customer/OpenDisputeAction.php
  app/Actions/Disputes/Staff/{ListDisputesAction,ShowDisputeAction,ViewDisputePhotoAction,ListDisputeAssigneesAction,PassOnDisputeAction,ResolveDisputeAction}.php
  app/Actions/Disputes/{PayCompensationAction,GiveBackFrozenTimeAction}.php
  app/Actions/Orders/Customer/{NameProxyAction,RemoveProxyAction,RequestMoreTimeAction}.php
  app/Actions/Orders/Staff/{ListExtensionRequestsAction,AcceptExtensionRequestAction,RefuseExtensionRequestAction,ViewProxyIdAction}.php · HandoverPieceAction (collector/proxy check) · the 13 order Actions (assertNotFrozen)
  app/Actions/Identity/CreateCustomerUploadAction.php (+2 purposes) · app/Http/Requests/Identity/StoreUploadRequest.php (gate per purpose)
  app/Actions/Orders/Concerns/TellsOrderParties.php (+proxy SMS) · app/Notifications/{OrderNotification (+6 events), ProxyNamedNotification}.php · lang EN/AR
  app/Exceptions/DomainApiException.php (+7) · bootstrap/app.php (DH009, DH010)
  app/Http/Requests/Customer/Order/{OpenDisputeRequest,RequestMoreTimeRequest,NameProxyRequest}.php · Dashboard/Dispute/{ListDisputesRequest,PassOnDisputeRequest,ResolveDisputeRequest}.php · Dashboard/Order/{ListExtensionRequestsRequest,AcceptExtensionRequestRequest,RefuseExtensionRequestRequest}.php · HandoverRequest (+collector, proxy_id_checked)
  app/Http/Resources/Customer/{DisputeResource}.php · CustomerOrderResource (+frozen, dispute, extension_request, proxy, can) · Staff/{StaffDisputeResource,ExtensionRequestResource}.php · StaffOrderResource (+disputes, extension_request, proxy, can) · CustomerFileResource (+disputes_raised)
  app/Http/Controllers/Api/V1/Customer/OrderController (+disputes, extension-requests, proxy) · Dashboard/{DisputeController,ExtensionRequestController}.php · Dashboard/OrderController (+proxy-id) · routes/api.php · AppServiceProvider (throttle customer.disputes)
  postman/Dahab-Backend.postman_collection.json (folder "Disputes": customer, dashboard; handover body)
  tests/Feature/Dispute/{DisputeSchemaTest,OpenDisputeTest,FreezeTest,SweepSkipsFrozenTest,DisputeQueueTest,PassOnDisputeTest,ResolveResumeTest,ResolveAgainstSaleTest,CompensationTest,SuspendOnResolveTest,DisputeIsolationTest,DisputeLeakTest,DisputePermissionsTest,DisputeNotificationTest,DisputeConcurrencyTest,DisputeReconciliationTest}.php
  tests/Feature/Order/{ProxyCollectionTest,ExtensionRequestTest}.php · tests/Feature/Performance/DisputePerformanceTest.php
  keep lists: tests/Feature/Order/OrderConcurrencyTest.php, tests/Feature/Performance/OrdersPerformanceTest.php, tests/Feature/Withdrawal/WithdrawalConcurrencyTest.php, tests/Feature/Performance/WithdrawalPerformanceTest.php
  tests/Support/Disputes.php
dashboard (CRLF)
  src/api/endpoints.ts · src/types/{dispute,order,staff}.ts · src/services/{dispute.service.ts,order.service.ts,errors.ts} · src/mock/nav.ts (badge from counts, shown) · src/router/index.ts (Placeholder → page)
  src/pages/disputes/index.vue
  src/components/disputes/{DisputesTable,DisputeDetailPanel,PassOnModal,ResolveModal}.vue
  src/components/orders/{OrderDetailPanel (dispute, extension request, proxy), ExtensionRequestsTable, AnswerExtensionModal, HandoverModal (collector + ID check), orderErrors.ts, orderFormat.ts (disputed)} · src/pages/orders/index.vue (More time requested / Extension requests)
flutter (no git)
  lib/models/{order.dart (+frozen, dispute, extension_request, proxy, can), dispute.dart}
  lib/services/api/orders_api.dart (+openDispute, requestMoreTime, nameProxy, removeProxy) · uploads (dispute_photo, proxy_id) · repositories.dart · mock_repositories.dart (mock dispute/extend/proxy removed)
  lib/features/orders/{order_flows.dart (DisputeScreen, ExtendScreen, ProxyScreen on the API), order_screen.dart (On hold, dispute status/reply, request status, proxy card, buttons by can), orders_screen.dart (disputed)}
  lib/core/i18n (strings, errors, ar_extra) · test/{test_app,flows_test}.dart (fake endpoints + flows)
```

**Structure decision**: the existing layering. Every order move through `MovesOrder` (now also the freeze guard and the request lapse); every dispute change through one `ChangesDispute` path writing `dispute_change`; money only through `PostLedgerEntryAction` (`DepositLedger::release`, `PayCompensationAction`).

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R22)
- [data-model.md](./data-model.md)
- [contracts/disputes-api.md](./contracts/disputes-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no Constitution violation; the schema deviations above are recorded and land in `docs/` in the same change.

## Follow-ups (not in this feature)

- Unwinding a paid order against the sale (Clarification Q2: deferred), the manual post-window refund from escrow.
- Staff approval of inspection messages (Clarification Q3: deferred).
- Listing reports (`RPT-…`) on the same page; the stand-alone *Pay compensation* and *Refund a buyer* screens; direct wallet adjustment; case files.
- *Ask IGI to re-weigh* as a re-inspection workflow; a back-and-forth thread with the customer.
- Automatic suspension for repeated disputes (no threshold in the schema).
- The Withdrawals signal "open disputes" (spec 013 follow-up) can now be built.

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `dispute_change` table | Named actor and history for pass-on (repeatable) and resolve | The audit log is not business data and customers cannot read it |
| `dispute_photo` table | 0–5 private photos per dispute | A JSON array of refs cannot carry append-only and RLS per row |
| `compensation` table + `compensation.uncapped` | Exact per-staff day cap and the reason code; "up to cap" without role names | Summing the ledger by actor lacks the reason; a role check breaks Principle II |
| `order_extension_request` + transition table | The seller's ask, its answer and the link to the granted extension | `order_deadline_extension` holds only granted extensions |
| Deadline give-back as extension rows | Keeps every deadline change in one audited table with its guard | A pause counter touches every deadline reader |
| `assertNotFrozen` in 10 Actions | One clear `order_frozen` code | Each Action's own `illegal_order_transition` hides the reason |
