# Tasks: Orders — delivery, inspection, balance, settlement and collection

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/orders-api.md](./contracts/orders-api.md), [quickstart.md](./quickstart.md)

**Tests**: required (Constitution V; spec FR-029). Work test-first within each story: write the test, watch it fail for the expected reason, implement, then run it.

**Database rules**:
- Run artisan and the tests as the `dahab` DB user, never `postgres`.
- **Never** run the suite, `migrate:fresh`, a rollback or a seeder against the primary `dahab` database. Use the isolated database `dahab_wt012`, owned by `dahab`. Create it once: `CREATE DATABASE dahab_wt012 OWNER dahab;`.
- Run the suite **sequentially** (no `--parallel`).

**Paths**:
- Backend paths are relative to the repo root.
- Dashboard paths are under `../dahab-dashboard/` (branch `feature/orders`, created from `feature/buy-requests`; it is not checked out yet — add a worktree for it when Dashboard work starts).
- Flutter paths are under `../dahab-flutter/`, which has no git.

**Git**: `feature/orders` exists in both repos. Never commit or push unless told. Before each phase, check `git status` and file modification times in all three projects: another session may be writing the same tree.

**Every endpoint task** includes:
- its `#[OA\…]` attributes;
- its Postman request in `postman/Dahab-Backend.postman_collection.json`: the body in step with the FormRequest, the `Idempotency-Key` pre-request script on every POST, and saved-id test scripts such as `order_id` and `inspection_id`;
- the gate or permission in `CustomerRouteAccess` or on the route.

It is not done without them.

**Line endings**: LF. Use the editor tools, not text-mode scripts.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (docs first — Constitution III)

- [X] T001 Write `docs/features/orders.md` from `docs/features/_TEMPLATE.md`:
  - the impact summary and the classification from plan.md;
  - the 16 clarifications in short;
  - the endpoint list from contracts/orders-api.md;
  - the permissions and roles;
  - the follow-ups from plan.md;
  - links to `specs/012-orders/`.
- [X] T002 Update `docs/Database schema/04_schema_market.sql` §9–§11 (and the same text in `00_schema_full.sql`). Mark each change "spec 012", exactly as data-model.md:
  - **`"order"`** gains `locked_seller_unit_rate`, `decision_due_deadline`, `proposed_price`/`proposed_by`/`proposed_at` (CHECK all-or-none), the settlement figures (CHECK `order_settlement_shape`: all null or all set, set only in `ready_to_collect`/`completed`; `spread_amount` may be negative), `settlement_txn_id`/`forfeit_txn_id`/`release_txn_id` → `ledger_transaction`, `reach_reminder_sent_at`, `balance_reminder_sent_at`.
  - **New `order_state_change`**: columns as in data-model.md, append-only, plus the deferred `trg_order_change_recorded`.
  - `order_branch_change.reason` and `order_deadline_extension.reason` become `NOT NULL CHECK (char_length BETWEEN 10 AND 1000)`.
  - **`seller_cancellation`** gains UNIQUE(`order_id`) and `by_sweep BOOLEAN NOT NULL`.
  - **`inspection_result`** gains `is_counterfeit BOOLEAN NOT NULL DEFAULT false` and `stone_below_claim BOOLEAN NOT NULL DEFAULT false`, plus a unique partial index on `supersedes_id`.
  - **`settlement_decision`** gains UNIQUE(`inspection_id`).
  - **`collection`** and **`seller_return`** gain `code_encrypted TEXT NOT NULL`, `failed_attempts SMALLINT NOT NULL DEFAULT 0` and `locked_until TIMESTAMPTZ`. `seller_return` also gains `relisted_at TIMESTAMPTZ` with a CHECK that it is not set together with `collected_at`. The `return_deadline` comment changes to calendar weeks (Part 3 §1.3).
  - The deferred `trg_order_money` and the as-built note (reached states; `tax_invoice` not created; the `disputed` rows never reached).
- [X] T003 Update `docs/Database schema/05_schema_security.sql` (and `00_schema_full.sql`):
  - the `order_transition` rows `('awaiting_balance','weight_adjust_pending','corrected result needs the buyer''s approval (spec 012)')` and `('awaiting_balance','cancelled_inspection','corrected result: karat mismatch / counterfeit (spec 012)')`;
  - `assert_order_transition()` with SQLSTATE `DH006`, also freezing `locked_seller_unit_rate` and, once set, the settlement figures and the three `*_txn_id`;
  - the RLS block for the new tables and the `order` scope, every policy by name with its reason (research R2);
  - the listing-guard exception `listing_relist_measured` (research R13): only on `awaiting_seller_return → live` may `karat_code`/`stated_weight_g` change, to the latest inspection's measured figures.
  - *As built*: no listing-guard exception was needed — the spec 010 guard does not freeze `karat_code` / `stated_weight_g` (only the application blocks edits), so the relist updates them in the Action and records old → new in the history note.
- [X] T004 [P] Update `03_schema_ledger.sql`:
  - the unique partial indexes `one_balance_payment_per_order` and `one_deposit_forfeit_per_order`;
  - `deposit_release_allowed()` (accepted requests whose order is `cancelled_staff | cancelled_seller | cancelled_inspection`).

  Update `02_schema_identity.sql`: `customer.cancellations_reset_at`, and `repeated_cancellations` in `customer_suspended_reason_check`. Use the same text in `00_schema_full.sql`.
