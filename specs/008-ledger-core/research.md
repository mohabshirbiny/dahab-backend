# Research: Ledger Core (spec 008)

Decisions taken while planning. Each: **Decision**, **Rationale**, **Alternatives considered**.

## R1 — Schema follows `03_schema_ledger.sql`, with five documented additions

**Decision**: the migration creates `account_kind`, `ledger_event_kind` (as in `01_schema_core.sql`), `account`, `ledger_transaction`, `ledger_posting`, the append-only triggers, the two deferred constraint triggers and the four views, verbatim. It adds five things, and the SQL docs (`03`, `00_schema_full`, `05` RLS list) are updated **first**:

1. `CREATE UNIQUE INDEX one_reversal_per_txn ON ledger_transaction(reverses_txn_id) WHERE reverses_txn_id IS NOT NULL` — an entry is reversed at most once (FR-010).
2. The AFTER INSERT trigger `trg_customer_accounts` on `customer`, which creates the two customer accounts (R4).
3. Row-level security on the three ledger tables (R3).
4. The trigger functions read under the `ledger` scope (R3). They raise stable SQLSTATEs: `DH001` for an overdraft and `DH002` for an unbalanced entry (R6).
5. Two read indexes (R8): `ledger_posting(account_id, posting_id) INCLUDE (amount, ledger_txn_id)` and `ledger_transaction(created_at)`.
6. The corrected sign of `bank` in `solvency_check` (R15).

The FKs from `ledger_transaction` to listing, order, buy request and withdrawal stay unconstrained UUID columns until those tables exist, as the schema says ("FK added in Part 3").

**Rationale**: Constitution III says docs first. Each addition closes a gap the spec requires, and none of them changes the documented semantics.

**Alternatives**: enforcing single reversal only in PHP was rejected, because a race could double-reverse.

## R2 — One money service; posting validates first, the database is the backstop

**Decision**: `App\Actions\Ledger\PostLedgerEntryAction::handle(LedgerEntry $entry): LedgerTransaction` is the only writer. `LedgerEntry` is an immutable DTO: event kind, actor (customer id **or** staff id), memo, the optional references (order, listing, buy request, withdrawal), the optional reversed txn, and `list<LedgerLine(accountId, amount)>`.

The Action:
1. validates in PHP: at least 2 lines, amount strings matching `^-?\d{1,14}(\.\d{1,4})?$`, none zero, bcmath sum exactly `0`, an actor present, every account existing;
2. requires an open DB transaction (`DB::transactionLevel() > 0`, otherwise a `LogicException`), because the caller owns the transaction so that its audit row and state change commit together (FR-009);
3. locks the touched **customer** accounts `FOR UPDATE`, ordered by `account_id`;
4. checks each customer account's balance plus its lines is ≥ 0 (throws `insufficient_funds`, 409);
5. inserts the transaction and its lines.

Money arithmetic reuses `App\Support\Pricing\Money` (bcmath, never floats). The Action does not round: an amount with more than 4 decimal places is refused (US1 scenario 7), and rounding belongs to the callers (Part 3 §3.5).

**Rationale**: Part 3 §0 and Part 1 §7 ("no handler writes postings ad hoc"). The PHP pre-check gives a clean 409. The deferred triggers stay the final guard for any bypass.

**Alternatives**: a `SECURITY DEFINER` SQL posting function was rejected: forced RLS applies to the owner anyway, and PHP gives better errors and testability.

## R3 — RLS: customers read their own rows; only the new `ledger` scope may write

**Decision**:
- `account`, `ledger_transaction` and `ledger_posting` get `ENABLE` + `FORCE ROW LEVEL SECURITY`.
- **SELECT** is allowed when the scope is elevated (staff, system, bootstrap, maintenance) **or** is `ledger`, or when the row is the customer's own:
  - `account`: `customer_id = dahab_current_customer_id()`;
  - `ledger_posting`: its account is the customer's;
  - `ledger_transaction`: it has a posting on one of the customer's accounts.
- **INSERT** is allowed only when elevated or `ledger`. There are no UPDATE or DELETE policies, and the append-only triggers block those operations too.
- `DatabaseActor` gains a `ledger` scope. It is **not** in `ELEVATED`, so it grants nothing on other customer tables, and `DatabaseActor::ledger(Closure)` pushes it while keeping the current customer and staff ids. `PostLedgerEntryAction` does its reads, locks and inserts inside it.
- The deferred trigger functions fire at COMMIT, after the `ledger` frame has been popped. So they switch to `ledger` themselves: they save the scope, `set_config('app.rls_scope','ledger', true)`, compute, then restore the saved value. Without this, a customer-scope commit would sum only the customer's own lines and report a false imbalance.

