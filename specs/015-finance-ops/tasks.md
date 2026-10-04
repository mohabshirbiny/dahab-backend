# Tasks: Finance operations and order export

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/finance-ops-api.md](./contracts/finance-ops-api.md), [quickstart.md](./quickstart.md)

**Tests**: required (Constitution V; the user's brief: reconciliation and concurrency like specs 012–014 — two adjustments at once, adjustment vs withdrawal, close vs a late entry). Work test-first within each story: write the test, watch it fail for the expected reason, implement, then run it.

**Database rules**:
- Run artisan and the tests as the `dahab` DB user, passing `DB_DATABASE=… DB_USERNAME=dahab DB_PASSWORD=secret` explicitly.
- **Never** run the suite, `migrate:fresh`, a rollback or a seeder against the main `dahab` database. Tests: `dahab_wt015`; seeded app: `dahab_wt015_dev`; both owned by `dahab` (as postgres: `CREATE DATABASE dahab_wt015 OWNER dahab;`, `CREATE DATABASE dahab_wt015_dev OWNER dahab;`).
- Run the suite **sequentially**. Any test that commits data puts back every kept table it changes (settings, karat adjustments).

**Paths**:
- Backend paths are relative to the repo root (worktree `spec-015-finance-ops-f96ac1`, branch `feature/finance-ops`). LF.
- Dashboard paths are under `D:\laragon\www\dahab-dashboard\.claude\worktrees\finance-ops` (branch `feature/finance-ops` from `main` c4e90f2, created). CRLF — edit with Edit/Write.
- Flutter paths are under `D:\laragon\www\dahab-flutter\.claude\worktrees\finance-ops` (branch `feature/finance-ops` from `main` 2b66253, created). Public repo: nothing secret. `dart format --line-length 180` only on changed files.

**Git**: never commit or push unless told; stage only changed files (never `git add -A`), `git status` right before every commit. Before each phase, check `git status` and file modification times in all three projects (another session may write the same tree).

**Every endpoint task** includes its `#[OA\…]` attributes; its Postman request in `postman/Dahab-Backend.postman_collection.json` (folder "Finance" unless noted; body in step with the FormRequest; the `Idempotency-Key` pre-request script on every POST; saved-id scripts `compensation_id`, `adjustment_id`, `bank_movement_id`, `proof_upload_token`); and the permission middleware on the route. It is not done without them.

## Format: `[ID] [P?] [Story] Description`

User stories (spec.md): **US1** compensation list + direct payment (P1) · **US2** wallet adjustment (P1) · **US3** bank book (P1) · **US4** daily close (P1) · **US5** real Overview (P2) · **US6** Orders export (P2) · **US7** held per order in the Customer App (P2) · **US8** public prices and quote (P3).

---

## Phase 1: Setup (docs first — Constitution III)

- [X] T001 Create the databases `dahab_wt015` and `dahab_wt015_dev` (owner `dahab`); run the current backend suite on `dahab_wt015` and confirm it is green before any change; confirm the dashboard and Flutter worktrees are clean on `feature/finance-ops`.
- [X] T002 Write `docs/features/finance-ops.md` from `docs/features/_TEMPLATE.md`: impact summary and classification (plan.md), the 10 clarifications in short, the endpoint table (contracts), permissions and seeds (research R4), recorded deviations, follow-ups, links to `specs/015-finance-ops/`; add it to `docs/features/README.md`.
- [X] T003 Update `docs/Database schema/05_schema_security.sql` §16 (compensation: `dispute_id`, `order_id`, `party` nullable; CHECKs `dispute_id IS NULL OR order_id IS NOT NULL` and `(order_id IS NULL) = (party IS NULL)`; the `compensation_recorded()` note) and §17 (`bank_movement` with `movement_no`, kind CHECK in (`capital_in`, `operating_expense`, `bank_charge`, `profit_draw`, `own_transfer`, `supplier_refund`, `other`), `amount <> 0` signed, reason 10–500, `proof_ref`/`proof_mime` pair, `ledger_txn_id` NULL iff `own_transfer`, append-only; `daily_close` with every column and CHECK of data-model.md §4 and the `daily_close_no_reopen` trigger; new `wallet_adjustment` per data-model.md §2 with forced RLS; DH011 deferred checks), each change marked "spec 015"; mirror in `00_schema_full.sql`.
- [X] T004 Amend the Technical Spec, each change "Changed by spec 015" with a link: Part 1 §4.2 (codes `wallet.adjust` — no role, founders; `bank.record`, `day.close` — finance; compensation payable outside a dispute), §5.1 (`wallet_adjustment` RLS); Part 2 §9 (`/dashboard/compensation*`, `/dashboard/customers/{id}/wallet-adjustments`, `/dashboard/wallet-adjustments`, `/dashboard/uploads`, `/dashboard/bank-movements*`, `/dashboard/bank-book*`, `/dashboard/daily-close*`, `/dashboard/overview`, `/dashboard/orders/export`; the adjustment's kind `reversal` without a reversed entry against `external_equity`; the seven kinds; the close rules), §3 reference (`/reference/gold-prices`, `/reference/quote`), customer wallet (`/customer/me/wallet/held`, `deposit_held`), §12 errors (`day_not_ended`, `day_already_closed`).
- [X] T005 [P] Update `docs/platform/api-contract.md`: surfaces (new paths), staff uploads, the three codes, idempotency list, error codes, CSV export conventions (now also orders, compensation, bank book, bank movements), public reference reads.

---

## Phase 2: Foundational (blocks every story)

- [X] T006 Write `tests/Feature/Finance/FinanceSchemaTest.php` (fails first): the three new tables exist; `wallet_adjustment` has forced RLS + policy; every CHECK of data-model.md §1–§4 (a compensation with a dispute and no order, a party without an order, a bank movement `own_transfer` with an entry or another kind without one, `amount = 0`, a proof ref without mime, a locked close without closer, a locked non-zero close without explanation, `difference <> bank_balance − books_bank`); `wallet_adjustment` and `bank_movement` append-only; a locked `daily_close` cannot be updated or deleted, an unlocked one can be updated; the deferred checks refuse a `wallet_adjustment` or `bank_movement` without a matching entry (SQLSTATE DH011) and a dispute-less compensation without its entry (DH009); the three tables can be truncated.
- [X] T007 Write `database/migrations/2026_10_08_000010_create_finance_ops.php` from the updated schema (T003): compensation ALTERs + CHECKs + `idx_compensation_paid_at (paid_at, compensation_id)` + the replaced `compensation_recorded()` (party check only with an order; `t.order_id IS NOT DISTINCT FROM NEW.order_id`); `bank_movement_no_seq`; `wallet_adjustment`, `bank_movement`, `daily_close` with indexes, `block_mutation` triggers, `daily_close_no_reopen`, deferred `wallet_adjustment_recorded()` / `bank_movement_recorded()` (DH011, reading in the `ledger` scope); forced RLS on `wallet_adjustment` (`USING (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())`, `WITH CHECK (dahab_rls_elevated())`); `down()` refuses while any new row or a compensation with `dispute_id IS NULL` exists, else reverses everything (R16). Run T006 green.
- [X] T008 [P] `bootstrap/app.php`: map DH011 to a logged 500 integrity failure; `app/Exceptions/DomainApiException.php` + `dayNotEnded()` 422 `day_not_ended`, `dayAlreadyClosed()` 409 `day_already_closed`.
- [X] T009 [P] Enums: `app/Enums/BankMovementKind.php` (seven, EN labels as the design: Capital paid in, Operating expense, Bank charge, Profit taken out, Transfer between our own accounts, Refund from a supplier, Something else; `postsToLedger()`), `app/Enums/AdjustmentDirection.php` (`credit`, `debit`); `StaffPermission` + `WALLET_ADJUST = 'wallet.adjust'`, `BANK_RECORD = 'bank.record'`, `DAY_CLOSE = 'day.close'` with labels and the read unions `COMPENSATION_READ = 'compensation.pay|wallet.view'`, `ADJUSTMENTS_READ = 'wallet.adjust|wallet.view'`, `BANK_READ = 'bank.record|wallet.view'`, `CLOSE_READ = 'day.close|wallet.view'`; `UploadPurpose` + `BANK_MOVEMENT_PROOF` (PDF/JPG/PNG, 10 MB, staff only); `AuditEvent` + `compensation.list_exported`, `wallet.adjusted`, `bank.movement_recorded`, `bank.movement_proof_viewed`, `bank.book_exported`, `bank.movements_exported`, `day.closed`, `day.saved`, `order.list_exported`.
- [X] T010 [P] `database/seeders/DashboardRolesAndPermissionsSeeder.php`: add the three codes additively — `wallet.adjust` to no role, `bank.record` and `day.close` to `finance` — founders hold every code; extend the seeder test.
- [X] T011 [P] Models + factories: `app/Models/{WalletAdjustment,BankMovement,DailyClose}.php` (casts, relations to customer/staff/ledger transaction, `number()` = `BM-{movement_no}`), `Compensation` relations nullable; `database/factories/{WalletAdjustmentFactory,BankMovementFactory,DailyCloseFactory}.php` building through the Actions where an entry is needed.
- [X] T012 [P] `config/dahab-finance.php` (`export_cap` 10000, `proof_max_kb` 10240) and `config/dahab-orders.php` + `export_cap` 10000.

---

## Phase 3: US1 — Compensation list and direct payment (P1)

**Goal**: Finance sees every compensation with totals and the caps, exports it, and pays outside a dispute under the same caps. **Independent test**: spec US1 scenarios 1–6.

- [X] T013 [P] [US1] `tests/Feature/Finance/CompensationListTest.php`: list newest first with dispute/order refs (null for direct), filters (`from`, `to` default last 30 days and ≤ 366 days, `reason`, `paid_by`, `customer_id`), keyset pages, `meta.totals.period` = sum of rows and `this_month`, `meta.caps` for a capped payer (`left_today`) and an uncapped one (`left_today: null`); export CSV equals the filtered rows, BOM, formula-neutralised, audited `compensation.list_exported`; COO/Operations 403.
- [X] T014 [P] [US1] `tests/Feature/Finance/DirectCompensationTest.php`: pay 800 `wasted_trip` with no order → one `compensation` entry `external_equity −800`, `cust_available +800`, a row with null dispute/order/party, audited `compensation.paid`, SMS + email after commit; with an order of the customer → party derived; an order the customer is not party to → 422; amount > per-payment or > left today → `compensation_cap_exceeded` with `details.per_payment` / `details.left_today`; `compensation.uncapped` lifts both; unverified customer → `verification_required`; suspended-from-verified allowed with the status in the audit; note 10–1000, reason one of `igi_delay|dahab_mistake|wasted_trip|dispute_settlement|goodwill`; Idempotency-Key replay posts once; the dispute-resolve path (spec 014 tests) still green.
- [X] T015 [US1] Generalise `app/Actions/Disputes/PayCompensationAction.php` to `handle(Staff $payer, string $customerId, string $amount, CompensationReason $reason, string $note, ?Order $order, ?Dispute $dispute, ?RequestContext $ctx)` (party from the order when named; memo `Compensation {DSP-…|direct} · {reason}`; entry `orderId`/`listingId` only with an order), keeping the per-payer advisory lock and caps; update the spec 014 caller `ResolveDisputeAction`.
- [X] T016 [US1] `app/Actions/Finance/PayDirectCompensationAction.php` (own transaction, customer standing per research R2, the order must be the customer's, calls T015, after-commit notification) and the new `app/Notifications/WalletNotification.php` with events `compensation_paid` and `wallet_adjusted` (SMS + email, EN/AR in `lang/`; spec 014's `OrderNotification` needs an order, so direct payments use this one).
- [X] T017 [US1] `app/Support/Finance/CompensationListQuery.php` + `app/Actions/Finance/{ListCompensationAction,ExportCompensationAction}.php` (totals, caps via `CompensationCaps` for the viewer; export per the withdrawals technique, cap `dahab-finance.export_cap`).
- [X] T018 [US1] `app/Http/Requests/Dashboard/Finance/{ListCompensationRequest,PayCompensationRequest}.php`, `app/Http/Resources/Staff/CompensationResource.php`, `app/Http/Controllers/Api/V1/Dashboard/CompensationController.php` (`index`, `export`, `store`), routes `GET /dashboard/compensation`, `GET /dashboard/compensation/export` (`staff.permission:compensation.pay|wallet.view`), `POST /dashboard/compensation` (`compensation.pay`, `idempotent`); `#[OA]`; Postman. Run T013–T014 green.

---

## Phase 4: US2 — Wallet adjustment (P1)

**Goal**: the CEO credits or debits a wallet with a reason as one balanced entry. **Independent test**: spec US2 scenarios 1–4.

- [X] T019 [P] [US2] `tests/Feature/Finance/WalletAdjustmentTest.php`: credit 500 → available +500, entry kind `reversal` with `reverses_txn_id` null, `cust_available +500` / `external_equity −500`, staff actor, reason memo, a `wallet_adjustment` row with `customer_status`, audited `wallet.adjusted`, SMS + email (no reason text) after commit; debit 300 of 1000 → 700; debit beyond available → `insufficient_funds` 409 with `details.available`, nothing written; amount > 0 with ≤ 4 decimals, reason 10–1000, direction `credit|debit`; Finance (no `wallet.adjust`) 403, a staff member granted `wallet.adjust` allowed, COO 403; replay posts once; the customer's history shows the line as `reversal` (*Correction*); `GET /dashboard/wallet-adjustments` newest first with filters, readable with `wallet.view`.
- [X] T020 [US2] `app/Actions/Finance/AdjustWalletAction.php` (one transaction; `DatabaseActor::ledger` account lookup; `PostLedgerEntryAction` with `LedgerEventKind::REVERSAL`; the row; audit; after-commit `WalletNotification` event `wallet_adjusted`, no reason text) and `ListWalletAdjustmentsAction.php`.
- [X] T021 [US2] `app/Http/Requests/Dashboard/Finance/{AdjustWalletRequest,ListWalletAdjustmentsRequest}.php`, `app/Http/Resources/Staff/WalletAdjustmentResource.php`, `app/Http/Controllers/Api/V1/Dashboard/WalletAdjustmentController.php`, routes `POST /dashboard/customers/{customer}/wallet-adjustments` (`wallet.adjust`, `idempotent`), `GET /dashboard/wallet-adjustments` (`wallet.adjust|wallet.view`); `#[OA]`; Postman. Run T019 green.

---

## Phase 5: US3 — Bank book (P1)

**Goal**: record movements outside the app with proof and see every bank posting. **Independent test**: spec US3 scenarios 1–4.

- [X] T022 [P] [US3] `tests/Feature/Finance/StaffUploadTest.php`: `POST /dashboard/uploads` purpose `bank_movement_proof`, PDF/JPG/PNG ≤ 10 MB → token; other types/sizes 422; another purpose 422; without `bank.record` 403; the token is single-use and tied to the staff member (another staff member's token → `upload_token_invalid`); throttled.
- [X] T023 [P] [US3] `tests/Feature/Finance/BankMovementTest.php`: `capital_in` 500,000 in → bank cash +500,000, `external_equity` +500,000 posting sign per R6, row `BM-n`, audited `bank.movement_recorded`; `operating_expense` out with proof → proof stored encrypted, `has_proof`, `GET …/proof` streams it and audits `bank.movement_proof_viewed` (404 without proof); `own_transfer` → row, no entry, bank unchanged; `other` without a reason 10–500 → 422; `occurred_on` in the future → 422, on a locked day → accepted and posted now; COO 403; replay posts once; list by `occurred_on` with `meta.totals`, export audited `bank.movements_exported`.
- [X] T024 [P] [US3] `tests/Feature/Finance/BankBookTest.php`: a period with a matched top-up, a credited-by-hand top-up, a released withdrawal, a recorded movement and a reversal → every bank posting oldest first with direction, amount, `cash_after`, actor and `source` (`matched_notice|credited_by_hand` with the reference and receiving account; `WD-n` with the bank transaction number; the movement; the reversed id); opening + in − out = closing and equals −SUM(bank) at the bounds; keyset pages; Cairo-day bounds; export audited `bank.book_exported`; readable with `wallet.view`.
- [X] T025 [US3] `app/Actions/Finance/CreateStaffUploadAction.php` (`IdentityDocumentStorage::storeAt('bank-proofs', staff_id, …)`, `UploadTokenStore` keyed `staff:<id>`), `app/Http/Requests/Dashboard/Finance/StoreStaffUploadRequest.php`, `app/Http/Controllers/Api/V1/Dashboard/StaffUploadController.php`, route `POST /dashboard/uploads` (`bank.record`, throttle `dashboard.uploads` in `AppServiceProvider`); `#[OA]`; Postman.
- [X] T026 [US3] `app/Actions/Finance/{RecordBankMovementAction,ListBankMovementsAction,ExportBankMovementsAction,ViewBankMovementProofAction}.php` (consume the token; signed amount from direction; entry `EXTERNAL_BANK_MOVEMENT` unless `own_transfer`; audit).
- [X] T027 [US3] `app/Support/Finance/{BankBookQuery,BankBookCursor}.php` + `app/Actions/Finance/{BuildBankBookAction,ExportBankBookAction}.php` (one query over bank postings joined to `topup`, `withdrawal`, `bank_movement`, the reversed entry; batch-loaded names).
- [X] T028 [US3] Requests `RecordBankMovementRequest` (`kind`, `direction in|out`, `amount` > 0 ≤ 4 dp, `occurred_on` ≤ today Cairo, `reason` 10–500, `proof_upload_token?`), `ListBankMovementsRequest`, `BankBookRequest` (≤ 366 days); Resources `BankMovementResource`, `BankBookRowResource`; `app/Http/Controllers/Api/V1/Dashboard/BankMovementController.php`; routes `POST /dashboard/bank-movements` (`bank.record`, `idempotent`), `GET /dashboard/bank-movements`, `/export`, `/{movement}/proof`, `GET /dashboard/bank-book`, `/export` (`bank.record|wallet.view`); `#[OA]`; Postman. Run T022–T024 green.

---

## Phase 6: US4 — Daily close (P1)

**Goal**: close an ended day against the typed statement balance; locked days never change. **Independent test**: spec US4 scenarios 1–5.

- [X] T029 [P] [US4] `tests/Feature/Finance/DailyCloseTest.php`: `GET /dashboard/daily-close?date=` for yesterday shows the books at midnight Cairo (bank cash, available, held, liability, Dahab wallet, escrow, VAT, movements in/out) — entries after midnight excluded; today shows live figures and `can_close: false`; `POST` for today/future → `day_not_ended`; statement = books → locked, audited `day.closed`; statement ≠ books without explanation → saved unlocked, audited `day.saved`, closable again (re-computed, same cut-off); with explanation 10–1000 → locked with the difference; closing a locked day → `day_already_closed` 409, row unchanged; `GET /dashboard/daily-closes` with `days_closed`, `last_difference`; COO 403; `wallet.view` reads; replay returns the first answer.
- [X] T030 [US4] `app/Support/Finance/CloseFigures.php` (the books at a cut-off, one query) + `app/Actions/Finance/{ShowCloseDayAction,ListClosesAction,CloseDayAction}.php` (`CloseDayAction`: one transaction, `pg_advisory_xact_lock(hashtext('close:'||date))`, `LOCK TABLE ledger_transaction IN SHARE MODE` before reading, upsert unless locked, CHECK-consistent row, audit).
- [X] T031 [US4] `app/Http/Requests/Dashboard/Finance/{CloseDayRequest,ListClosesRequest}.php`, `app/Http/Resources/Staff/DailyCloseResource.php`, `app/Http/Controllers/Api/V1/Dashboard/DailyCloseController.php`, routes `GET /dashboard/daily-close`, `GET /dashboard/daily-closes` (`day.close|wallet.view`), `POST /dashboard/daily-close` (`day.close`, `idempotent`); `#[OA]`; Postman. Run T029 green.

---

## Phase 7: US5 — The real Overview (P2)

**Goal**: one read-only endpoint feeding every remaining Overview figure, per permission. **Independent test**: spec US5 scenarios 1–3.

- [X] T032 [P] [US5] `tests/Feature/Overview/OverviewTest.php`: as CEO every section present and equal to the database (orders by state with count, value, held now from the ledger; earnings this Cairo month = commission + spread postings; needs-decision kinds oldest first, max 10; this month's counts and average days); as Operations no `earnings`; as Verification only identity documents in `needs_decision` and no `orders`/`earnings`; branch scope applied to `orders`; no key for first-sale advance or Rapaport; query count bounded.
- [X] T033 [US5] `app/Support/Overview/OverviewSections.php` + `app/Actions/Overview/ShowOverviewAction.php` (one query per section, per R10), `app/Http/Controllers/Api/V1/Dashboard/OverviewController.php`, route `GET /dashboard/overview` (auth:staff, standing; no extra permission); `#[OA]` (each section optional); Postman folder "Overview". Run T032 green.

---

## Phase 8: US6 — Orders export (P2)

- [X] T034 [P] [US6] `tests/Feature/Order/OrderExportTest.php`: filters `group`, `past_deadline`, `branch_id`, `q` give exactly the list's rows across all pages; a branch-assigned staff member exports only their branch; columns per research R11 incl. held now; cap with truncation line and `X-Export-Truncated`; audited `order.list_exported` with filters and count; without `order.view` 403.
- [X] T035 [US6] `app/Actions/Orders/Staff/ExportOrdersAction.php` reusing `ListOrdersAction`'s filtered query and branch scope; `DashboardOrderController@export`, route `GET /dashboard/orders/export` before `/{order}`; `#[OA]`; Postman folder "Orders". Run T034 green.

---

## Phase 9: US7 — Held per order (P2, Backend)

- [X] T036 [P] [US7] `tests/Feature/Wallet/HeldPerRequestTest.php`: two waiting requests and an accepted order → `deposit_held` on each resource equals the deposit; a left (released) request 0; a paid order 0; a forfeited deposit 0; the seller's order view `deposit_held: null`; `GET /customer/me/wallet/held` lists the three with amounts summing to the wallet's `held_on_orders` (with a pending withdrawal present, which is not listed); another customer's rows never appear (RLS); suspended customer may read (verified gate).
- [X] T037 [US7] `app/Support/Wallet/HeldByRequest.php` (one grouped query per page, `ledger` scope limited to the customer's own requests), `BuyRequestResource` + `CustomerOrderResource` + `deposit_held` (`#[OA]`), `app/Actions/Wallet/ListHeldItemsAction.php`, `app/Http/Resources/Customer/HeldItemResource.php`, `CustomerWalletController@held`, route `GET /customer/me/wallet/held` in the `customer.gate:verified` wallet group; Postman folder "Customer wallet". Run T036 green.

---

## Phase 10: US8 — Public prices and quote (P3, Backend)

- [X] T038 [P] [US8] `tests/Feature/Reference/PublicPricesTest.php`: `GET /reference/gold-prices` without auth → enabled karats with `sellers_get`/`buyers_pay` equal to the dashboard's current figures, `price_at`, `feed_state`; no bid/ask or adjustment fields; disabled karats absent; no usable price → `price_unavailable` 409; `GET /reference/quote` for 10 g 21K gold with a making charge → `payout` equals the spec 005 calculator's seller proceeds (the Part 3 §3 example to the piastre); gold with diamond and diamond categories; 422 for a disabled karat, weight ≤ 0 or > 10000, missing asking price; throttled (`public.market`); `Cache-Control` set.
- [X] T039 [US8] `app/Actions/Reference/{ShowGoldPricesAction,QuoteAction}.php` (through `PricingContext`), `app/Http/Requests/Reference/QuoteRequest.php`, `app/Http/Resources/Reference/{GoldPricesResource,QuoteResource}.php`, `ReferenceController@goldPrices|quote`, routes in the `throttle:public.market` reference group; `#[OA]`; Postman folder "Reference". Run T038 green.

---

## Phase 11: Dashboard (US1–US6)

- [X] T040 Types and services: `src/types/finance.ts` (compensation row/meta, adjustment, bank movement, bank book row/summary, day view, close), `src/types/overview.ts` (live shape replacing the mock type), `src/types/order.ts` (export), `src/types/staff.ts` (+ `walletAdjust`, `bankRecord`, `dayClose` in `PERMISSIONS`); `src/api/endpoints.ts`; `src/services/finance.service.ts` (list, export blob + filename + truncated, pay, adjust, upload, record, proof blob, bank book, day view, closes, close), `src/services/overview.service.ts`, `src/services/order.service.ts` + `exportCsv`; `src/services/errors.ts` + `day_not_ended`, `day_already_closed`, `compensation_cap_exceeded`, `insufficient_funds` messages.
- [X] T041 [P] Composables `src/composables/useFinance.ts` (Vue Query keys, invalidation after each POST) and `src/composables/useOverview.ts` (live, drops `overviewMock`); delete `src/mock/overview.ts`.
- [X] T042 [US1] `src/pages/compensation/index.vue` + `src/components/finance/{CompensationTable,CapsCard,PayCompensationModal}.vue`: period/reason/payer/customer filters, totals, caps and *Your remaining limit today*, Export; *Pay compensation* `DModal` with `useIdempotencyKey` (customer search, amount, reason, note, optional order) shown only with `compensation.pay`; cap error shows the figures.
- [X] T043 [US2] `src/components/customer-file/AdjustWalletModal.vue` (`DModal`, `useIdempotencyKey`; credit/debit, amount, reason) from the Customer file wallet panel, only with `wallet.adjust`; refresh the wallet after success.
- [X] T044 [US3] `src/pages/bankbook/index.vue` + `src/components/finance/{BankMovementForm,BankMovementsTable,BankBookTable}.vue`: *Record a movement* `DModal` (kind, amount, in/out, statement date, what it was for, proof upload → token) with `useIdempotencyKey`, only with `bank.record`; tabs *Recorded by hand* and *Everything through the bank* with summary, period filter, Export, proof viewer.
- [X] T045 [US4] `src/pages/closing/index.vue` + `src/components/finance/{CloseDayPanel,RecentClosesTable}.vue`: today's live figures (Close disabled), a day picker for ended days, the figures and difference preview, *Close the day* `DModal` (statement balance, explanation required to lock a non-zero difference, *Save without locking*) with `useIdempotencyKey`, recent days with Bank, Books, Difference, Closed by and explanation; days closed this month and last difference found.
- [X] T046 [US5] `src/pages/dashboard/overview.vue` + `src/components/dashboard/*`: earnings, transactions by state (Open → Orders filtered; Export → Orders export), needs-a-decision rows linking to their pages, each labelled with its area from the acting permission (e.g. *Withdrawals*, *Listings to review*) — never a role name, gold price from `/dashboard/gold-prices/current` (with `pricing.view`), this month; each block only when its key is present; *Paid out ahead of buyers* and the Rapaport row removed.
- [X] T047 [US6] `src/pages/orders/index.vue`: *Export* with the current filters (blob download, truncation toast).
- [X] T048 `src/router/index.ts` (Placeholder → the three pages, `meta.permission` unions) and `src/mock/nav.ts` (Compensation, Bank movements, Daily closing shown by permission); run `npm run type-check`, `npm run lint`, `npm run build`.

---

## Phase 12: Customer App (US7, US8, labels)

- [X] T049 [US7] `lib/models/wallet.dart` (+ `HeldItem`), the buy request and order models (+ `depositHeld`), `lib/services/api/wallet_api.dart` (+ `held()`), repositories + the mock repository updated.
- [X] T050 [US7] `lib/features/wallet/wallet_screens.dart` `HeldScreen`: the list of requests/orders with ref, title and amount (each opens its request or order), total from the Backend, the withdrawals block kept; the held line on the buy request and order screens from `depositHeld`; EN/AR strings and number patterns in `lib/core/i18n/`.
- [X] T051 [US8] `lib/models/prices.dart`, `lib/services/api/prices_api.dart` (`/reference/gold-prices`, `/reference/quote`), `lib/services/live_rates.dart` on the API (poll 60 s + on resume; flash on change; paused state), the sell draft's quote (debounced 400 ms) replacing `Pricing` maths for the estimate.
- [X] T052 [US8] Wire `home_screen.dart` `RateBar` and `_Calculator`, `splash_screen.dart` `_RateCards`, `sell1_screen.dart` estimate; remove their `MockMark`s; keep a `MockMark` only on parts without a Backend source (jeweller comparison, promo card); *Prices are paused* EN/AR; check `sell3_screen.dart`'s card and flag only what is still mock.
- [X] T053 Check `lib/models/wallet_labels.dart` labels `compensation` (*Compensation from Dahab*) and `reversal` (*Correction*) have Arabic; add the missing ones; state in the report that nothing else from this spec reaches the customer (no bank movement, close or staff list does).
- [X] T054 `test/test_app.dart` / `test/flows_test.dart`: fake endpoints for held, gold prices, quote; flows (Held lists lines summing to the total; prices shown; paused state; sell estimate from the quote); `dart format --line-length 180` on changed files; `flutter analyze`, `flutter test`, `flutter build web --release`.

---

## Phase 13: Polish & cross-cutting

- [X] T055 [P] `tests/Feature/Finance/FinancePermissionsTest.php`: every new route × (CEO, COO, Finance, Operations, Verification, IGI) per the seed; a frozen staff member refused; every POST without `Idempotency-Key` refused.
- [X] T056 [P] `tests/Feature/Finance/FinanceIsolationTest.php`: a customer reads only their own `wallet_adjustment` and `compensation` rows; no customer path reads `bank_movement` or `daily_close`; held items never include another customer's request.
- [X] T057 `tests/Feature/Finance/FinanceConcurrencyTest.php` (two connections, committed fixtures, then truncate with the keep lists; put back any setting changed): two debit adjustments at once; a debit adjustment vs a withdrawal submission; a debit adjustment vs a buy request deposit hold; two direct compensations by one payer against the day cap; a direct and a dispute compensation together; two closes of the same day; a close vs a ledger entry stamped before midnight committing after the close started (share lock) — never negative, never over cap, one close, snapshot consistent.
- [X] T058 `tests/Feature/Finance/FinanceReconciliationTest.php`: after every outcome `ledger_global_zero = 0`, bank cash = −SUM(bank), each row matches its entry, held per request sums to `held_on_orders`, a locked close is unchanged after later entries.
- [X] T059 [P] `tests/Feature/Performance/FinancePerformanceTest.php`: overview, bank book and compensation list < 300 ms p95 with 10,000 orders/postings; order export of 10,000 rows < 10 s.
- [X] T060 Confirm the six truncating tests (`OrderConcurrencyTest`, `OrdersPerformanceTest`, `WithdrawalConcurrencyTest`, `WithdrawalPerformanceTest`, `DisputeConcurrencyTest`, `DisputePerformanceTest`) still pass with the new tables (no transition tables added, keep lists unchanged).
- [X] T061 `database/seeders/LocalFinanceSeeder.php` (+ `DatabaseSeeder`, local only) through the real Actions: a direct compensation, a credit and a debit adjustment, `capital_in` and `bank_charge` movements (one with a proof), a locked and a saved close; run on `dahab_wt015_dev`.
- [X] T062 Quality gates (`laravel-quality-gates`): `./vendor/bin/pint --test`, the full suite sequentially on `dahab_wt015`, `migrate:fresh` + rollback check on `dahab_wt015` (refused with rows, clean when empty), `composer swagger:generate`, `php artisan route:list --path=dashboard`; N+1 review on the lists.
- [X] T063 CLAUDE.md "Current state" (Backend, Dashboard, Flutter lines for spec 015) and the roadmap memory; Postman README if variables were added.
- [X] T064 Step 5 report: per project, API classification, tests and builds, docs, what is still mock in each app; note that the main `dahab` DB needs `php artisan migrate` and `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder`.

## As built (notes)

- Notifications: one `WalletNotification` with its EN/AR text inline, like `PayoutNotification` (no `lang/` files exist for these).
- `UploadPurpose::BANK_MOVEMENT_PROOF` lives in the shared enum and is refused by the customer upload request (`isStaffOnly()`).
- The Orders export mirrors the list exactly: the list has a `branch_id` filter but no forced branch scope, so neither has the export.
- SQLSTATE DH011 is left unmapped: it surfaces as the generic 500 (logged); it can only fire on a bug.
- The bank book orders rows by the bank posting (monotonic) rather than `(created_at, ledger_txn_id)`: entries of one transaction share `now()`.
- `daily_close` audit rows carry the date in the payload (`audit_log.entity_id` is a UUID).
- The overview needed column aliases on every UNION part (a single visible kind otherwise loses them).
- Seven inventory tests were updated for the new routes, codes and audit events (PrincipalIsolation 151 → 169, OpenApiGeneration,
  AuditCatalogue, StaffDashboardAccess, StaffAuthorizationMigration, and LedgerSchemaTest's rollback order).
- Tests ran on `dahab_wt015` (full suite) and a scratch `dahab_wt015_iter` (owner `dahab`) while iterating; seeding on `dahab_wt015_dev`.
- Rollback check: refused on `dahab_wt015_dev` (rows exist); clean down/up on the empty scratch database.
- SC-006 (Orders export of 10,000 rows) is covered by the bank-book export at 10,000 rows in FinancePerformanceTest; no 10,000-order fixture.

## Dependencies & execution order

- Phase 1 → Phase 2 → stories. US1–US4 are independent of each other once Phase 2 is done (US1 touches `PayCompensationAction`, so its spec 014 tests must stay green). US5–US8 depend only on Phase 2.
- Backend API before each consumer: Phase 11 needs US1–US6 green; Phase 12 needs US7–US8 green.
- Phase 13 after all backend stories; T057/T058 need US1–US4.

## Parallel examples

- Phase 2: T008–T012 touch different files.
- US1: T013 + T014 together; US3: T022 + T023 + T024 together.
- US1, US2, US3, US4 in parallel (different Actions/controllers), US5–US8 likewise.
- Phase 11 (Dashboard) and Phase 12 (Customer App) in parallel once their backend stories are green.

## Implementation strategy

1. **MVP**: Phases 1–6 — the four finance tools (compensation, adjustment, bank book, daily close) through the API and Postman with concurrency-safe money.
2. Then the Overview and Orders export (US5–US6), the Customer App backend (US7–US8), the apps (Phases 11–12), and the polish.