- [X] T005 Amend the Technical Spec, marking each change "Changed by spec 012" with a link:
  - **Part 1**:
    - §3.4: the IGI account is a Dashboard staff user, and branch scope comes from the assigned branch.
    - §4.1: the codes `order.view`, `order.receive`, `inspection.enter`, `order.price_adjust`, `order.change_branch`, `order.extend_deadline`, `order.handover`, `buy_request.view` with their seeds (research R22).
    - §5.1: the new tables, and the `order` scope with its recorded deviation.
  - **Part 2**:
    - §5: seller cancel, change-branch and extend-deadline as built, with the paths of research R1.
    - §6: the work list, receive, inspection result (flags, corrections), propose-price, decision.
    - §7: pay-balance (verified gate, insufficient-funds details, figures stored, code shown in the owner's detail), handover (422 `invalid_collection_code`, `handover_locked`), the seller-return handover, relist.
    - §10: the staff Orders, Inspections and Buy requests reads.
    - §11: `orders:sweep` with its six passes.
    - §12: the new codes, and `illegal_order_transition` mapped to DH006.
  - **Part 3**:
    - §1.3: return and collect windows in calendar time, as built.
    - §2.3/§3: locked rates — the seller's at acceptance; a negative spread is possible and absorbed by Dahab.
    - §5, §7: correction rules, the stone-regrade price by staff, an unanswered adjustment counts as a decline.
    - §9.1: the threshold counted since the last reinstatement, automatic, `repeated_cancellations`; karat/fake use `piece_misrepresented`.
    - §10.1: forfeit remainder → `dahab_commission`; relist with the measured figures.
    - §12: the jobs as built.
- [X] T006 [P] Update `docs/platform/api-contract.md`:
  - the new customer and dashboard endpoints;
  - the idempotency "in use on" list;
  - the new error codes (including 422 `invalid_collection_code`, 429 `handover_locked` with `retry_after`);
  - the `order` scope;
  - the permission list (+8);
  - the audit events.
- [X] T007 [P] Create `config/dahab-orders.php` with `reach_reminder_working_hours 3`, `balance_reminder_hours 24`, `near_expiry_hours 6`, `handover_max_attempts 5`, `handover_lock_minutes 15`, `code_length 6`.

---

## Phase 2: Foundational (blocks every story)

- [X] T008 Write `tests/Feature/Order/OrderSchemaTest.php` (real Postgres, maintenance scope, direct SQL; deferred triggers fired with `SET CONSTRAINTS ALL IMMEDIATE`, as `BuyRequestSchemaTest`). It proves:
  - every move not in `order_transition` raises DH006, and each allowed move passes; the two new rows exist;
  - frozen columns: `locked_seller_unit_rate`, and the settlement figures once set, raise DH006; `order_settlement_shape` refuses a partial set;
  - `trg_order_change_recorded`: a state change with no `order_state_change` row in the transaction is refused at commit;
  - `trg_order_money`: `cancelled_seller`/`cancelled_inspection` without a release, `cancelled_buyer_nopay` without a forfeit, and `ready_to_collect` without a `balance_payment` are each refused at commit;
  - a second `balance_payment` or `deposit_forfeit` for one order is refused (unique);
  - `deposit_release_allowed()` accepts a release on an accepted request whose order is `cancelled_seller`/`cancelled_inspection` and refuses it otherwise;
  - `inspection_result`: `inspection_no_update` refuses UPDATE/DELETE; `karat_rule` and `karat_mismatch_forces_cancel` refuse inconsistent rows; two rows cannot supersede the same row;
  - `seller_cancellation` is unique per order; `settlement_decision` is unique per inspection;
  - the `seller_return` CHECK refuses both `collected_at` and `relisted_at`;
  - `listing_relist_measured`: changing `stated_weight_g` on any other move is still refused (DH004).
  - *As built*: the `stated_weight_g` guard case was dropped (see T003); everything else is covered in `tests/Feature/Order/OrderSchemaTest.php`.
- [X] T009 Write `database/migrations/2026_10_05_000010_create_orders_lifecycle.php` mirroring T002–T004 verbatim:
  - Create: the 8 tables, the `"order"`/`collection`/`seller_return`/`customer` columns, the 2 order transition rows, `assert_order_transition()` redefined (DH006), `trg_order_change_recorded`, `trg_order_money` (setting its own read scope, like spec 011's deferred triggers), the unique indexes, the new `deposit_release_allowed()`, the suspension-reason CHECK, the listing-guard relist exception.
  - RLS: `ENABLE` + `FORCE` on every new table; the isolation policies and the `order` scope policies (data-model.md "RLS"), in the `(SELECT dahab_rls_scope())` InitPlan form.
  - **Backfill**: `locked_seller_unit_rate` on existing `awaiting_delivery` orders from the current gold price row and adjustments (`sellers_get`/`mid` per category), by the system actor, explained in the docblock.
  - **`down()`**: refuses while any order is outside `awaiting_delivery`/`cancelled_staff` or any new table has rows; otherwise restores the spec 011 functions and drops in reverse.
  - **Verify**: run `migrate:fresh --seed` and a rollback on `dahab_wt012`; T008 passes.
  - *As built*: `locked_seller_unit_rate` is **not** backfilled — orders accepted before spec 012 (local data only) settle on the seller rate current at payment (`OrderSettlement::sellerRate`, analysis U1). Added during implementation: `listing_state_change_order_read` (INSERT … RETURNING needs a SELECT policy) and `seller_cancellation.cancelled_at DEFAULT clock_timestamp()` (compared with the reinstatement moment to the microsecond).
- [X] T010 [P] Enums:
  - `app/Enums/OrderEvent.php` (the cases of research R20), `InspectionOutcome.php` (5 cases), `DeadlineKind.php` (`reach_branch`, `decision`, `balance`, `collect`, `return`).
  - `OrderState` gains `isOpen()`, `isFinal()`, `group()`, `customerStage()`.
  - `SuspendedReason::REPEATED_CANCELLATIONS = 'repeated_cancellations'`, with its customer wording (EN/AR).
  - `StaffPermission`: the 8 codes with labels, group "Orders" and the `seedRoles()` of research R22 ("CEO holds every code"); inserted into existing installs the way specs 009–011 did.
  - `AuditEvent`: `order.received`, `order.branch_changed`, `order.deadline_extended`, `order.price_proposed`, `order.handed_over`, `order.handover_failed`, `order.return_handed_over`, `inspection.result_recorded`, and the customer-actor events `order.seller_cancelled`, `order.decided`, `order.paid`, `order.relisted` (analysis C1), all in category `orders`.
  - Update `tests/Feature/Authorization/PermissionCatalogueTest.php` and the audit catalogue tests.
- [X] T011 [P] Models:
  - `app/Models/{OrderStateChange,OrderBranchChange,OrderDeadlineExtension,SellerCancellation,InspectionResult,SettlementDecision,Collection,SellerReturn}.php`: singular tables, UUID keys, no Laravel timestamps, `decimal:4`/`decimal:3` casts, enums, `immutable_datetime`.
  - `Collection`/`SellerReturn`: `code_encrypted` with the `encrypted` cast, hidden by default.
  - `Order` gains its relations, new casts, a `latestInspection()` that ignores superseded rows, `isParty(Customer)`.
  - `Customer` gains `cancellations_reset_at`.
- [X] T012 [P] Factories `database/factories/{InspectionResultFactory,…}.php` plus an `OrderJourney` test helper (`tests/Support/OrderJourney.php`). It drives the real Actions — fund wallets, list (spec 010), request and accept (spec 011), receive, result, decide, pay, sweep — to reach any state, so every deferred trigger passes. Uses `Carbon::setTestNow` for deadlines.
  - *As built*: no new factories — `tests/Support/Orders.php` drives every state through the real API (request, accept, receive, result, pay, sweep), as `tests/Support/BuyRequests.php` does for spec 011.
- [X] T013 Extend `app/Support/DatabaseActor.php` with the `order` scope (in `SCOPES`, not `ELEVATED`) and `DatabaseActor::order(Closure)`, like `queue()`. Write `tests/Feature/Order/OrderScopeTest.php`. In the `order` scope, a party:
  - reads the counterparty's request and `display_ref` for their own order only;
  - can move the listing of their order only to `sold`/`awaiting_seller_return` (buyer);
  - sees nothing of an order they are not party to (0 rows) and no other customer table.

  Also: a commit with the outer frame in plain `customer` scope still passes the deferred checks. Static checks (extend `ElevationTest`): `DatabaseActor::order(` appears only under `app/Actions/Orders/Customer/`; no file under `app/Actions/Orders/Customer/` calls `DatabaseActor::elevate`; and every Action that pushes the `order` scope records an `order.*` audit event (analysis C1 — assert by running each customer write once and reading `audit_log`).
- [X] T014 [P] Add to `app/Exceptions/DomainApiException.php` the codes of research R21:
  - `illegalOrderTransition()`, `wrongBranch()`, `orderNotOpen()`, `deadlineNotRunning()`, `deadlineMustMoveForward()`, `inspectionCorrectionNotAllowed()`, `priceNotSet()`, `balanceDeadlinePassed()`, `settlementNotPossible()`, `invalidCollectionCode(int $attemptsLeft)` (422), `handoverLocked(int $retryAfter)` (429);
  - `insufficientFunds()` gains the `amount_due` details variant.

  Map SQLSTATE `DH006` → `illegalOrderTransition()` in `bootstrap/app.php`, in both mapping blocks. Adjust `tests/Feature/BuyRequest/CancelAcceptanceTest.php`, which asserted `illegal_buy_request_transition` for order moves, if any.
- [X] T015 Create the support classes in `app/Support/Orders/`:
  - `OrderTransitions` (loads `order_transition`; `allows()`);
  - `StaffBranchScope` (`assertCanActAt(Staff, int)`, `branchFilterFor(Staff)`; research R5);
  - `CollectionCodes` (`issue(): array{plain, hash, encrypted}`, `matches(string $plain, string $hash)` with HMAC + `hash_equals`, `registerFailure(Model)`, `assertNotLocked(Model)`; research R10);
  - `DeadlinePolicy` (balance, decision, collect and return deadlines from the settings, in Cairo calendar time; `reachRemainingWorkingMinutes()` through the resolver);
  - `OrderCursor` (like `ListingCursor`).

  Also `app/Actions/Orders/Concerns/MovesOrder.php`: `moveOrder(Order, OrderState, actor, ?note)` writes the state, then the `order_state_change` row; it refuses moves `OrderTransitions` does not allow with `illegalOrderTransition`.
  - *As built*: `OrderCursor` was not needed — `App\Support\Listings\ListingCursor` (a timestamp + a UUID) is reused. `StaffBranchScope::guard()` checks and audits a wrong branch before the transaction (an audit row inside it would roll back with the refusal). It is not spec 002's `BranchScope` (which grants nothing without a branch).
- [X] T016 Price lock and settlement maths:
  - Add `PriceCalculator::lockedBreakdown(Piece $piece, ?string $buyerRate, ?string $sellerRate, PricingRates $rates): PriceBreakdown`, reusing `finish()`. Gold uses the two locked per-gram rates. Gold-with-diamond uses the locked mid for the protected value. Diamond uses the asking or proposed price.
  - Write `tests/Unit/Pricing/LockedBreakdownTest.php`: Part 3 §3.3 (10.000 g → 55,631.2500 / 55,368.7500 / 262.5 / 600 / 84 / 54,684.7500) and §3.4 (9.900 g → 55,074.9375 / 54,815.0625 / 259.8750 / 594 / 83.1600 / 54,137.9025) to the piastre; a negative spread when `sellerRate > buyerRate`; a stones case; and a 10,000-sample property that `buyer_total − proceeds − commission − vat − spread = 0`.
  - Create `app/Support/Orders/OrderSettlement.php`: `compute(Order, InspectionResult, PricingRates): SettlementFigures` (`buyer_total`, `seller_gross`, `commission`, `vat`, `spread`, `proceeds`, `balance = max(total − deposit, 0)`, `excess = max(deposit − total, 0)`, `final_weight`), and `post(Order, SettlementFigures, Customer $buyer): LedgerTransaction`, building the `balance_payment` entry of research R7 (zero lines omitted, accounts resolved in `DatabaseActor::ledger()`).
- [X] T017 Change `app/Actions/BuyRequests/AcceptBuyRequestAction.php` to store `locked_seller_unit_rate` from the same `PricingContext` as the quote: `sellersGet` for gold, `mid` for gold-with-diamond, null for diamond. Extend `tests/Feature/BuyRequest/AcceptBuyRequestTest.php` to assert it. Change `app/Actions/Orders/CancelAcceptanceAction.php` to move the order through `MovesOrder`, so the `order_state_change` row is written.
- [X] T018 [P] Create `app/Notifications/OrderNotification.php` (`OrderEvent`, SMS + mail, queued, `afterCommit`, the customer's language, the order ref and piece; never the counterparty's identity), following `BuyRequestNotification`, plus EN/AR message templates for every event of research R20. Create `app/Support/Orders/OrderTimeline.php`, which merges the history sources for one order (research R18) for both customer and staff Resources, with a `forCustomer` flag that drops staff names and ledger internals.
- [X] T019 Create `app/Actions/Orders/SuspendSellerAction.php` with `handle(string $sellerId, SuspendedReason $reason, string $note, Staff $actor)`:
  - It runs inside the caller's transaction, which is always a staff-scope one: the sweep's suspension pass (system actor) or the inspector's result (inspector). It is never called from a customer path (analysis C2).
  - It locks the customer and does nothing if they are already suspended.
  - It calls `Customer::suspend()` and `HoldListingsOfCustomerAction::hold()`, writes an audit `auth.customer.suspended` row with the actor, and sends the existing suspension notice after commit.
  - It reuses `SuspendCustomerAction`'s inner steps (extract a shared `SuspendsCustomer` concern; `SuspendCustomerAction` keeps its HTTP behaviour and tests).

  Change `app/Actions/Customers/ReinstateCustomerAction.php` to set `cancellations_reset_at = now()`, and extend `tests/Feature/CustomerFile/ReinstateCustomerTest.php`.
  - *As built*: `SuspendSellerAction` is self-contained (not a shared concern with `SuspendCustomerAction`, whose HTTP behaviour is unchanged). No suspension notification exists in the codebase; the app shows the suspension notice (spec 007). `repeated_cancellations` is excluded from the staff suspend request (`SuspendedReason::staffChoices()`). The reset stamp is written as `clock_timestamp()` by `ReinstateCustomerAction`.
- [X] T020 Create `app/Actions/Orders/OpenSellerReturnAction.php` with `open(Order, Listing, ?LedgerTransaction $compensation, actor): array{return: SellerReturn, code: string}`:
  - it moves the listing to `awaiting_seller_return` via `MovesListing`;
  - it inserts `seller_return` with the order's branch, a code from `CollectionCodes`, `return_deadline` from `DeadlinePolicy` and `compensation_txn_id`.

  Create `app/Actions/Orders/ReleaseOrderDepositAction.php`, which wraps `DepositLedger::release($request, …, orderId)` and sets `order.release_txn_id`.
- [X] T021 Routes and controllers skeleton:
  - `app/Http/Controllers/Api/V1/Customer/OrderController.php`, `Dashboard/OrderController.php` (extend the spec 011 one), `Dashboard/InspectionController.php`, `Dashboard/BuyRequestController.php`.
  - Route groups in `routes/api.php` with `idempotent` on every POST, the permissions as `permission:` middleware with `|` alternatives, and the customer gates in `CustomerRouteAccess` (verified for reads, cancel, decision and pay; trade for relist).
  - Extend `tests/Feature/Isolation/CustomerRouteGateTest.php` to expect the gates.
- [X] T022 Resources:
  - `app/Http/Resources/Customer/CustomerOrderResource.php` (OrderItem of the contract; `actions[]`; the code only via a `withCode()` toggle used by the detail action);
  - `Staff/StaffOrderResource.php`, `Staff/StaffOrderDetailResource.php` (including `can{}` computed from the caller's permissions and branch scope);
  - `Staff/InspectionResultResource.php`, `Staff/WorkListItemResource.php` (no money, no names);
  - `Staff/StaffBuyRequestResource.php`.

  Each carries its `#[OA\Schema]`.

  - *As built*: the Collection model is `OrderCollection` (avoids clashing with Laravel's Collection). Receive / result / handover answer `StaffOrder` for holders of `order.view`, else the money-free `WorkListItem`.
**Checkpoint**: the schema, the guards, the scope, the maths and the shared Actions are in place; the stories can start.

---

## Phase 3: User Story 1 — The seller brings the piece and the branch receives it (P1) 🎯 MVP

**Goal**: both parties see their orders; staff at the branch mark a piece received.
**Independent test**: an accepted order shows to both parties with its deadline; a Nasr City staff member receives it, and the order and the listing move to `at_inspection`; another branch's staff get `wrong_branch`.

- [X] T023 [P] [US1] Write `tests/Feature/Order/CustomerOrdersReadTest.php`:
  - the buyer and the seller each list and open their orders (role filter, group filter, newest first, cursor);
  - a third customer gets 404 on the detail and an empty list (RLS — assert through `OrderIsolationTest` too);
  - the counterparty appears only as `counterparty_ref`; the seller has no `amount_due` and the buyer no `seller_proceeds`;
  - the deadline is `reach_branch`; the branch has its hours; `actions` = `["cancel"]` for the seller and `[]` for the buyer.
- [X] T024 [P] [US1] Write `tests/Feature/Order/ReceivePieceTest.php`:
  - `order.receive` holders with no branch and with the order's branch receive it: the order is `at_inspection` with an `order_state_change` row naming the staff member; the listing is `at_inspection` with a history row; audit `order.received`; `OrderNotification` `received` to both;
  - another branch → 403 `wrong_branch`, audited; no permission → 403; a second receive → 409 `illegal_order_transition`; the same key twice → replayed once.
- [X] T025 [P] [US1] Write `tests/Feature/Order/OrderIsolationTest.php`: forced RLS on every new table — each customer sees only rows of their own orders, in the customer scope, with direct queries. Extend `tests/Feature/Isolation/CustomerTableIsolationTest.php` with the 8 tables.
- [X] T026 [US1] Implement `app/Actions/Orders/Customer/ListOwnOrdersAction.php` and `ShowOwnOrderAction.php`:
  - read the rows under plain isolation, then the piece summaries and counterparty refs inside `DatabaseActor::order()`;
  - keyset by `accepted_at DESC, order_id`; `group` open/closed;
  - the code is decrypted only for the owner (the buyer while `ready_to_collect`; the seller while a return is open).

  Implement `GET /customer/me/orders` and `/{order}` in `Customer/OrderController` with `ListOrdersRequest` (`role in:buyer,seller`, `group in:open,closed`, `limit 1..100`, `cursor`).
- [X] T027 [US1] Implement `app/Actions/Orders/Staff/ReceivePieceAction.php`:
  - one transaction in the staff scope: lock the listing → lock the order → `StaffBranchScope::assertCanActAt` → state must be `awaiting_delivery`;
  - `MovesOrder` → `at_inspection`; `MovesListing` `accepted → at_inspection`;
  - audit, then notify both after commit.

  Implement `POST /dashboard/orders/{order}/receive` (permission `order.receive`), answering with `StaffOrderDetailResource`. `ShowOrderAction` (staff detail) is created here and is also used by US9.

---

## Phase 4: User Story 2 — A seller who cannot deliver cancels, or misses the deadline (P1)

**Goal**: the seller's cancel and the reach-branch sweep refund the buyer, record the cancellation and withdraw the listing; the sweep's suspension pass suspends at the threshold; plus the reach-branch reminder.
**Independent test**: a cancel refunds the buyer; a second order missing its deadline is cancelled by `orders:sweep`, and the same run suspends the seller with `repeated_cancellations`.

- [X] T028 [P] [US2] Write `tests/Feature/Order/SellerCancelTest.php`:
  - the seller cancels: `cancelled_seller`; buyer held −d and available +d (`GET /customer/me/wallet` for the buyer); `release_txn_id` set; a `seller_cancellation` row with `by_sweep = false`; the listing is `withdrawn` with a history row; `order_state_change` names the seller; one audit row `order.seller_cancelled` with the seller as actor and no other actor anywhere in the request; `seller_cancelled` sent to the buyer only; the seller is **not** suspended by this request even at the threshold;
  - a suspended seller may still cancel (verified gate);
  - the buyer → 404; another state → 409; replay once.
- [X] T029 [P] [US2] Write `tests/Feature/Order/ReachBranchSweepTest.php`:
  - with the clock past the deadline, `php artisan orders:sweep` cancels the order as the system actor (`by_sweep = true`), then the results are read through the API;
  - an order whose deadline was extended, or that was received, is untouched;
  - running it twice changes nothing more.
- [X] T030 [P] [US2] Write `tests/Feature/Order/CancellationSuspensionTest.php`:
  - two explicit cancellations leave the seller active until `orders:sweep` runs; the run suspends them with `repeated_cancellations` by the system actor, in its own transaction; their live listings are held (spec 010) and their reserved queues released (spec 011); one `auth.customer.suspended` audit row by the system actor;
  - one explicit cancellation plus one sweep cancellation in the same run → suspended in that run (pass 0 runs again after pass 1);
  - an already suspended seller is skipped; a second run changes nothing;
  - after a reinstatement the count restarts (one more cancellation does not suspend);
  - the threshold is read live (set it to 3 → no suspension at 2);
  - their other open orders are untouched.
- [X] T031 [US2] Implement `app/Actions/Orders/Customer/CancelOrderBySellerAction.php` with `handle(Customer|null $seller, string $orderId, bool $bySweep)`:
  - **Customer path**: `DatabaseActor::order(fn () => DB::transaction(…))`. **Sweep**: the system actor in the staff scope.
  - Lock the listing → order → request; the state must be `awaiting_delivery` (for the sweep, also deadline ≤ now).
  - `MovesOrder` → `cancelled_seller`; `ReleaseOrderDepositAction`; the `seller_cancellation` row; `MovesListing` `accepted → withdrawn` (note `seller_cancelled` / `deadline_missed`).
  - Customer path: the audit row `order.seller_cancelled` (actor = the seller). No suspension here (analysis C2).
  - Notify after commit.

  Implement `POST /customer/me/orders/{order}/cancel`.
- [X] T032 [US2] Create `app/Console/Commands/SweepOrders.php` (`orders:sweep`, scheduled every minute `withoutOverlapping()` in `routes/console.php`). Start with the suspension pass, the reach-branch pass and the reach reminder pass:
  - suspension (pass 0, run before and after the reach-branch pass): sellers not suspended with at least `suspension.cancellations_threshold` `seller_cancellation` rows since `cancellations_reset_at` → one transaction each as the system actor: lock the customer, re-count, `SuspendSellerAction(REPEATED_CANCELLATIONS)`;
  - due ids `awaiting_delivery AND reach_branch_deadline <= now()`, each handled by T031 in its own transaction;
  - reminder: `reach_reminder_sent_at IS NULL` and `DeadlinePolicy::reachRemainingWorkingMinutes() <= 180` → `reach_reminder` to the seller, and stamp the column.

  Write `tests/Feature/Order/RemindersTest.php` (reach part): it is sent once, it is not sent after a receive, and it is sent again after an extension clears the stamp.

---

## Phase 5: User Story 4 — The inspector records the result (P1)

**Goal**: an immutable result with the derived outcome and its effects; corrections; the work list; the stone-regrade price.
**Independent test**: 9.900 g → `pass` → `awaiting_balance` with the balance on 9.900 g; 9.700 g → `weight_adjust_pending`; 18K → `cancelled_inspection`, the buyer refunded, the seller suspended.

- [X] T033 [P] [US4] Write `tests/Feature/Order/InspectionResultTest.php` (dataset over the outcomes):
  - **pass** (|diff| ≤ 1.5%) → two `order_state_change` rows, `awaiting_balance`, `balance_due_deadline` = +10 Cairo calendar days, listing `settling`;
  - **weight_adjust** → `weight_adjust_pending`, `decision_due_deadline` +10 days, the customer detail shows `new_price` = the locked rates × the measured weight;
  - **stone_regrade** (`stone_below_claim` on a diamond) → `weight_adjust_pending`, no decision deadline, `price_pending = true`;
  - **karat_cancel** (any karat difference, even with the counterfeit flag) and **fake_cancel** → `cancelled_inspection`; the buyer refunded; the seller suspended `piece_misrepresented` with the inspector as actor; the listing `awaiting_seller_return`; a `seller_return` without compensation and with a code; both told;
  - the stated figures are copied in; `karat_mismatch` and `weight_diff_pct` are derived; the client cannot send `outcome`;
  - validation: gold needs a measured karat and weight;
  - `wrong_branch` 403; the wrong state 409; replay once; audit `inspection.result_recorded`.
- [X] T034 [P] [US4] Write `tests/Feature/Order/InspectionCorrectionTest.php`:
  - a correction superseding the latest result while `awaiting_balance` (pass → weight_adjust) moves to `weight_adjust_pending`; pass → karat → `cancelled_inspection` with the refund and the suspension; pass → pass with a new weight keeps the state and the deadline;
  - superseding a non-latest result, after a decision, or after payment → 409 `inspection_correction_not_allowed`;
  - the old row is unchanged, and the detail flags it superseded.
- [X] T035 [P] [US4] Write `tests/Feature/Order/ProposePriceTest.php`:
  - `order.price_adjust` sets the price on a regrade → `decision_due_deadline` is set, and the buyer (asked) and the seller are told; audit;
  - on a weight adjust or a second time → 409; `price` ≤ 0 → 422; `reason` outside 10–1000 → 422.
- [X] T036 [P] [US4] Write `tests/Feature/Order/WorkListTest.php` and `tests/Feature/Order/InspectorLeakTest.php`:
  - the work list shows the caller's branch only (all branches without a branch, filterable), with each task;
  - no response an `igi_branch` user can reach (work list, inspections list, inspection-result response) contains a price, an amount, a name, a phone or an email (recursive key and value scan).
- [X] T037 [US4] Implement `app/Actions/Inspections/RecordInspectionResultAction.php` per research R9:
  - lock the listing → order; check the branch scope and the state/correction rules;
  - derive the outcome from `inspection.weight_tolerance_pct` (read live); insert the row;
  - apply the effects through `MovesOrder`/`MovesListing`, `DeadlinePolicy`, `ReleaseOrderDepositAction`, `SuspendSellerAction` and `OpenSellerReturnAction`;
  - audit; notify (`result_passed`/`result_adjust`/`result_regrade_pending`/`result_cancelled`, plus `return_waiting` with the code to the seller).

  Implement `POST /dashboard/orders/{order}/inspection-results` with `RecordInspectionResultRequest`:
  - `measured_karat nullable|integer|exists:karat,karat_code` (required for gold and gold-with-diamond);
  - `measured_weight_g nullable|decimal:0,3|gt:0` (required for gold and gold-with-diamond);
  - `measured_stone_grade nullable|string|max:100`, `certificate_number nullable|string|max:100`, `inspector_note nullable|string|max:2000`;
  - `is_counterfeit boolean`, `stone_below_claim boolean`, `supersedes_id nullable|uuid`.

  Answer 201 with `InspectionResultResource` and `order_state`.
  - *As built*: the seller gets a dedicated `result_buyer_deciding` message while the buyer decides (new `OrderEvent` case).
- [X] T038 [US4] Implement `app/Actions/Orders/Staff/ProposePriceAction.php` and `POST /dashboard/orders/{order}/propose-price` (`order.price_adjust`; `ProposePriceRequest`: `price required|decimal:0,4|gt:0`, `reason required|string|between:10,1000`).
- [X] T039 [US4] Implement `app/Actions/Inspections/InspectionWorkListAction.php` and `GET /dashboard/inspections/work-list` (`inspection.enter|order.receive|order.handover`; tasks per research R18; branch filter via `StaffBranchScope`).

---

## Phase 6: User Story 6 — The buyer pays the balance and the seller is paid (P1)

**Goal**: the settlement at pay-balance, through escrow, with the figures stored and the code issued.
**Independent test**: Part 3 §3.3 end to end through HTTP: the wallets, the order's settlement figures and the ledger lines to the piastre.

- [X] T040 [P] [US6] Write `tests/Feature/Order/SettlementMathTest.php`:
  - set the gold price (bid = ask = 5,994) and the 21K adjustments (∓13.125 fixed); a 10.000 g ring at 300/g; commission 20%, VAT 14%, minimum 200;
  - go through request → accept → receive → pass 10.000 g → pay;
  - assert: buyer available −44,505.0000 and held −11,126.2500; seller available +54,684.7500; `dahab_commission` +600, `vat_payable` +84, `dahab_spread` +262.5; escrow net 0; one `balance_payment` with exactly the 8 lines of research R7; the stored figures; `ledger_global_zero` 0;
  - repeat on 9.900 g (§3.4): balance 43,948.6875, proceeds 54,137.9025;
  - a gold price change between acceptance and payment changes nothing (locked rates);
  - a commission rate change before payment applies (read live);
  - a crossed-rates case posts a negative spread and the seller still gets the locked figure.
- [X] T041 [P] [US6] Write `tests/Feature/Order/StoneSettlementTest.php`:
  - a diamond: no spread line; commission is `stone_pct` of the asking price (minimum 200);
  - a gold-with-diamond: commission on the asking price minus the gold value at the locked mid × the measured weight;
  - a regrade with an accepted proposed price settles on that price;
  - a total below the deposit → no available debit, and the excess back to available in the same transaction.
- [X] T042 [P] [US6] Write `tests/Feature/Order/PayBalanceTest.php`:
  - `ready_to_collect`; `collect_deadline` +3 calendar weeks; the listing `sold`; a `collection` row with hash + encrypted; the response and the buyer's detail carry the 6-digit `collection_code`; the list response never does; the seller's detail shows `seller_proceeds` and never the code;
  - `paid` to the seller, `collection_code` by SMS to the buyer; one audit row `order.paid` with the buyer as actor;
  - `insufficient_funds` with `amount_due`/`available`/`shortfall`, nothing changed;
  - past the deadline → `balance_deadline_passed`;
  - the seller or a stranger → 404; the wrong state → 409; a suspended buyer can pay; replay once; `settlement_not_possible` when the proceeds would be ≤ 0 (forced with a high minimum commission).
- [X] T043 [US6] Implement `app/Actions/Orders/Customer/PayBalanceAction.php` per research R16:
  - `DatabaseActor::order` + a transaction; lock the listing → order → request; check the state and the deadline;
  - `OrderSettlement::compute` + `post` (catch `insufficientFunds` and rethrow it with the details);
  - store the figures and `settlement_txn_id`; `MovesOrder` → `ready_to_collect`; `MovesListing` `settling → sold` (buyer actor, `order` scope policy); audit `order.paid` (actor = the buyer);
  - `CollectionCodes::issue` → `collection`; notify after commit.

  Implement `POST /customer/me/orders/{order}/pay-balance`, answering 200 with `CustomerOrderResource::withCode()`.
- [X] T044 [US6] Add the balance reminder pass to `SweepOrders`: `awaiting_balance`, `balance_reminder_sent_at IS NULL`, `balance_due_deadline − now ≤ 24 h` → `balance_reminder` to the buyer, and stamp. Extend `RemindersTest.php`.

---

## Phase 7: User Story 7 — A buyer who never pays forfeits the deposit; the piece goes back (P1)

**Goal**: the no-pay sweep's forfeiture split, the seller's return, collect or relist, and the return window.
**Independent test**: the sweep forfeits 11,640 → seller +5,820, `dahab_commission` +5,820; a return with a code; the seller relists or collects.

- [X] T045 [P] [US7] Write `tests/Feature/Order/ForfeitureSweepTest.php`:
  - past `balance_due_deadline`, `orders:sweep` → `cancelled_buyer_nopay` by the system actor; one `deposit_forfeit` (buyer held −d, seller available +round½↑(d × 50%), `dahab_commission` + the rest; an odd-piastre deposit puts the residue on Dahab); `forfeit_txn_id`; no release;
  - the listing `awaiting_seller_return`; a `seller_return` with `compensation_txn_id` and `return_deadline` +3 calendar weeks; `forfeited` to both and `return_waiting` with the code to the seller;
  - the share is read live (set it to 40%);
  - running it twice changes nothing; a payment that wins the race leaves nothing for the sweep.
- [X] T046 [P] [US7] Write `tests/Feature/Order/SellerReturnTest.php`:
  - the seller's detail shows the `return_code` and `can_relist`;
  - **relist** (trade gate): the listing `live` with an empty queue; a gold listing takes the measured karat/weight (history note old → new); `relisted_at` set; the market shows it; one audit row `order.relisted` with the seller as actor; a suspended seller → 403 `account_suspended`;
  - **staff handover** with the seller's code (`order.handover`, branch-scoped) → listing `withdrawn`, `collected_at`, `handover_by`, audit `order.return_handed_over`; a wrong code → 422 with attempts left; 5 wrong → 429 for 15 minutes;
  - the return window passes → `orders:sweep` → listing `seller_unclaimed`, `return_window_passed` to the seller; a staff handover from `seller_unclaimed` → `withdrawn`;
  - relist or handover twice → 409.
- [X] T047 [US7] Implement `app/Actions/Orders/ForfeitDepositAction.php` per research R13, plus the no-pay pass and the seller-return pass in `SweepOrders`.
- [X] T048 [US7] Implement `app/Actions/Orders/Customer/RelistReturnedPieceAction.php` (`POST /customer/me/orders/{order}/relist`, trade gate; seller-owned listing move under the owner policy; the measured figures through the guard exception; `NotifyWhenFreeAction` dispatch; audit `order.relisted`) and `app/Actions/Orders/Staff/HandoverReturnedPieceAction.php` (`POST /dashboard/orders/{order}/seller-return/handover`, `HandoverRequest`: `code required|digits:6`).

---

## Phase 8: User Story 9 — Staff run orders from the Dashboard (P1)

**Goal**: the staff reads (Orders list/detail, Inspections list) and the Dashboard Orders and Inspections pages, live with every action.
**Independent test**: a staff member with `order.view` filters "Past deadline", opens an order, sees the timeline, and sees only the actions their permissions allow.

**Backend**

- [X] T049 [P] [US9] Write `tests/Feature/Order/StaffOrdersListTest.php`:
  - every `group` and its count; `past_deadline`; `branch_id`; `q` by order ref and by a customer display ref; keyset order; the columns of the contract;
  - Finance (`order.view`) sees amounts; no `order.view` → 403 with an audit row.
  - Detail: the timeline merges every source in time order; the ledger lines per party; `can{}` reflects permissions and branch; codes never appear.
- [X] T050 [P] [US9] Write `tests/Feature/Order/InspectionsListTest.php` (filters, default 30 days, superseded flagged, permission alternatives) and `tests/Feature/Order/OrderPermissionsTest.php` (every new staff endpoint × with and without its permission; seeded roles hold what research R22 says; the CEO holds everything).
- [X] T051 [US9] Implement `app/Actions/Orders/Staff/ListOrdersAction.php` (the groups and counts in one grouped query, `past_deadline` via the running deadline per state, the index-backed keyset), `ShowOrderAction` (complete with the timeline and the ledger), and `app/Actions/Inspections/ListInspectionsAction.php`. Add the routes `GET /dashboard/orders`, `/dashboard/orders/{order}`, `/dashboard/inspections` with their Requests.

**Dashboard** (`../dahab-dashboard`, branch `feature/orders`)

- [X] T052 [P] [US9] Types and services:
  - `src/types/order.ts` (`StaffOrderItem`, `StaffOrderDetail`, `InspectionResult`, `WorkListItem`, the `OrderState` union, `OrderGroup`);
  - `src/types/staff.ts` (+8 permission strings);
  - `src/api/endpoints.ts` (the orders, inspections and buy-requests paths);
  - `src/services/order.service.ts` and `src/services/inspection.service.ts` (snake_case ↔ camelCase mapping);
  - `src/services/errors.ts` (the new codes with user wording; `illegal_order_transition`);
  - the audit category/event labels;
  - the listing state labels for `at_inspection`, `settling`, `sold`, `awaiting_seller_return`, `seller_unclaimed`, `uncollected_expired` (check `listingFormat.ts` and every exhaustive map).
- [X] T053 [US9] Create `src/pages/orders/index.vue` and `src/components/orders/{OrdersTable,OrderDetailDrawer,OrderTimeline}.vue` per the design reference's Orders page: chips with counts (*All open*, *Waiting for the seller*, *On the way to IGI*, *Needs a decision*, *Waiting for the balance*, *Ready to collect*, *Returns*, *Past deadline*), branch and search filters, the table (order, piece, seller → buyer, value, held, stage, deadline with overdue), the detail drawer (figures, inspections, timeline, ledger). Route `orders` in `src/router/index.ts` with the permission meta; the nav item unhidden for `order.view` (`src/mock/nav.ts`, or wherever the nav is gated).
- [X] T054 [US9] Create the action modals, all `DModal` (never `v-dialog`), each with `useIdempotencyKey`, shown only with their permission and `can{}`: `src/components/orders/{ReceiveModal,ChangeBranchModal,ExtendDeadlineModal,ProposePriceModal,HandoverModal}.vue`.
  - `ChangeBranchModal`: the branch options of the listing, the reason, an optional new deadline.
  - `ExtendDeadlineModal`: which deadline, quick picks +6/12/24/48 working hours (computed client-side from now) or a date-time, the reason.
  - `HandoverModal`: the 6-digit code; it reads `attempts_left` and `retry_after`; it is used for both the buyer and the seller-return handover.

  Wire the existing `CancelAcceptanceModal` (spec 011) into the drawer.
- [X] T055 [US9] Create `src/pages/inspections/index.vue` and `src/components/inspections/{InspectionsTable,WorkList,InspectionResultModal}.vue`:
  - the results list (date presets, branch, outcome; stated vs measured, difference, outcome, superseded);
  - for `inspection.enter`/`order.receive`/`order.handover`, the work list at the caller's branch with the task buttons (Receive, Enter result, Correct, Hand over);
  - the result form in `DModal` (fields by category, counterfeit and stone-below-claim switches, `supersedes_id` set when correcting).

  Route `inspections`; nav unhidden by permission.

  *As built (T052–T055):* one `order.service.ts` holds the orders, the work list and the inspection results (no separate `inspection.service.ts`); `ServiceError` gained `details` (for `attempts_left` / `retry_after`). The order opens in a side panel like Listings to review (`OrderDetailPanel.vue`), not a drawer. Receive is a `ConfirmDialog` (built on `DModal`), not a `ReceiveModal`. The work list and results tables live in `src/components/orders/`. The branch options of `ChangeBranchModal` come from the listing's `branch_options` (listing permissions) or, without them, every branch (`reference.view`); the Backend refuses one the seller did not offer. `ExtendDeadlineModal` takes a date-time only — no quick picks, because the Backend exposes no working-hours calculator and the client must not compute one. Audit labels come from the Backend; nothing to add. Not built because the Backend has no such feature (design-only): the extension requests from sellers, the approve-the-message step, export to Excel and People to watch.
- [X] T056 [US9] Run `npm run type-check`, `npm run lint` and `npm run build` in `../dahab-dashboard`. Fix every exhaustive-map error from the new states and permissions.

---

## Phase 9: User Story 11 — The Customer App runs the order life on the API (P1)

**Goal**: the buyer's and the seller's order life after acceptance is live in Flutter.
**Independent test**: in the app, the seller sees the countdown; after a receive and a pass, the buyer pays and sees the code, and the seller's wallet shows the proceeds.

- [X] T057 [P] [US11] Models:
  - `../dahab-flutter/lib/models/order.dart`: add the API models `Order`, `OrderInspection`, `OrderCollection`, `OrderReturn`, `OrderTimelineEvent`, `OrderDeadline`, with `fromJson` per contracts/orders-api.md (money as strings; unknown states mapped to a safe default).
  - Keep the card block classes.
- [X] T058 [US11] Turn `../dahab-flutter/lib/services/api/orders_api.dart` into `OrdersApi`: `list(role, group)`, `get(id)`, `cancel`, `decide(accept, inspectionId)`, `payBalance`, `relist` — POSTs with `ApiClient` idempotency keys, and errors mapped (`insufficient_funds` details, `balance_deadline_passed`, `illegal_order_transition`, `account_suspended`, `inspection_correction_not_allowed`).
  - `ApiOrdersRepository.orders()` builds the cards from orders plus the not-yet-accepted buy requests (spec 011 cards). Nothing comes from the mock for an order once the API answers, so drop the mock order cards that the API now covers.
  - Register it in `lib/services/app_session.dart` and `lib/services/repositories.dart`.
- [X] T059 [US11] Screens in `../dahab-flutter/lib/features/orders/`:
  - order detail with the timeline per stage;
  - seller: *Bring the piece to IGI* (branch, hours, live countdown), *Cancel the sale* sheet (prototype text: "This cancels the sale, it does not extend it…"), *Ask for more time* → a support link (no API);
  - inspection result (what IGI found, measured vs stated, note, certificate); buyer: *Accept* / *Decline* the new price, "Waiting for Dahab's price" for a regrade;
  - buyer: *Pay balance* (amount due, from the wallet; on `insufficient_funds`, *You need a little more* → *Add funds*), the collection code screen (*Show this code at the counter*, collect before), *Collection window passed*;
  - seller: proceeds received; the returned-piece card with *Your code* and *Put it back on the market*;
  - the cancelled cards (declined adjustment, no-pay with half the deposit, seller cancelled, karat).
  - Strings in `lib/core/i18n` (EN/AR), with the order and listing state labels.
- [X] T060 [US11] Add the fake backend in `../dahab-flutter/test/flows_test.dart` (and `test_app.dart`) for `/customer/me/orders*`, plus flows: the seller cancels; the buyer accepts an adjustment and pays (the code shown); `insufficient_funds` → Add funds; the seller relists a returned piece. Run `flutter analyze`, `flutter test` and `flutter build web --release`.

  *As built (T057–T060):* the models sit in `lib/models/order.dart` (`CustomerOrder`, `OrderInspection`, `OrderCollection`, `OrderReturn`, `OrderTimelineEvent`, `OrderDeadline`, `BalanceShortfall`) beside the card blocks. `OrdersRepository` gained `list/show/cancel/decide/payBalance/relist`; `ApiOrdersRepository(client, tokens, buyRequests)` builds the cards from the orders, then the requests not accepted (an accepted request is the order), and no longer falls back on the mock cards. One order screen (`lib/features/orders/order_screen.dart`, route `order?id=`) renders every stage for both sides, instead of rewiring the prototype's separate Pay / Code / Inspection screens (those stay as prototype screens, unlinked from live cards). *Ask for more time* opens Support (no API). Strings: `ar_extra.dart` plus `i18n.dart` patterns. Fake backend: `FakeBackend.orders` with `order()`/`orderOut()` and the six routes; flows: the carousel filters, the seller cancels, the buyer accepts and pays (code shown), a short wallet → Add funds, the seller relists.

---

## Phase 10: User Story 3 — Staff change the branch or extend a deadline (P2)

**Goal**: an audited branch change (the clock keeps running) and extensions for the three deadlines.
**Independent test**: a change to Zamalek keeps the deadline; an extension by 12 working hours writes old/new and moves it.

- [X] T061 [P] [US3] Write `tests/Feature/Order/ChangeBranchTest.php`:
  - an option branch that is enabled → `branch_id` changes, an `order_branch_change` row, the deadline unchanged; with `extend_to` an extension row too and the reminder stamp cleared; both told; audit;
  - not an option or disabled → `branch_not_in_options`; the same branch → 422; outside `awaiting_delivery` → `order_not_open`; `extend_to` in the past or not later → 422 `deadline_must_move_forward`; no permission → 403; replay once.
- [X] T062 [P] [US3] Write `tests/Feature/Order/ExtendDeadlineTest.php`:
  - `reach_branch` / `balance` / `collect` in their states → the row with old/new, the deadline moved, the reminder reset, both told, audit;
  - `collect` while the listing is `uncollected_expired` → back to `sold`;
  - the wrong state → 409 `deadline_not_running`; not forward → 422; the sweep honours the new deadline.
- [X] T063 [US3] Implement `app/Actions/Orders/Staff/ChangeOrderBranchAction.php` and `ExtendOrderDeadlineAction.php` per research R17. Implement the endpoints:
  - `ChangeBranchRequest`: `branch_id required|integer`, `reason required|string|between:10,1000`, `extend_to nullable|date|after:now`.
  - `ExtendDeadlineRequest`: `which required|in:reach_branch,balance,collect`, `new_deadline required|date|after:now`, `reason required|string|between:10,1000`.

---

## Phase 11: User Story 5 — The buyer decides on an adjusted price (P2)

**Goal**: accept or decline; an unanswered adjustment counts as a decline.
**Independent test**: accept → `awaiting_balance`; decline → refund and a return; the sweep declines an unanswered one.

- [X] T064 [P] [US5] Write `tests/Feature/Order/AdjustmentDecisionTest.php`:
  - **accept** → a `settlement_decision` (old = the locked total, new = the recomputed or proposed price), `awaiting_balance` with its deadline, the seller told;
  - **decline** → `cancelled_inspection`, the refund, the seller not suspended, the listing `awaiting_seller_return` with a return and no compensation, the seller told with nothing to the buyer; each decision writes one audit row `order.decided` with the buyer as actor;
  - a regrade with no price → `price_not_set`; a stale `inspection_id` → `inspection_correction_not_allowed`; the seller → 404; a suspended buyer may decide; replay once.
- [X] T065 [P] [US5] Write `tests/Feature/Order/DecisionSweepTest.php`: past `decision_due_deadline`, `orders:sweep` declines as the system actor with no decision row and the timeline note "no answer"; a regrade with no price is never swept; a decision that wins the race leaves nothing for the sweep.
- [X] T066 [US5] Implement `app/Actions/Orders/Customer/DecideAdjustmentAction.php` (research R15, customer and sweep paths; audit `order.decided`) and `POST /customer/me/orders/{order}/decision` (`DecideAdjustmentRequest`: `accept required|boolean`, `inspection_id required|uuid`). Add the unanswered-adjustment pass to `SweepOrders`.

---

## Phase 12: User Story 8 — The buyer collects the piece (P2)

**Goal**: the code handover with the attempt lock; the collection window.
**Independent test**: the right code → `completed`; 5 wrong → 429; past the window → `uncollected_expired`, and a later handover still completes.

- [X] T067 [P] [US8] Write `tests/Feature/Order/HandoverTest.php`:
  - the right code at the right branch → `completed`, `completed_at`, `collected_at`, `handover_by`, no ledger transaction, audit, `collected` to both;
  - wrong → 422 with `attempts_left` and audit `order.handover_failed`; the 5th wrong → 429 `handover_locked` with `retry_after`; after 15 minutes (clock) it works;
  - another branch → 403; not ready → 409; replay once.
- [X] T068 [P] [US8] Write `tests/Feature/Order/CollectionSweepTest.php`: past `collect_deadline`, `orders:sweep` → listing `uncollected_expired`, `collection_window_passed` to the buyer, the order still `ready_to_collect`; then a handover → listing `sold`, order `completed`.
- [X] T069 [US8] Implement `app/Actions/Orders/Staff/HandoverPieceAction.php` (research R17) and `POST /dashboard/orders/{order}/handover` (`HandoverRequest`). Add the collection pass to `SweepOrders`.

---

## Phase 13: User Story 10 — Staff see every open buy request (P2)

**Goal**: the read-only Buy requests page across listings.
**Independent test**: 5 queued requests across 3 listings, one due in 2 hours → all 5 sorted by deadline, that one flagged; a listing filter narrows the list.

- [X] T070 [P] [US10] Write `tests/Feature/Order/StaffBuyRequestsTest.php`:
  - `buy_request.view` lists queued requests across listings sorted by reply deadline; the filters `state` (queued/accepted/ended), `listing_id`, `near_expiry` (< 6 h, config), `branch_id`;
  - `place_in_line`/`queue_length`; `order_ref` when accepted; `meta.counts`;
  - buyers and sellers by `display_ref` + `customer_id` only (no name/phone/email); no permission → 403 + audit.
- [X] T071 [US10] Implement `app/Actions/BuyRequests/ListBuyRequestsForStaffAction.php` (staff scope; keyset by `seller_reply_deadline, buy_request_id`; uses `PlaceInLine`) and `GET /dashboard/buy-requests` (`ListBuyRequestsRequest`: `state in:queued,accepted,ended`, `listing_id uuid`, `near_expiry boolean`, `branch_id integer`, `limit 1..100`, `cursor`) with `StaffBuyRequestResource`.
- [X] T072 [US10] Dashboard:
  - `src/types/buyRequest.ts`, `src/services/buyRequest.service.ts`;
  - `src/pages/buy-requests/index.vue` + `src/components/buy-requests/BuyRequestsTable.vue`: piece, listing, seller, buyer (refs linking to `/customers/{id}` for `customer.view`), place / queue length, locked price, deposit held, requested, reply deadline with a countdown, near-expiry rows highlighted, filter chips and a count;
  - a new nav item "Buy requests" under Orders for `buy_request.view`; the route with permission meta.

  Run `npm run type-check`, `npm run lint` and `npm run build`.

  *As built (T072):* `src/services/buy-request.service.ts`, `src/composables/useBuyRequests.ts`, `src/components/orders/BuyRequestsTable.vue`; chips In line / Close to expiry / Accepted / Ended with the Backend's counts; a `?listing=` address filter; the reply deadline as a time (no countdown).

---

## Phase 14: Polish & cross-cutting

- [X] T073 [P] Write `tests/Feature/Order/OrderConcurrencyTest.php` (two connections, as `BuyRequestConcurrencyTest`): receive vs the reach sweep; the seller's cancel vs receive; pay vs the no-pay sweep; decide vs the decision sweep; two handovers with the same code; staff cancel vs the seller's cancel. Exactly one wins each time, and the money moves once.
- [X] T074 [P] Write `tests/Feature/Order/OrderReconciliationTest.php` per research R12: build orders through `OrderJourney` into every state, then assert every rule over all orders, plus `ledger_global_zero = 0` and the buy-request reconciliation of spec 011 still green.
- [X] T075 [P] Write `tests/Feature/Order/OrderLeakTest.php`: no customer-facing order response contains the counterparty's name, phone or email (recursive scan); the list never contains a code; the seller never sees the buyer's code and vice versa.
- [X] T076 [P] Write `tests/Feature/Order/OrderNotificationTest.php`: each event goes to the right parties in their language, after commit (none on rollback), none for a customer's own action except `collection_code`, and a failed SMS does not undo the change.
- [X] T077 Create `database/seeders/LocalOrderSeeder.php` (research R23; local only, after `LocalBuyRequestSeeder` in `DatabaseSeeder`) through the real Actions with `Carbon::setTestNow` for deadline passes; print the order refs per state. Run it on `dahab_wt012` with `migrate:fresh --seed`.
- [X] T078 [P] Postman: the folder "Orders" (customer: list, show, cancel, decision, pay-balance, relist; dashboard: list, show, receive, inspection-results, propose-price, change-branch, extend-deadline, handover, seller-return handover, work list, inspections) and "Buy requests → Staff list"; environment variables `order_id`, `inspection_id`, `collection_code`; check against `postman/README.md`.
- [X] T079 [P] Performance: add `tests/Feature/Performance/OrdersPerformanceTest.php` — `GET /dashboard/orders` with 10,000 orders < 300 ms; pay-balance < 300 ms p95; one sweep pass over 1,000 due orders < 60 s.
- [X] T080 Update `CLAUDE.md` "Current state" (Backend, Dashboard, Flutter lines for spec 012) and the roadmap memory note if it exists.

  *As built (T073–T080):*
  - T073: two connections on committed fixtures (as `TopUpConcurrencyTest`), six races, each "B waits on A's lock, then changes nothing"; the fixture rows are truncated afterwards and the ledger singletons re-provisioned. The test forgets the fixtures' signed-in principal and bound `RequestContext` before the race (a test-only artefact: each real request binds its own).
  - T074: ten states through the API; the no-pay order is built first because its time travel would sweep the others.
  - T075: recursive key/value scan of every customer list and detail, both sides; codes only in the owner's detail.
  - T076: parties per event, language, no message on rollback, a failing SMS keeps the change (the sync test queue surfaces the job error; production queues it).
  - T077: `LocalOrderSeeder` (after `LocalBuyRequestSeeder`): Karim gets a demo 500,000 EGP top-up through the money service; Hoda sells a fresh 21K ring per order; the CEO account acts for Dahab; eight orders (at inspection, deciding, waiting for the balance, ready to collect, completed, seller cancel, karat cancel with return, no-pay with return). Run with `migrate:fresh --seed` on `dahab_wt012_dev`; the ledger sums to 0.
  - T078: the collection already had every route; the customer list's empty `role=` became a disabled query param.
  - T079 (opt-in `--group=perf`, 10,000 orders built through send + accept in 1,538 s): staff list p95 55 ms; pay-balance p95 103 ms; one sweep of 1,000 due orders 39.1 s. The first trial measured 70.6 s: the seller-cancel path locked the listing and the order twice; it now passes the locked rows on (−2 queries, 53 → 43 ms per order).
  - T080: CLAUDE.md "Current state", the Flutter README, the Dashboard guide and API doc, the roadmap memory note.
  - Quality gate (T081) also found an N+1 in `GET /customer/me/orders` (the settlement rates read 4 settings per order); the resource now reads them once per request (request attributes) and passes them to `OrderSettlement::compute()`; a payment still reads them live (a first try memoised them on a scoped `OrderSettlement`, which broke "commission and VAT read live at payment" — reverted). `OrderListQueriesTest` pins every list to a fixed query count.
- [X] T081 Quality gates (`laravel-quality-gates` skill) on `dahab_wt012`:
  - `./vendor/bin/pint --test`;
  - `composer test`, sequentially;
  - `migrate:fresh --seed` and a rollback (expect the documented refusal once orders exist; verify on an empty database);
  - `composer swagger:generate` with no warnings;
  - `php artisan route:list --path=orders` and `--path=buy-requests`;
  - an N+1 check on the lists;
  - a security review of the `order` scope, the codes and the branch scope.

  Run the quickstart.md scenarios. Then the Dashboard and Flutter build commands.
- [X] T082 Write the Step 5 report (CLAUDE.md shape) with the PR note from plan.md, the potentially breaking items (DH006 code, new states, permission union) and what stays mock in each app.

  *As built (T081–T082):* Pint clean except two files that were failing before this work; 1584 tests pass sequentially on `dahab_wt012`; OpenAPI generated with no warnings; routes listed; N+1 pinned by `OrderListQueriesTest`; rollback refused on the seeded dev database and exercised on an empty one by `LedgerSchemaTest`; security review of the `order` scope, the codes (HMAC, constant-time, encrypted copy for the owner only, 5-try lock) and the branch scope found nothing. Quickstart walked in part in the browser (Orders, detail, Inspections, Buy requests, receive); the full manual walk is still open. Report: in the conversation.

---

## Dependencies & execution order

- **Phase 1 (docs) → Phase 2 (foundation)**: these block everything. T009 depends on T002–T004; T013–T022 depend on T009–T011.
- **US1 (Phase 3)** comes first; it creates the read Actions and the staff detail used by the others.
- **US2** depends on US1 (the customer detail, the receive test fixtures).
- **US4** depends on US1 (receive).
- **US6** depends on US4 (a pass).
- **US7** depends on US2 (the `orders:sweep` command, T032), US4 (a pass) and US6 (payment race). The return handover reuses `CollectionCodes`.
- **US9 backend** (T049–T051) can follow US1. The Dashboard pages (T052–T056) need the endpoints of US1–US8 and US3 to be complete. Build them after US8, or stub the actions behind `can{}`.
- **US11** (Flutter) needs the customer endpoints of US1, US2, US5, US6 and US7.
- **US3, US5, US8** depend on US4 (US5) or US6 (US8, collect extension) and US1 (US3).
- **US10** is independent after Phase 2 (spec 011 data only).
- **Polish** runs after all stories.

**Suggested order for one implementer**: Phase 1 → 2 → US1 → US2 → US4 → US6 → US7 → US5 → US3 → US8 → US10 → US9 (backend + Dashboard) → US11 → Polish.

## Parallel examples

- Phase 2: T010, T011, T012, T014 and T018 touch different files.
- US4: T033–T036 (four test files) in parallel, then T037 → T038 → T039.
- US6: T040–T042 in parallel, then T043.
- Dashboard: T052 alongside backend US3/US5 work, then the pages.

## Implementation strategy

- **MVP**: Phases 1–2 plus US1, US2, US4, US6 and US7. This gives the full money life: delivery, cancellation, inspection, settlement and forfeiture, through the API, with the reconciliation (T074) run early as a safety net.
- **Then**: the P2 levers and the decision (US3, US5, US8) and the Buy requests page (US10).
- **Then**: the Dashboard and Customer App surfaces (US9, US11).
- **Last**: Polish.