**Rationale**: Constitution II and Part 1 §5.1 say wallet accounts join RLS. A customer-initiated posting (e.g. a future deposit hold or top-up) must write lines on internal accounts, which the customer cannot see. Least privilege: `ledger` sees the ledger and nothing else.

**Alternatives**:
- `DatabaseActor::elevate('system')` for writes: rejected, because it opens every customer table and muddles the audited meaning of `system`.
- No RLS on the ledger: rejected by the Constitution.
- A BYPASSRLS role: needs superuser to create and breaks the single-owner setup (memory: run artisan as the `dahab` user).

## R4 — Customer accounts are created by a database trigger; existing customers are backfilled

**Decision**: an AFTER INSERT trigger on `customer` inserts the `cust_available` and `cust_held` rows in the same transaction. The migration backfills existing customers with `INSERT … SELECT … ON CONFLICT DO NOTHING` and seeds the six internal singletons the same way. Both steps are idempotent, and `one_account_per_customer_kind` and `one_singleton_per_internal_kind` are the backstops.

**Rationale**: FR-002 requires the accounts "atomically with registration" and also for seeders, factories and any future path that creates a customer. One trigger covers all of them. Registration (bootstrap scope) and tests (maintenance scope) are elevated, so the insert passes RLS.

**Alternatives**: a call in `SubmitCustomerRegistrationAction` plus a factory hook was rejected: two places to forget, and seeders miss it.

## R5 — Concurrency: lock customer accounts only

**Decision**: lock only the customer accounts an entry touches, `SELECT … FOR UPDATE ORDER BY account_id`. Internal accounts (bank, escrow…) are never locked.

**Rationale**: under READ COMMITTED, two concurrent holds on one wallet each see only their own uncommitted lines, so the deferred non-negative trigger could pass both. Locking the customer's account row serializes them, and the second then sees the first's lines. The deterministic lock order prevents deadlocks when an entry touches two customers (buyer and seller). Locking singletons would serialize the whole platform on `bank`, and internal accounts may take any sign anyway.

**Alternatives**: SERIALIZABLE isolation was rejected, because it needs retries everywhere; advisory locks were rejected, because a row lock is simpler and visible.

## R6 — Errors

**Decision**: new `DomainApiException::insufficientFunds()` → **409 `insufficient_funds`** (the code Part 2 §4 and §8 already name). At commit, a `QueryException` with SQLSTATE `DH001` is mapped to the same 409 in `bootstrap/app.php`. `DH002` (unbalanced) is a programming error: 500, logged. Staff without `wallet.view` get the existing **403 `permission_denied`**, and `EnforceStaffPermission` already writes the `auth.staff.permission_denied` audit row naming `wallet.view`. That row is the "wallet access denial" of FR-019.

**Rationale**: Part 2 §9 says staff without the permission get `403 permission_denied`. Part 1 §8 lists `wallet_access_denied`, but it predates spec 002, which made wallet access an ordinary permission. The Part 1 row is amended rather than a second 403 code added.

**Alternatives**: a new `wallet_access_denied` code was rejected, because it adds a second 403 code for the same check and needs Dashboard handling.

## R7 — Balances and history are computed from lines

**Decision**:
- **Balances**: a `SUM` per account through the documented views (`account_balance`, `customer_wallet`, `solvency_check`, `ledger_global_zero`), queried with the customer's filter pushed down.
- **Entry order**: the sequence key of an entry is `MIN(posting_id)`, since lines of one entry are inserted together and `posting_id` is a global sequence. `created_at` is not unique within one DB transaction.
- **Customer history (FR-013)**: one row per entry touching the customer's accounts, newest first, with keyset pagination on the sequence key. Each row carries `available_change`, `held_change`, `available_after` and `held_after`. *As built*: a running window over the customer's own lines gives every balance-after; the page is cut on the lines alone, and only its rows are joined to `ledger_transaction` (whose RLS policy costs a subquery per row).
- **Statements (FR-016)**: oldest first. *As built*: the opening is **the total now minus everything since `from`**, so a long history is never regrouped. The period's entries come from the period's transactions first (the `created_at` index, a `MATERIALIZED` CTE), then their lines. Each row's before and after are opening plus the running sum. The period totals ride on the rows query as window aggregates. Keyset pagination: the cursor carries **only the position** (sequence key or period), and every figure is recomputed, so a crafted cursor can skip rows but never change a number. No signing is needed, and a malformed cursor is a 422. Grain `day` or `month` groups entries by Cairo date (`created_at AT TIME ZONE 'Africa/Cairo'`), with a count.
- **View accounts**:
  - `customer`: running balance on `cust_available`, held beside it;
  - `customers`: running balance on the sum of all `cust_available` + `cust_held`, so a hold nets to 0 and the row carries `moved_to_held`;
  - `dahab`: running balance on `dahab_commission` + `dahab_spread`, with the `vat_payable` closing reported separately.

