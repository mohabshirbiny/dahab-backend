# Tasks: Ledger Core

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/wallet-api.md](./contracts/wallet-api.md), [quickstart.md](./quickstart.md)

**Tests**: required (Constitution V). Work test-first within each story: write the test, see it fail for the expected reason, implement, run it. Run artisan and tests as the `dahab` DB user.

**Paths**:
- Backend paths are relative to the repo root.
- Dashboard paths are under `../dahab-dashboard/`.
- Flutter paths are under `../dahab-flutter/`, which has no git.

**Git**: work on branch `feature/ledger-core` in backend and dashboard, created only when the user asks.

## Format: `[ID] [P?] [Story] Description`

## Phase 1: Setup (docs first — Constitution III)

- [X] T001 Write `docs/features/ledger-core.md` from `docs/features/_TEMPLATE.md`, with the impact analysis from plan.md and links to `specs/008-ledger-core/`.
- [X] T002 Update `docs/Database schema/03_schema_ledger.sql` and the matching section of `00_schema_full.sql`, per research R1, R3, R4 and R15:
  - partial UNIQUE `one_reversal_per_txn ON ledger_transaction(reverses_txn_id) WHERE reverses_txn_id IS NOT NULL`;
  - replace `idx_posting_account` with `(account_id, posting_id) INCLUDE (amount, ledger_txn_id)`;
  - add `idx_ledger_txn_created ON ledger_transaction(created_at)`;
  - the trigger functions `RAISE … USING ERRCODE = 'DH002'` (unbalanced) and `'DH001'` (overdraft), and read under a transaction-local `app.rls_scope = 'ledger'`, restoring the previous value;
  - ~~`solvency_check.bank_balance = −SUM(bank)` + the sign note~~ — **done 2026-09-28** (user-approved R15);
  - the `trg_customer_accounts` AFTER INSERT trigger on `customer`;
  - the singleton seed: `INSERT … ON CONFLICT DO NOTHING` for the 6 internal kinds.
