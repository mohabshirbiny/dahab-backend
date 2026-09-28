# Ledger Core

> File: `docs/features/ledger-core.md` · Branch: `feature/ledger-core` (backend, dashboard; Flutter has no repo)
> Status: done (pending review and merge) · Date: 2026-09-28 · Spec Kit: [`specs/008-ledger-core/`](../../specs/008-ledger-core/spec.md)

## Goal

The platform's double-entry EGP ledger (`docs/Database schema/03_schema_ledger.sql`). It has:
- customer accounts (available, held) and Dahab's internal accounts (escrow, commission, spread, VAT payable, bank, external equity);
- append-only entries whose lines always sum to zero, and customer balances that never go negative;
- one money service that every later money feature posts through.

On top of it, read-only surfaces:
- the customer's own wallet and history (Customer App);
- CEO / Finance's Wallet statement in three views, the customer-file wallet panel, and the Overview safety figure (Dashboard).

**No endpoint in this feature moves money.** Wallet Top-up comes next.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (non-breaking: 2 customer GETs, 4 dashboard GETs)
Dashboard:    YES
Customer App: YES
Auth:         NO  (the customer gate `verified` is reused)
Permissions:  YES (new wallet.view — ceo, finance; not coo)
```

## Backend Impact

- **Money service**, in `app/Actions/Ledger`:
  - `PostLedgerEntryAction`: validates, locks the customer accounts, and writes inside the caller's transaction;
  - `ReverseLedgerEntryAction`.
- **Reads**, in `app/Actions/Wallet`: the customer wallet and history; the ledger overview; the statement and its export.
- **Plumbing**: the new `ledger` RLS scope in `DatabaseActor`. Enums `AccountKind` and `LedgerEventKind`. Models `Account`, `LedgerTransaction` and `LedgerPosting`.

## Database Impact

One migration:
- the types, tables, triggers and views of `03_schema_ledger.sql`, plus forced RLS on the three tables;
- a trigger on `customer` that creates both accounts; a backfill for existing customers; the six internal singletons;
- two read indexes, and a partial unique index so an entry is reversed at most once.

**Docs correction (approved 2026-09-28, research R15).** Signed amounts summing to zero are a true double entry (negative = debit). Money arriving is therefore `bank −X`, and the cash in the bank is `−SUM(bank)`. `solvency_check`, Part 2 §8 (top-up), Part 2 §9 and Part 3 §11 (withdrawal release) were corrected. Screens show the bank as positive cash.

## API Changes

All are new and non-breaking. See [`specs/008-ledger-core/contracts/wallet-api.md`](../../specs/008-ledger-core/contracts/wallet-api.md).

- `GET /customer/me/wallet` · `GET /customer/me/wallet/transactions` (gate `verified`)
- `GET /dashboard/wallets/overview` · `GET /dashboard/customers/{customer}/wallet` · `GET /dashboard/wallet-statement` · `GET /dashboard/wallet-statement/export` (`wallet.view`)
- New error codes: `insufficient_funds` (409) and `ledger_already_reversed` (409), both raised by the service.

## Dashboard Impact

- The Money → Wallet statement page: three views (one customer, all customers, Dahab) and three grains, with export.
- The customer-file wallet panel.
- On the Overview, three items go live when the viewer holds `wallet.view` (and are hidden otherwise): the safety figure, the Customer wallets panel and "Held on open orders". "Dahab earned this month" is hidden until Settlement exists.

## Customer App Impact

- `ApiWalletRepository`: the summary and the transactions come from the API; top-up methods and invoices stay mock.
- The wallet models move from `int` to `num` (display only).
- en/ar labels for the 15 event kinds, and an empty state.
- The fake backend in `test/flows_test.dart` serves both endpoints.

## Authentication / Authorization

- **Customer**: own wallet only. This is enforced by PostgreSQL RLS as well as the route. Suspended customers may read.
- **Staff**: `wallet.view` on every wallet read. Without it: 403 `permission_denied`, audited.

## Permissions

`wallet.view`, "View wallets and statements", group "Money". Seeded to CEO and Finance (not the COO), editable from Staff and permissions.

## Validation

Statement query: `view` is required; `customer_id` is required only for `view=customer`; `from ≤ to`; the range is at most 366 days; `grain` is one of `each`, `day`, `month`; `per_page` is 1–200. History `per_page` is 1–100. Cursors are signed.

## Error Handling

`verification_required` (customer), `permission_denied` (staff) and `validation_failed`. `insufficient_funds` can't be reached from any endpoint in this feature.

## UI States

Loading, empty (no movements), error and permission-hidden, on the statement page, the wallet panel, the Overview panels and the app's Wallet.

## Testing

Pest:
- the schema and trigger refusals, account provisioning, posting and reversal, concurrency and RLS — all at the Action level against real PostgreSQL, under the temporary Principle V waiver: HTTP tests arrive with Top-up;
- every endpoint through HTTP, including statement reconciliation and permissions;
- opt-in perf tests (`--group=perf`).

Dashboard: type-check, lint, build. Flutter: analyze, test, build.

**Results (2026-09-28):**
- Backend: Pest 775 passed (the full suite, `perf` excluded); the perf group passes (SC-004); Pint is clean for this feature's files (two pre-existing files on `main` still fail Pint and are untouched); OpenAPI generated with all 6 paths; `migrate:fresh --seed`, `migrate:rollback --step=1` and `migrate` all OK.
- Dashboard: `type-check` and `build` OK; this feature's files are lint-clean (the repo's existing lint errors are in untouched files and in a leftover `.claude/worktrees` folder that ESLint scans).
- Flutter: `analyze` has no issues; `test` 27 passed; `build web --release` OK.
- A manual browser click-through against a running Backend was not done.

## Breaking Changes

None.

## As built — worth knowing

- **Row locks under forced RLS need an UPDATE policy.** `SELECT … FOR UPDATE` sees only rows that pass one; without it the money service's lock silently matched nothing. `account` and `ledger_transaction` have a lock-only policy (`USING (scope = 'ledger') WITH CHECK (false)`), and the Action refuses to post unlocked.
- **RLS scope checks are InitPlans** (`(SELECT dahab_rls_elevated())`), evaluated once per query instead of once per row. The benchmark found per-row evaluation cost 3.7 s on 500k lines.
- **Performance (SC-004), measured with 1,000,000 lines** (steady state, development laptop):
  - customer wallet + first history page: about 0.15 s (target 1 s);
  - one-month all-customers statement: about 1.3 s (target 2 s).

  The benchmark is opt-in (`perf` group). The heaviest part is the all-customers opening balance (a full pass over customer lines); the daily-close snapshot is where to start from when volumes grow.
- **Statement cursor** only positions the page (not signed): every figure is recomputed server-side.

## Follow-ups (not in this feature)

- **Wallet Top-up** is next: the first money-moving endpoint, which brings the HTTP-boundary tests for posting. That ends the temporary Principle V waiver.
- **Finance and `customer.view`** (decided 2026-09-28): Finance's seed roles now include `customer.view`, so Finance can find a customer on the Wallet statement page. This is seed-only: on an existing install, add it to Finance from Staff and permissions → Roles. Document images still need `identity.view`, which Finance does not hold.
- **Flutter:** the Withdraw screen, the Held screen and the invoices still show mock figures (withdrawals, orders and invoices are not built).
- **`reference`** on history and statement rows is `null` until orders and listings exist.

## Migration / Compatibility

Deploy the Backend first. The migration backfills accounts for existing customers, and its rollback drops the ledger (it holds no data before this feature). Older app builds keep their mock wallet.