**Rationale**: balances are never stored (schema, Part 2 §8), and this is the clarified hold rule (Clarifications, option A). The cursor follows the audit-log cursor pattern (spec 006).

**Alternatives**: a stored running balance per line was rejected by the schema ("no wallet balance is ever stored and mutated"). A materialized view was rejected: it adds a refresh problem and is not needed at the SC-004 scale.

## R8 — Performance at SC-004 scale

**Decision**:
- Index `ledger_posting(account_id, posting_id) INCLUDE (amount, ledger_txn_id)`: customer balances and history are index-only per account.
- Index `ledger_transaction(created_at)`: statement periods.
- The all-customers view joins `account` on kind, which is small (2 × customers).
- A benchmark test (`tests/Feature/Performance/LedgerPerformanceTest.php`) seeds 1,000,000 lines through a bulk SQL insert, balanced by construction; the per-row triggers are off for the insert only, and the test checks the global total is still 0. It asserts the SC-004 timings. It is opt-in: `phpunit.xml` excludes the `perf` group, so `composer test` stays fast.

**As built — what the benchmark found and fixed (2026-09-28)**:
- **RLS per-row cost.** The ledger policies called `current_setting()` once per row, and the planner could not estimate them. Summing 500k lines took 3.7 s through nested loops. The fix is to wrap the scope checks in scalar subqueries (`(SELECT dahab_rls_elevated())`, `(SELECT dahab_current_customer_id())`): PostgreSQL then evaluates them once per query as InitPlans. This is in the migration and in `05_schema_security.sql`; other tables' policies are unchanged.
- **Opening balance.** "Total now minus since the start" instead of regrouping the whole history: 8 s → about 0.7 s.
- **Period entries.** Start from the period's transactions (a `MATERIALIZED` CTE): 5–9 s → about 0.55 s.
- **Customer history.** Page on lines, then join the page's transactions: 545 ms → about 60 ms.
- **Results on the development laptop, steady state:**
  - customer wallet + first history page with 10,000 movements: about 0.15 s (target 1 s);
  - one-month all-customers statement with 1,000,000 lines: about 1.3 s (target 2 s).

  The first read of freshly bulk-inserted rows pays a one-time hint-bit cost, so the test reads them once before timing. Runs taken right after other heavy test runs were noisier (up to 2.6 s).
- **Remaining headroom.** The all-customers opening balance is a full pass over the customer lines. When volumes grow past this, the documented daily-close snapshot (schema `daily_close`, a later feature) is the place to start the opening from.

**Rationale**: SC-004 is measurable only with volume, and running it by default would slow CI.

## R9 — Labels live in one enum

**Decision**: `App\Enums\LedgerEventKind` (the 15 schema values) has `staffLabel()` (Dashboard wording, e.g. "Sale settled", "Deposit held", "Compensation from Dahab") and `customerKey()`. `AccountKind` has 8 cases with `isCustomer()`.
- **Customer API**: returns `kind`. Flutter maps it to en/ar text in `lib/core/i18n`, as spec 007 did for suspension reasons.
- **Dashboard API**: returns `kind` + `label`.
- The customer never sees the `memo`, which is staff text.

**Rationale**: the Customer App is bilingual and already localises codes; the Dashboard is English and uses server labels, as in the audit log.

## R10 — "Entered by hand"

**Decision**: `by_hand = staff_id IS NOT NULL AND staff.is_system = false`.

**Rationale**: this matches the design's amber rows ("entered by hand rather than produced by a transaction"). Scheduled jobs (the system actor) are automatic.

## R11 — The permission `wallet.view`

*As built, R5*: `SELECT … FOR UPDATE` under forced RLS only sees rows that pass an **UPDATE** policy. Without one, the lock matched nothing — silently — and the money service posted unlocked. `account` and `ledger_transaction` therefore have a lock-only policy, `FOR UPDATE USING ((SELECT dahab_rls_scope()) = 'ledger') WITH CHECK (false)`: locks are allowed, and every actual UPDATE is still refused. The Action also refuses to post if it locked fewer rows than it asked for. `DatabaseActor::ledger()` runs its work in a savepoint, so a failed statement cannot leave the scope pop on an aborted transaction and hide the real error.