- [X] T003 [P] Update the RLS section of `docs/Database schema/05_schema_security.sql` (and `00_schema_full.sql`) with the ledger policies of data-model.md "Row-level security", and document the `ledger` scope (sees and inserts ledger rows only; not in `dahab_rls_elevated()`).
- [X] T004 [P] Amend the Technical Spec:
  - Part 1 §5.1 (wallet accounts are now under RLS);
  - Part 1 §5.2 (the permission `wallet.view`);
  - Part 1 §8 (`wallet_access_denied` → the existing `permission_denied` + audit row; research R6);
  - Part 2 §1 (accounts created by the trigger on `customer`);
  - Part 2 §8: the as-built `GET /customer/me/wallet` and `/me/wallet/transactions` paths, the top-up legs (already corrected 2026-09-28), and `POST /me/wallet/topup` marked "not built — next feature";
  - Part 2 §9: the new statement and overview endpoints (the release legs, and Part 3 §11's, were already corrected 2026-09-28);
  - Part 3 §0: the customer-account lock rule (research R5).

  Mark each amendment "Changed by spec 008" with a link to the spec.

---

## Phase 2: Foundational (blocks every story)

- [X] T005 Write `tests/Feature/Ledger/LedgerSchemaTest.php` (real Postgres, maintenance scope). It covers:
  - the types exist, with exactly the 8 `account_kind` and 15 `ledger_event_kind` values;
  - `customer_accounts_have_owner` refuses an internal account with an owner, and a customer account without one;
  - a second singleton of a kind is refused;
  - UPDATE and DELETE on `ledger_transaction` and `ledger_posting` raise "append-only";
  - an actor-less transaction violates `ledger_txn_has_actor`;
  - a zero posting violates `posting_nonzero`;
  - an unbalanced raw insert fails at COMMIT with SQLSTATE `DH002`;
  - a raw overdraft fails at COMMIT with `DH001`;
  - a second reversal of one txn violates `one_reversal_per_txn`;
  - the four views exist, and `ledger_global_zero` is 0 on an empty ledger;
  - deleting a customer who has postings fails on the foreign key (spec edge case, G1);
  - `migrate:rollback` of the migration leaves none of the objects.
- [X] T006 Create `database/migrations/2026_10_01_000010_create_ledger.php` from the updated `03_schema_ledger.sql` (T002) and data-model.md. On pgsql only (the RLS migration pattern), it creates, in order:
  1. the types `account_kind`, `ledger_event_kind`;
  2. the tables `account`, `ledger_transaction`, `ledger_posting` with every CHECK, UNIQUE and FK;
  3. the indexes, including `one_reversal_per_txn`;
  4. `block_mutation()` (reuse it if it already exists) and the two append-only triggers;
  5. `assert_txn_balanced()` and `assert_customer_account_nonneg()` as DEFERRABLE INITIALLY DEFERRED constraint triggers, with the ledger self-scope and the `DH001`/`DH002` codes;
  6. the views, with the corrected `solvency_check`;
  7. `ENABLE` + `FORCE ROW LEVEL SECURITY` and the policies (`account_isolation`, `ledger_transaction_isolation`, `ledger_posting_isolation`: SELECT `dahab_rls_elevated() OR dahab_rls_scope()='ledger' OR <owner>`, INSERT `dahab_rls_elevated() OR dahab_rls_scope()='ledger'`; no UPDATE or DELETE policy);
  8. `create_customer_accounts()` + `trg_customer_accounts` AFTER INSERT ON `customer`;
  9. the seed of the 6 internal singletons and the backfill of both accounts for every existing customer (`ON CONFLICT DO NOTHING`).

  `down()` reverses everything in order.
- [X] T007 [P] Create `app/Enums/AccountKind.php` (8 cases, `isCustomer()`) and `app/Enums/LedgerEventKind.php` (15 cases, `staffLabel()` in the Dashboard's wording: e.g. `topup` "Added by bank transfer", `deposit_hold` "Deposit held", `deposit_release` "Deposit returned", `settlement_seller` "Sale settled", `withdrawal` "Withdrawn", `compensation` "Compensation from Dahab", `reversal` "Correction (reversal)"; no default arm).
- [X] T008 Add the `ledger` scope to `app/Support/DatabaseActor.php`:
  - it goes in `SCOPES` but **not** `ELEVATED`;
  - `DatabaseActor::ledger(Closure $work)` pushes `ledger` while keeping the current frame's customer and staff ids, and pops in `finally`.

  Extend `tests/Feature/Rls/*`: the ledger scope sees no `customer`, `identity_document` or `audit_log` rows.
- [X] T009 [P] Create the models `app/Models/Account.php`, `app/Models/LedgerTransaction.php` and `app/Models/LedgerPosting.php`, per data-model.md:
  - UUID keys; `amount` as a decimal string cast (never float);
  - relationships; `Account::internal(AccountKind)`; the `forCustomer()` scope;
  - `LedgerTransaction` and `LedgerPosting` throw on `save()` of an existing row, and on `delete()`.

  Add `Customer::accounts()`.
- [X] T010 [P] Add `DomainApiException::insufficientFunds()` (409 `insufficient_funds`) and `::ledgerAlreadyReversed()` (409 `ledger_already_reversed`) in `app/Exceptions/DomainApiException.php`. In `bootstrap/app.php`, map a `QueryException` whose SQLSTATE is `DH001` to the `insufficient_funds` envelope. `DH002` stays a 500 and is logged.
- [X] T011 Add `tests/Support/Ledger.php`, a test helper (maintenance scope, inside a transaction) with `topUp($customer, '1000')`, `hold($customer, '400')` and `release()`. Top-up uses `bank −X` / `cust_available +X` (R15). It starts with raw balanced inserts (so Phase 2 has no dependency on US1), and T014 switches it to `PostLedgerEntryAction` (I3).
- [X] T012 Add the ledger tables to the expectations of `tests/Feature/Rls/CustomerTableIsolationTest.php`: `account` and `ledger_transaction` carry `customer_id`; `ledger_posting` is covered explicitly.

**Checkpoint**: `php artisan migrate:fresh --seed` works; T005 is green.

---

## Phase 3: User Story 1 — Balanced, attributable, permanent money movements (P1) 🎯 MVP

**Goal**: the only way to write the ledger is one validated, locked, balanced set; a reversal is the only correction.

**Independent test**: the US1 scenarios of the spec, through the Action, against real Postgres.

- [X] T013 [US1] Write `tests/Feature/Ledger/PostLedgerEntryTest.php`. It covers:
  - top-up then hold → balances 600 / 400, bank cash 1000, `ledger_global_zero` 0;
  - the entry and its lines commit with the caller's other work, and roll back together when the caller throws;
  - refused, with nothing written:
    - fewer than 2 lines;
    - a zero amount;
    - an amount with 5 decimal places;
    - a non-zero sum;
    - no actor;
    - an unknown account;
  - an overdraft of available or of held → `insufficient_funds`;
  - lines to the same account twice in one entry net correctly;
  - called outside a transaction → `LogicException`;
  - the customer scope may post an entry touching internal accounts, and it commits (the trigger reads in `ledger` scope);
  - references and memo are persisted.
- [X] T014 [US1] Implement `app/Support/Ledger/LedgerLine.php` and `app/Support/Ledger/LedgerEntry.php` (immutable, with constructor validation per data-model.md "Posting contract": amount `/^-?\d{1,14}(\.\d{1,4})?$/`, `≠ 0`, `≥ 2` lines, `Σ = 0` by bcmath, an actor present, memo `≤ 1000`). Then implement `app/Actions/Ledger/PostLedgerEntryAction.php`:
  1. require `DB::transactionLevel() > 0`;
  2. inside `DatabaseActor::ledger()`, load the accounts;
  3. lock the customer accounts `FOR UPDATE ORDER BY account_id`;
  4. project each one's balance (`current + Σ lines`), and throw `insufficientFunds` if any is `< 0`;
  5. insert the transaction and its lines, and return the transaction.

  Switch `tests/Support/Ledger.php` (T011) to post through this Action. Make T013 green.
- [X] T015 [US1] Write `tests/Feature/Ledger/ReverseLedgerEntryTest.php`. It covers:
  - a reversal negates every line, with `event_kind` `reversal`, `reverses_txn_id` set, memo = reason, the staff actor and the references copied; the original is unchanged;
  - a second reversal → `ledger_already_reversed`;
  - reversing a reversal is allowed;
  - a reversal that would overdraw → `insufficient_funds`;
  - the reason is required (1–1000);
  - a staff actor is required.
- [X] T016 [US1] Implement `app/Actions/Ledger/ReverseLedgerEntryAction.php` on top of `PostLedgerEntryAction` (it checks for an existing reversal under a lock on the original row, and the unique index is the backstop). Make T015 green.
- [X] T017 [US1] Write `tests/Feature/Ledger/LedgerConcurrencyTest.php` with two real connections: two holds of 700 on a 1,000 wallet → exactly one commits and the other gets `insufficient_funds`, and the balance is never negative. Also, an entry touching two customers' accounts in opposite orders from two connections completes without deadlock (the lock order). *As built: the two-connection wait/refuse case is tested. The cross-customer lock order (`ORDER BY account_id`) is enforced in code but not simulated, because a deadlock needs two concurrent processes.*
- [X] T018 [US1] Write `tests/Feature/Ledger/LedgerIsolationTest.php`. In customer A's scope:
  - B's accounts, transactions and postings are invisible (`count` is 0);
  - A sees exactly their own;
  - a direct INSERT into any of the three tables is refused;
  - with no actor bound, nothing is visible (fail closed).

**Checkpoint**: US1 is complete; every later money feature can post.

---

## Phase 4: User Story 2 — Every customer has a wallet from the start (P1)

**Goal**: two accounts per customer, from any creation path, plus the six singletons.

**Independent test**: register a customer, run the backfill twice, and check the singletons.

- [X] T019 [US2] Write `tests/Feature/Ledger/AccountProvisioningTest.php`. It covers:
  - a full registration through `POST /customer/auth/register/submit` → exactly one `cust_available` and one `cust_held`, both at 0, in the same transaction (a forced failure after the customer insert leaves neither);
  - `Customer::factory()->create()` also gets both;
  - re-running the migration's backfill SQL on a customer whose accounts were removed under maintenance restores them, and re-running it again adds nothing;
  - there is exactly one account per internal kind, and none has an owner.
- [X] T020 [US2] Fix whatever T019 exposes: the trigger function, the backfill statement, and the factories and seeders that insert customers in bulk (`database/seeders/LocalCustomerSeeder.php`). Make T019 green. *As built: nothing needed fixing — the trigger covers every insert path, including seeders and factories. The registration assertion lives in `tests/Feature/Auth/Customer/RegisterTest.php`.*

---

## Phase 5: User Story 3 — The customer sees their wallet and history (P2)

**Goal**: `GET /customer/me/wallet` and `/me/wallet/transactions`, and the Customer App's Wallet on live data.

**Independent test**: the US3 scenarios; in the app, Wallet shows 600 / 400 / 1,000 and 2 movements.

### Backend

- [X] T021 [US3] Write `tests/Feature/Wallet/CustomerWalletTest.php`. It covers:
  - a verified customer gets `{available, held, total, currency}` as 4-dp strings;
  - a new customer gets `0.0000` ×3;
  - pending and rejected customers → 403 `verification_required`;
  - a suspended customer → 200;
  - a staff token → 401;
  - another customer's money never appears.
- [X] T022 [US3] Write `tests/Feature/Wallet/CustomerWalletHistoryTest.php`. It covers:
  - one row per entry, newest first;
  - the hold row has `available_change "-400.0000"`, `held_change "400.0000"`, `available_after "600.0000"`, `held_after "400.0000"`;
  - the top-up row has `available_after "1000.0000"`;
  - pagination: `per_page` of 1–100 and `next_cursor` chaining with no gaps or duplicates, and the balance-after stays correct across pages;
  - a malformed cursor → 422;
  - `memo` is never present;
  - `reference` is null;
  - verified-gate refusals as in T021;
  - RLS: B's entries never appear, even if B's lines share an entry with A's (such an entry shows only A's changes).
- [X] T023 [US3] Implement:
  - `app/Actions/Wallet/ShowCustomerWalletAction.php`: sums per kind for the customer, in the customer's own scope;
  - `app/Actions/Wallet/ListCustomerWalletHistoryAction.php`: grouped per `ledger_txn_id` over the customer's accounts; the sequence key is `MIN(posting_id)`; keyset on it; balance-after = current balance − the changes of newer entries (research R7);
  - `app/Support/Ledger/HistoryCursor.php`: a signed, opaque cursor;
  - `app/Http/Requests/Customer/WalletHistoryRequest.php`;
  - `app/Http/Resources/Customer/{WalletResource,WalletHistoryRowResource}.php`;
  - `app/Http/Controllers/Api/V1/Customer/WalletController.php`, with `#[OA]` for both endpoints;
  - the routes in `routes/api.php` under `customer/me` with `customer.gate:verified`.

  Make T021 and T022 green.
- [X] T024 [P] [US3] Add "Customer → Wallet" (2 requests) to `postman/Dahab-Backend.postman_collection.json`, per `postman/README.md`.

### Customer App (`../dahab-flutter`, no git)

- [X] T025 [US3] Change `lib/models/wallet.dart`:
  - `WalletSummary` gets `num available`, `held` and `total`, plus `fromJson`;
  - `WalletTxn` gets `fromJson` from the history row (`kind`, `created_at`, `available_change`, `held_change`, `available_after`, `held_after`, `reference`). `type` is derived from the kind and sign: hold → `TxnType.hold`, in → `moneyIn`, out → `moneyOut`.

  Keep the mock constants compiling (`lib/mock/mock_wallet.dart`).
- [X] T026 [US3] Add en/ar labels for the 15 event kinds in `lib/core/i18n`, with the prototype wording ("Top-up", "Hold placed", "Hold released", "Sale settled", "Withdrawal", …), and a customer-facing note per kind.
- [X] T027 [US3] Create `lib/services/api/wallet_api.dart`, an `ApiWalletRepository` implementing `summary()` and `transactions()` via `api_client.dart` (`/customer/me/wallet`, `/customer/me/wallet/transactions`, following `next_cursor`). `topUpMethods()` and `invoices()` delegate to `MockWalletRepository` (not built). Wire it in `lib/main.dart` for signed-in sessions.
- [X] T028 [US3] Update `lib/features/wallet/wallet_screens.dart` and `lib/features/account/account_screen.dart`: show the `num` amounts rounded to whole EGP, label rows from i18n by kind, add an empty state for no movements, and handle `verification_required` with the existing message.
- [X] T029 [US3] Extend the fake backend in `test/flows_test.dart` with both endpoints: a wallet with a top-up and a hold renders 600 / 400 / 1,000 and 2 labelled rows; an empty wallet shows the empty state; `verification_required` shows the gate message.

---

## Phase 6: User Story 4 — CEO and Finance read wallets and the safety figure (P2)

**Goal**: the permission, the four Dashboard endpoints, the Wallet statement page, the customer-file panel and the live Overview panels.

**Independent test**: the US4 scenarios; as Finance the statement reconciles; as the COO everything is hidden and refused.

### Backend

- [X] T030 [US4] Add `StaffPermission::WALLET_VIEW = 'wallet.view'` (label "View wallets and statements", group "Money", seed `[finance]`, never `coo`) in `app/Enums/StaffPermission.php`. Add `AuditEvent::LEDGER_STATEMENT_VIEWED = 'ledger.statement.viewed'` and `LEDGER_STATEMENT_EXPORTED = 'ledger.statement.exported'`, with labels ("Wallet statement opened", "Wallet statement exported") and a new `AuditCategory::MONEY`. Update the presenter's subject mapping in `app/Support/Audit/AuditEntryPresenter.php`. Extend the existing permission-seed tests: `ceo` and `finance` have `wallet.view`, `coo` does not.
- [X] T031 [US4] Write `tests/Feature/Wallet/WalletPermissionTest.php`: for each of the 4 dashboard endpoints (plus export), Finance and the CEO → 200, and the COO, Operations and Verification → 403 `permission_denied`, with one `auth.staff.permission_denied` audit row naming `wallet.view`.
- [X] T032 [US4] Write `tests/Feature/Wallet/WalletOverviewTest.php` and `CustomerFileWalletTest.php`. They cover:
  - overview: `available`, `held`, `total_owed`, `bank` (cash, positive after a top-up), `headroom = bank − total_owed` (0 after pure top-ups and holds), `system_total "0.0000"`;
  - the customer wallet: `{available, held, total}`; an unknown customer → 404; not audited.
- [X] T033 [US4] Implement `app/Actions/Wallet/ShowLedgerOverviewAction.php` (the `solvency_check`, `customer_wallet` totals and `ledger_global_zero` views), `app/Http/Resources/Staff/LedgerOverviewResource.php` and `app/Http/Controllers/Api/V1/Dashboard/WalletController.php` (`overview`, `customerWallet`), with `#[OA]`. Add the routes `GET /dashboard/wallets/overview` and `GET /dashboard/customers/{customer}/wallet` behind `staff.permission:wallet.view`. Make T032 green.
- [X] T034 [US4] Write `tests/Feature/Wallet/WalletStatementTest.php`, over a seeded August with top-ups, a hold, a release and a staff-actor entry. It covers:
  - **view `customer`**:
    - opening, in, out and closing;
    - each row's before + in − out = after;
    - the first row's before = opening and the last row's after = closing;
    - a hold is an `out` with `held_after`;
    - the summary's available + held = total;
    - `by_hand` is true only for the non-system staff entry;
    - `label` comes from `staffLabel()`;
  - **view `customers`**: the running balance is the total owed, a hold row has `moved_to_held` and before = after, and the closing = the total owed at `to`;
  - **view `dahab`**: entries on the commission and spread accounts, and `vat_payable` in the summary;
  - **grains `day` and `month`**: counts and sums equal the underlying rows;
  - **periods**: Cairo-day boundaries, so an entry at 23:30 Cairo on 31 Aug is in August;
  - **pagination**: pages chain, and before and after stay continuous across pages; a tampered cursor → 422;
  - **validation**: `view` required; `customer_id` required for `customer` and prohibited otherwise; `from ≤ to`; range ≤ 366 days; `per_page` 1–200;
  - **audit**: exactly one `ledger.statement.viewed` for a first page of `view=customer`, none for a `cursor` page, and none for the `customers` and `dahab` views.
- [X] T035 [US4] Implement:
  - `app/Actions/Wallet/BuildWalletStatementAction.php`: the view's account set; opening = the sum before `from` (Cairo); in and out; rows grouped per entry (`each`) or per Cairo day or month; running balances; keyset;
  - `app/Support/Ledger/StatementCursor.php`: signed, carrying the sequence key and the running balance;
  - `app/Http/Requests/Dashboard/Wallet/WalletStatementRequest.php`, with the rules quoted in contracts/wallet-api.md;
  - `app/Http/Resources/Staff/WalletStatementResource.php`;
  - the controller method `statement`, with `#[OA]` and the audit call;
  - the route `GET /dashboard/wallet-statement`.

  Make T034 green.
- [X] T036 [US4] Write `tests/Feature/Wallet/WalletStatementExportTest.php`: the CSV header, summary and rows match the JSON statement for the same query; the 50,000-row cap writes the closing note; one `ledger.statement.exported` per call, for every view. Then implement `app/Actions/Wallet/ExportWalletStatementAction.php` (the spec 006 CSV pattern) and the route `GET /dashboard/wallet-statement/export`.
- [X] T037 [P] [US4] Add "Dashboard → Wallets" (overview, customer wallet, statement ×3 views, export) to `postman/Dahab-Backend.postman_collection.json`.

### Dashboard (`../dahab-dashboard`)

- [X] T038 [US4] Add the endpoints to `src/api/endpoints.ts`. Create `src/types/wallet.ts` (the `WalletOverview`, `CustomerWallet`, `StatementQuery`, `StatementSummary` and `StatementRow` types, camelCase, money as `string`). Add `'wallet.view'` to the permission type in `src/types/staff.ts`.
- [X] T039 [US4] Create `src/services/wallet.service.ts` (snake → camel mapping, CSV download via the existing export helper) and `src/composables/useWallet.ts` (TanStack queries: overview, customer wallet, infinite statement).
- [X] T040 [US4] Build `src/pages/statement/index.vue` from the design's "Wallet statement":
  - a view switch (one customer wallet, all customer wallets, the Dahab wallet), with the customer picked by reference or phone using the existing customer search;
  - period presets (this month, last month, last 90 days, custom) and a grain switch (every movement, one line per day, one line per month);
  - four stat cards (opening, in, out, closing, with available + held under the closing card for one customer);
  - a table with When, What, Reference, Before, In, Out, After, with an amber shade on `byHand` rows and the design's note;
  - "Open their file" and "Export to Excel".

  Put the pieces in `src/components/wallet/{StatementFilters,StatementSummary,StatementTable}.vue`. Replace the placeholder route in `src/router/index.ts`, and set `permission: 'wallet.view'` and remove `hidden` on the nav item in `src/mock/nav.ts`. Loading, empty and error states are required.
- [X] T041 [US4] Add `src/components/customer-file/CustomerWalletPanel.vue` (available, held, total; "Open wallet statement" links to the statement for this customer) to `src/pages/customers/[id].vue`, rendered only with `wallet.view` (absent, not zero, otherwise).
- [X] T042 [US4] Overview: in `src/services/overview.service.ts` and `src/pages/dashboard/overview.vue`:
  - load `hero` (the safety figure: "Bank balance minus customer wallets", with the value, hint "Bank … · wallets …" and a red style when negative), `wallets` and the "Held on open orders" stat (value = `held`; hint: none until orders exist) from `/dashboard/wallets/overview` when the viewer has `wallet.view`, and hide all three otherwise;
  - hide the "Dahab earned this month" stat until Settlement is built (spec Clarifications, I2);
  - the other panels stay mock and unchanged.
- [X] T043 [US4] Update the Dashboard docs (`docs/` per the repo's pattern) and `CLAUDE.md`: Wallet statement live, Overview wallet and safety panels live. Run `npm run type-check`, `npm run lint` and `npm run build`.

---

## Phase 7: Polish & cross-cutting

- [X] T044 Write `tests/Performance/LedgerPerformanceTest.php` (group `perf`). A bulk SQL seed of 1,000,000 postings under the maintenance scope (balanced batches), then assert:
  - a customer wallet plus the first history page with 10,000 movements < 1 s;
  - a one-month `customers` statement first page < 2 s;
  - the `EXPLAIN` of the history query uses `(account_id, posting_id)`.
- [X] T045 [P] Update `docs/platform/api-contract.md`: the permission list (+`wallet.view`), the money-string convention, the new error codes and the customer wallet endpoints. Update `CLAUDE.md` Part 1 "Current state" with the ledger core. Complete `docs/features/ledger-core.md` as built.
- [X] T046 Run the `laravel-quality-gates` skill:
  - `composer test`;
  - `./vendor/bin/pint --test`;
  - `composer swagger:generate`;
  - `php artisan migrate:fresh --seed`, then `migrate:rollback --step=1` and `migrate` again;
  - `php artisan route:list --path=wallet`;
  - an N+1 and security review of the statement and history queries.
- [X] T047 Flutter: `flutter analyze`, `flutter test`, `flutter build web --release`.
- [X] T048 Run the quickstart.md scenarios end to end and record the results in `docs/features/ledger-core.md`. *As built: sections 1–5 are covered by the automated suites (Pest through HTTP, Flutter widget flows against the fake backend, Dashboard type-check and build). A manual click-through in a browser against a running Backend was not done; it is left for review.*

---

## Dependencies & execution order

- Phase 1 (docs) → Phase 2 (migration, models, scope) → **US1** (the money service) → US2, US3 and US4.
- US2 depends only on Phase 2. It is listed after US1 because its tests use the posting helper for the balance checks.
- US3 and US4 depend on US1 (their test data is posted through `PostLedgerEntryAction` via `tests/Support/Ledger.php`) and are independent of each other.
- Inside US3: backend T021–T023 before Flutter T025–T029. Inside US4: T030 first; T031–T037 backend before the Dashboard T038–T043.
- Polish comes last.

## Parallel opportunities

- T003 and T004 (docs) alongside T002.
- T007, T009 and T010 after T006.
- After US1: the US3 backend (T021–T024) and the US4 backend (T030–T037) touch different files, and can run in parallel.
- The Flutter tasks (T025–T029) and the Dashboard tasks (T038–T043) are in different repos, and can run in parallel once their backend endpoints are green.
- The Postman tasks T024 and T037.

## Implementation strategy

1. **MVP = Phases 1–3 (US1)**: the ledger exists, is guarded by the engine, and can be posted to. Stop and review with the user.
2. US2 (accounts from every path).
3. US3 (the customer wallet, backend + Flutter) and US4 (the staff reads, backend + Dashboard) as two batches.
4. Polish: perf, docs, gates. The user merges to local main; never push without asking.
