# Implementation Plan: Finance operations and order export

**Branch**: `feature/finance-ops` in all three repositories (backend worktree `spec-015-finance-ops-f96ac1` from `main` 8fe25f7; dashboard worktree `.claude/worktrees/finance-ops` from `main` c4e90f2; Customer App worktree `.claude/worktrees/finance-ops` from `main` 2b66253) | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

## Summary

- **Compensation**: a list of every payment (period, reason, payer, customer filters; period and month totals; the caps and the viewer's remaining limit), CSV export, and direct payment outside a dispute under `compensation.pay` with the same caps — `compensation` loses its NOT NULL on dispute/order/party (R2).
- **Wallet adjustment**: the CEO-only (`wallet.adjust`) credit/debit of a customer's available against `external_equity`, kind `reversal` without a reversed entry, recorded in append-only `wallet_adjustment`; never below zero (R3).
- **Bank book**: record movements outside the app (seven kinds, statement date, reason, optional encrypted proof via a staff upload token; own-account transfers are records without an entry) under `bank.record`; the bank book lists every bank posting with its source and opening/in/out/closing cash; both export CSV (R5–R7).
- **Daily close**: `day.close` closes an ended Cairo day against a typed statement balance; books cut at midnight under a share lock; 0 locks, non-zero locks only with an explanation, else saved unlocked; locked rows are frozen by the schema's trigger (R8–R9).
- **Overview**: `GET /dashboard/overview` — earnings this month, orders by state with held now, needs-a-decision, this month — each section by permission; the rest of the page uses existing endpoints; all mock data removed (R10).
- **Order export**: `GET /dashboard/orders/export` with the list's filters and branch scope, audited (R11).
- **Customer App**: `deposit_held` on requests and orders and `GET /customer/me/wallet/held` for the Held screen (R12); public `GET /reference/gold-prices` and `GET /reference/quote` wiring the rate strip, splash cards, home calculator and sell estimate, MOCK flags removed (R13); wallet history labels for compensation and correction checked in EN/AR.

## Impact analysis

```
Backend:          YES — 1 migration; ~12 Actions (Finance/{PayDirectCompensation,ListCompensation,ExportCompensation,AdjustWallet,ListWalletAdjustments,CreateStaffUpload,RecordBankMovement,ListBankMovements,ExportBankMovements,ViewBankMovementProof,BuildBankBook,ExportBankBook,ShowCloseDay,ListCloses,CloseDay}, Overview/ShowOverview, Orders/Staff/ExportOrders, Wallet/ListHeldItems, Reference/{ShowGoldPrices,Quote}), PayCompensationAction generalised; ~10 Requests, ~8 Resources (+2 extended), 5 controllers (+3 extended), 2 error codes, ~10 audit events, 2 notifications, seeder, factories, tests, Postman
Database:         YES — compensation (3 columns nullable, 2 CHECKs, check function), wallet_adjustment, bank_movement (+ sequence), daily_close; DH011 deferred checks; forced RLS on wallet_adjustment; 3 permissions
API:              YES — 17 dashboard endpoints, 1 customer endpoint + 2 resource fields, 2 public endpoints; all additive
Dashboard:        YES — Compensation, Bank movements, Daily closing pages; live Overview; Orders Export; Customer file Adjust wallet; types/services/endpoints/permissions/errors; mock/overview.ts removed
Customer App:     YES — Held screen list, deposit_held on request/order screens, live prices (rate strip, splash cards, calculator, sell estimate) with MOCK flags removed; EN/AR; fake backend + tests
Auth:             NO — sign-in, tokens and the verified/trade gates unchanged
Permissions:      YES — 3 new codes: wallet.adjust (no role; founders), bank.record + day.close (finance; founders)
API models/types: YES — Dashboard src/types/{finance,overview,order,staff}.ts; Flutter lib/models/{wallet,order,buy_request,prices}.dart
```

**Classification**: non-breaking (new endpoints, optional fields). Potentially breaking: compensation rows without dispute/order (consumers read them only per dispute today), the permission union (+3), the upload purpose enum (+1, staff only). Nothing renamed or removed.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary dependencies**:
  - **Backend**: `PostLedgerEntryAction`, `Account`, `StatementCursor`/`StatementQuery` (spec 008); `PayCompensationAction`, `CompensationCaps` (spec 014); `IdentityDocumentStorage`, `UploadTokenStore` (spec 001/009); `ListOrdersAction` + `ListOrdersRequest` (spec 012); the withdrawals export technique (spec 013); `PricingContext`, `PriceCalculator`, `Piece` (spec 005); `RecordAuditLogAction`; `idempotent` middleware; `DatabaseActor::ledger`; on-demand SMS + mail.
  - **Dashboard**: TanStack Vue Query, `useIdempotencyKey`, `usePermissions`, `DModal`, the statement/withdrawals table and export patterns.
  - **Flutter**: `ApiClient`, `ApiWalletRepository`, the orders/buy-requests APIs, `LiveRates` (replaced), provider.
- **Storage**: PostgreSQL 16; Redis (idempotency, throttles); the private encrypted disk (`bank-proofs/`).
- **Testing**: Pest through HTTP on `dahab_wt015` as `dahab`, sequential — every endpoint and refusal; schema tests (CHECKs, DH011, the no-reopen trigger, RLS on `wallet_adjustment`); concurrency on two connections (R15); reconciliation; performance (overview and bank book with 10,000 orders/postings < 300 ms p95; order export 10,000 rows < 10 s).
- **Constraints**: bcmath; one transaction per write with the money service's lock order; caps from settings under the per-payer advisory lock; the close's share lock held only for the read; Backend LF, Dashboard CRLF; Flutter `dart format --line-length 180` on changed files only.
- **Scale/scope**: 20 endpoints, 3 Dashboard pages + Overview + 2 modals elsewhere, 1 Customer App screen + 4 price parts.

No open NEEDS CLARIFICATION: 10 clarifications in the spec; engineering choices R1–R16 in [research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Compensation names the payer; adjustment `adjusted_by` + the entry's staff actor; bank movement `recorded_by` + the entry's actor; close `saved_by`/`closed_by`; exports and proof views audited with the viewer. | ✅ |
| II. Isolation by the engine; staff authz by data | `wallet_adjustment` under forced RLS (customer reads own, writes elevated); `compensation` RLS unchanged; `bank_movement` / `daily_close` hold no customer data; three new catalogue codes, never role names; the overview filters by codes. | ✅ |
| III. Docs first | Same change: `05_schema_security.sql` §16 (compensation nullables) and §17 (`bank_movement`, `daily_close` as built, `wallet_adjustment`), `00_schema_full.sql`; Technical Spec Part 1 §4.2 (codes), Part 2 §9 (compensation direct, wallet adjustment, bank movements, daily close), §3 reference (public prices/quote), §12 errors — "Changed by spec 015"; `api-contract.md` (staff uploads); `docs/features/finance-ops.md`; CLAUDE.md "Current state". | ✅ |
| IV. Foundation before modules | The migration mirrors the updated schema; each endpoint has Request, Resource, Action, Pest tests and `#[OA]`. | ✅ |
| V. Test the boundary and the ledger | Every money write asserted through HTTP on balances and the whole-ledger zero; concurrency and reconciliation suites. | ✅ |
| Reversible migrations | `down()` refuses once any new row (or a dispute-less compensation) exists (R16). | ✅ (documented) |

No Constitution deviation.

**Recorded deviations from the schema / Technical Spec** (land in `docs/` in the same change; pending the user's approval at the end of analyze):

- Paths `/dashboard/*` and `/reference/*` instead of `/admin/*` (R1).
- `compensation.dispute_id`, `order_id`, `party` nullable (Clarification).
- `bank_movement.kind`: the design's seven (Part 2 lists four; `rent` → `operating_expense`); `movement_no`, `proof_mime`; `ledger_txn_id` NULL only for `own_transfer`.
- `daily_close` gains `books_bank`, `customer_available`, `customer_held`, `escrow`, `vat_payable`, `movements_in/out`, `explanation`, `saved_by/at`; "lock when clean" extended to "or with an explanation" (Clarification).
- New table `wallet_adjustment`; the adjustment is kind `reversal` without a reversed entry (R3).
- New error codes `day_not_ended`, `day_already_closed`; SQLSTATE DH011.
- Staff upload `POST /dashboard/uploads` with purpose `bank_movement_proof`.
- Public `GET /reference/gold-prices` and `GET /reference/quote` (not in Part 2).

## Project Structure

### Documentation

```text
specs/015-finance-ops/{spec,plan,research,data-model,quickstart}.md, contracts/finance-ops-api.md, checklists/requirements.md, tasks.md (next)
docs/features/finance-ops.md (+ README index)
```

### Source Code

```text
backend (LF)
  docs/Database schema/{00,05}_*.sql · docs/Technical Spec/part{1,2}.md · docs/platform/api-contract.md · docs/features/{finance-ops.md,README.md} · CLAUDE.md
  database/migrations/2026_10_08_000010_create_finance_ops.php
  database/seeders/{LocalFinanceSeeder.php (+ DatabaseSeeder, local only), DashboardRolesAndPermissionsSeeder (+3)} · database/factories/{WalletAdjustmentFactory,BankMovementFactory,DailyCloseFactory}.php
  config/dahab-finance.php (export caps, proof max size) · config/dahab-orders.php (+export_cap)
  app/Enums/{BankMovementKind,AdjustmentDirection}.php · StaffPermission (+3) · UploadPurpose (+1) · AuditEvent (+~10)
  app/Models/{WalletAdjustment,BankMovement,DailyClose}.php · Compensation (nullable relations)
  app/Support/Finance/{BankBookQuery,BankBookCursor,CloseFigures,CompensationListQuery}.php · app/Support/Overview/OverviewSections.php · app/Support/Wallet/HeldByRequest.php
  app/Actions/Disputes/PayCompensationAction.php (generalised)
  app/Actions/Finance/{PayDirectCompensationAction,ListCompensationAction,ExportCompensationAction,AdjustWalletAction,ListWalletAdjustmentsAction,CreateStaffUploadAction,RecordBankMovementAction,ListBankMovementsAction,ExportBankMovementsAction,ViewBankMovementProofAction,BuildBankBookAction,ExportBankBookAction,ShowCloseDayAction,ListClosesAction,CloseDayAction}.php
  app/Actions/Overview/ShowOverviewAction.php · app/Actions/Orders/Staff/ExportOrdersAction.php · app/Actions/Wallet/ListHeldItemsAction.php · app/Actions/Reference/{ShowGoldPricesAction,QuoteAction}.php
  app/Notifications/WalletNotification.php (compensation_paid, wallet_adjusted) · lang/{en,ar}
  app/Exceptions/DomainApiException.php (+2) · bootstrap/app.php (DH011)
  app/Http/Requests/Dashboard/Finance/{ListCompensationRequest,PayCompensationRequest,AdjustWalletRequest,ListWalletAdjustmentsRequest,StoreStaffUploadRequest,RecordBankMovementRequest,ListBankMovementsRequest,BankBookRequest,CloseDayRequest,ListClosesRequest}.php · Reference/QuoteRequest.php
  app/Http/Resources/Staff/{CompensationResource,WalletAdjustmentResource,BankMovementResource,BankBookRowResource,DailyCloseResource}.php · Customer/{BuyRequestResource,CustomerOrderResource} (+deposit_held) · Customer/HeldItemResource.php · Reference/{GoldPricesResource,QuoteResource}.php
  app/Http/Controllers/Api/V1/Dashboard/{CompensationController,WalletAdjustmentController,BankMovementController,DailyCloseController,OverviewController,StaffUploadController}.php · Dashboard/OrderController (+export) · Customer/WalletController (+held) · ReferenceController (+goldPrices, quote) · routes/api.php · AppServiceProvider (throttle dashboard.uploads)
  postman/Dahab-Backend.postman_collection.json (folder "Finance"; Overview; Orders export; Customer wallet held; Reference prices/quote)
  tests/Feature/Finance/{FinanceSchemaTest,CompensationListTest,DirectCompensationTest,WalletAdjustmentTest,StaffUploadTest,BankMovementTest,BankBookTest,DailyCloseTest,FinancePermissionsTest,FinanceIsolationTest,FinanceConcurrencyTest,FinanceReconciliationTest}.php
  tests/Feature/Overview/OverviewTest.php · tests/Feature/Order/OrderExportTest.php · tests/Feature/Wallet/HeldPerRequestTest.php · tests/Feature/Reference/PublicPricesTest.php · tests/Feature/Performance/FinancePerformanceTest.php
  keep lists unchanged (no transition tables); the new append-only tables verified truncatable
dashboard (CRLF)
  src/api/endpoints.ts · src/types/{finance,overview,order,staff}.ts · src/services/{finance.service.ts,overview.service.ts,order.service.ts (+export),wallet.service.ts (+adjust),errors.ts}
  src/composables/{useFinance.ts,useOverview.ts (live)} · src/mock/{overview.ts (removed), nav.ts (pages shown by permission)} · src/router/index.ts (Placeholder → pages)
  src/pages/{compensation,bankbook,closing}/index.vue · src/pages/dashboard/overview.vue (live)
  src/components/finance/{CompensationTable,PayCompensationModal,CapsCard,BankMovementForm,BankMovementsTable,BankBookTable,CloseDayPanel,RecentClosesTable}.vue · src/components/customer-file/AdjustWalletModal.vue · src/components/dashboard/* (live sections) · orders page Export button
flutter (worktree, public repo — nothing secret)
  lib/models/{wallet.dart (+HeldItem), order.dart / buy_request.dart (+depositHeld), prices.dart (GoldPrices, SellQuote from API)}
  lib/services/api/{wallet_api.dart (+held), prices_api.dart} · lib/services/live_rates.dart (API, polling) · repositories.dart · mock_repositories.dart (mock feed removed)
  lib/features/wallet/wallet_screens.dart (HeldScreen list) · orders/buy-request screens (held line) · home/home_screen.dart (RateBar, _Calculator) · auth/splash_screen.dart (_RateCards) · sell/sell1_screen.dart (estimate) · widgets/mock_flag.dart
  lib/core/i18n (strings + number patterns) · test/{test_app,flows_test}.dart (fake endpoints + flows)
```

**Structure decision**: the existing layering — thin controllers, FormRequests, Actions with one transaction, money only through `PostLedgerEntryAction`, reads through Resources; exports reuse the withdrawals technique; the overview is read-only aggregate SQL per section.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R16)
- [data-model.md](./data-model.md)
- [contracts/finance-ops-api.md](./contracts/finance-ops-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no Constitution violation; the deviations above land in `docs/` in the same change.

## Follow-ups (not in this feature)

- Founder real-time alert on a wallet adjustment (OI-1.3 channel undecided).
- The first-sale advance (*Paid out ahead of buyers*), the Rapaport matrix, invoices / credit notes, promo codes, market makers.
- A bank-statement import to match movements line by line.
- Reversing a specific earlier ledger entry from the Dashboard.

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `wallet_adjustment` table | List adjustments with direction, reason and the customer's status | Filtering `reversal` entries cannot tell an adjustment from a true reversal or carry the direction |
| Share lock in the close | Deterministic cut-off against commits that straddle midnight | A grace period only narrows the race |
| Staff upload token | Keeps the movement POST JSON so idempotency covers it | Multipart bodies hash files as `{}` |
| Extra `daily_close` columns | The design shows each figure per day; a locked row must keep what was compared | Recomputing later would change with history only if the ledger changed — but storing makes the lock meaningful and the list cheap |