**Decision**: `StaffPermission::WALLET_VIEW = 'wallet.view'`, labelled "View wallets and statements", in group "Money". Seed roles: `[finance]`; `ceo` gets every code, and `coo` is deliberately absent (spec 002 FR-051). It gates the four Dashboard wallet endpoints, the customer file's wallet panel, the Overview wallet and safety panels, and the Money → Wallet statement menu item.

**Rationale**: Part 1 §4.2 has "View a wallet balance" and "Open a wallet statement" as CEO + Finance. The two rows are one code because every statement shows balances. Splitting them later is additive.

## R12 — Auditing reads

**Decision**:
- Opening a **one-customer** statement writes `ledger.statement.viewed`, with the subject being the customer and the view, period and grain in `after`.
- An export writes `ledger.statement.exported` (any view).
- The all-customers and Dahab views, the customer-file wallet panel and the overview figures are not audited (aggregates, or already covered by the file-opened audit).
- Two new `AuditEvent` cases, with label and category `money` (a new `AuditCategory` case).

**Rationale**: FR-019. It mirrors the rule that opening identity documents is audited, without flooding the log with dashboard refreshes.

## R13 — Consumers

**Decision**:
- **Dashboard**:
  - `src/types/wallet.ts`, `src/services/wallet.service.ts` and `src/composables/useWallet.ts`;
  - a new `pages/statement/index.vue` (route `/dashboard/statement`; the nav item gets `permission: 'wallet.view'` and loses `hidden`);
  - a `CustomerWalletPanel` on the customer file;
  - the Overview's `hero` (safety figure) and `wallets` panels are loaded from the API when the viewer holds `wallet.view` and hidden otherwise; the other Overview panels stay mock.
- **Flutter**:
  - `ApiWalletRepository` implements `summary()` and `transactions()` against the API; `topUpMethods()` and `invoices()` keep delegating to the mock (their features are not built);
  - the models change from `int` to `num`: `WalletSummary` and `WalletTxn` parse the API's decimal strings for **display only**, and the app does no money arithmetic (the total comes from the API). Display rounds to whole EGP, as the prototype does;
  - `test/flows_test.dart`'s fake backend gains the two endpoints.

**Rationale**: the multi-project rule, and not faking features that do not exist (golden rule 1).

## R15 — Sign of the bank account (a docs inconsistency, corrected)

**Finding**: the schema says the lines of every entry sum to 0 and that "the sum of ALL postings across ALL accounts is always zero". Under that rule, money arriving in the bank must be posted as `bank −X, customer +X`: the bank is the one asset account, and its ledger balance is the negative of the cash held. Part 3 §6.3 already follows this (`external_equity −advance`, `seller +advance`). But three documents are written as if the bank rose with deposits:
- `solvency_check` computes `bank_balance − owed_to_customers`, which is `−cash − owed`;
- Part 2 §8 top-up says "`bank +amount`, `cust_available +amount` (balanced)", and that does not sum to 0;
- Part 2 §9 withdrawal release says "customer hold −amount, `bank` −amount", which does not sum to 0 either; Part 3 §11 repeats it.

**Decision**: keep the locked zero-sum convention and correct the docs.
- The cash in the bank is `−SUM(bank lines)`. `solvency_check` becomes `bank_balance = −SUM(bank)` and `headroom = bank_balance − owed_to_customers`.
- Part 2 §8 top-up becomes `bank −amount, cust_available +amount`. Part 2 §9 release becomes `customer hold −amount, bank +amount`.
- Every screen and API shows the bank as cash, a positive figure.
- The docs gain a sign note: `bank` is the only account whose ledger balance is the negative of what it represents.

**Status**: approved by the user on 2026-09-28. The docs were corrected the same day: `03_schema_ledger.sql`, `00_schema_full.sql`, Part 2 §8–§9 and Part 3 §11. The MySQL design files (`00_schema_mysql.sql`, `Dahab_Database_Design_MySQL.md`) are not the target stack and are left as they are.

**Rationale**: the zero-sum invariant is the schema's central guarantee (the balanced trigger, `ledger_global_zero`). The alternative breaks it. Only the display of one account changes.

**Alternatives**: normal-balance (debit/credit) accounts were rejected, because they redefine the documented model, the triggers and the views.

## R14 — Out of scope

No endpoint in this feature moves money (Clarifications). Top-up, withdrawals (and their pending-hold account), compensation, wallet adjustment, bank movements, daily close, settlement and reversal endpoints come later. They call `PostLedgerEntryAction` and `ReverseLedgerEntryAction`, which this feature builds and tests directly.
