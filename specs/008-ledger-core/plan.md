# Implementation Plan: Ledger Core

**Branch**: `feature/ledger-core` (backend + dashboard; the Customer App has no repository). Spec work is on `claude/ledger-core-spec-008-e92642`. | **Date**: 2026-09-28 | **Spec**: [spec.md](./spec.md)

## Summary

The double-entry EGP ledger from `03_schema_ledger.sql`, made of:
- the accounts (per customer, available and held, created by a trigger on `customer` and backfilled; one of each internal kind), with append-only transactions and postings and the balanced and non-negative deferred triggers;
- one money service (`PostLedgerEntryAction`, `ReverseLedgerEntryAction`), which validates, locks the customer accounts and writes inside the caller's transaction;
- derived balances;
- forced RLS on the three tables, with a new write-only `ledger` scope.

It also adds the read surfaces:
- the customer's wallet and history;
- the staff's Wallet statement in three views (one customer, all customers, Dahab) and three grains, with CSV export;
- the customer-file wallet panel;
- the Overview's safety figure and Customer wallets panel, all behind the new `wallet.view` permission.

No endpoint moves money (Clarifications). The design also corrects a sign error in the docs: the bank's cash is `−SUM(bank lines)` (research R15).

## Impact analysis

```
Backend:          YES — migration, 2 enums, 3 models, money service (post, reverse), read queries, 6 GET endpoints, permission, 2 audit events, error codes, tests, Postman
Database:         YES — 2 types, 3 tables, 4 views, 5 triggers, RLS on 3 tables, trigger on customer, backfill + singleton seed, 2 indexes
API:              YES — non-breaking: 2 new customer GETs, 4 new dashboard GETs, new error codes (insufficient_funds, ledger_already_reversed)
Dashboard:        YES — Wallet statement page, customer-file wallet panel, Overview hero + wallets live, nav item + permission
Customer App:     YES — ApiWalletRepository (summary + transactions), models int → num, en/ar labels per event kind, fake backend in tests
Auth:             NO  — the customer gate `verified` is reused; the new DatabaseActor `ledger` scope is RLS plumbing, not auth
Permissions:      YES — new code wallet.view (ceo, finance; not coo)
API models/types: YES — Dashboard src/types/wallet.ts; Flutter lib/models/wallet.dart
```

**Classification**: every change is additive, so non-breaking.
- The Dashboard gains a permission string; the `usePermissions` typing grows by one code.
- Flutter's `WalletSummary` and `WalletTxn` field types change internally (no API consumer outside the app).

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary Dependencies**: bcmath via `App\Support\Pricing\Money`; Spatie permission; `DatabaseActor`; the audit presenter (spec 006); the CSV export pattern (spec 006); TanStack Vue Query.
- **Storage**: PostgreSQL 16. Deferred constraint triggers, forced RLS, append-only triggers. Migrations mirror the schema docs, which are updated first.
- **Testing**: Pest feature tests through HTTP for every endpoint. There are also service-level tests of the money service inside a transaction: US1 has no endpoint by design, so it is tested at the Action boundary with real Postgres (Constitution V is satisfied for the read endpoints; posting has no HTTP boundary yet, see Complexity Tracking). The service tests cover:
  - the SQL-level refusals of direct UPDATE, DELETE and INSERT;
  - the RLS matrix;
  - concurrency with two DB connections;
  - statement reconciliation property checks.

  Perf tests are in the opt-in `perf` group.
- **Performance Goals**: SC-004 — wallet and first history page < 1 s at 10k movements; one-month statement < 2 s at 1M lines.
- **Constraints**:
  - no floats;
  - named actor (DB CHECK);
  - customer accounts ≥ 0;
  - Σ = 0 per entry and globally;
  - append-only for everyone;
  - Cairo days for periods.
- **Scale/Scope**: 6 GET endpoints; 1 new Dashboard page and 2 panels; Flutter wallet wiring.

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | `ledger_txn_has_actor` CHECK; the money service requires an actor; jobs will use the system actor | ✅ |
| II. Isolation by engine, staff authz by data | Forced RLS on account, transaction and posting; `ledger` scope with least privilege; `wallet.view` is catalogue data, editable from the Dashboard, no PG grants | ✅ |
| III. Docs first | `03_schema_ledger.sql`, `00_schema_full.sql` and the `05` RLS list are updated before the migration (R1, R15). Also: Part 1 §5.1–§5.2 and §8 (the `wallet_access_denied` row → `permission_denied`), Part 2 §1 (accounts via trigger), §8 (as-built routes, corrected top-up signs), §9 (release signs, statement endpoints), Part 3 §0 (the lock rule), `api-contract.md` (new permission, money strings), `docs/features/ledger-core.md`, CLAUDE.md "Current state" | ✅ |
| IV. Foundation before modules | Wallets is a module. Its foundation arrives together: migrations matching the schema, Requests and Resources, Actions, Pest tests (happy + refusal), `#[OA]` on every endpoint | ✅ |
| V. Test the boundary and the ledger | Read endpoints through HTTP. Posting is tested at the Action level against real PostgreSQL. **Temporary written waiver approved by the user (2026-09-28)**: it goes in the PR body citing Principle V; Top-up adds the HTTP-boundary tests | ⚠ waived (approved) |
| Reversible migrations | `down()` drops the views, triggers, policies, tables and types in reverse order. Ledger data is lost on rollback; there is none before this feature | ✅ |

## Project Structure

### Documentation

```text
specs/008-ledger-core/{spec,plan,research,data-model,quickstart}.md, contracts/wallet-api.md, checklists/requirements.md, tasks.md (next)
docs/features/ledger-core.md
```

### Source Code

```text
backend
  docs/Database schema/{03_schema_ledger,05_schema_security,00_schema_full}.sql · docs/Technical Spec/{part1,part2,part3} · docs/platform/api-contract.md
  database/migrations/2026_10_01_000010_create_ledger.php        (types, tables, triggers, views, RLS, customer trigger, seed + backfill)
  app/Enums/{AccountKind,LedgerEventKind}.php · StaffPermission (+WALLET_VIEW) · AuditEvent (+2) · AuditCategory (+MONEY)
  app/Support/DatabaseActor.php (+ledger scope, ::ledger()) · app/Support/Ledger/{LedgerEntry,LedgerLine,StatementCursor,HistoryCursor}.php
  app/Models/{Account,LedgerTransaction,LedgerPosting}.php · Customer (+accounts())
  app/Actions/Ledger/{PostLedgerEntryAction,ReverseLedgerEntryAction}.php
  app/Actions/Wallet/{ShowCustomerWalletAction,ListCustomerWalletHistoryAction,ShowLedgerOverviewAction,BuildWalletStatementAction,ExportWalletStatementAction}.php
  app/Exceptions/DomainApiException.php (+insufficientFunds, ledgerAlreadyReversed) · bootstrap/app.php (DH001 → 409)
  app/Http/Requests/{Customer/WalletHistoryRequest, Dashboard/Wallet/WalletStatementRequest}.php
  app/Http/Resources/{Customer/WalletResource, Customer/WalletHistoryRowResource, Staff/WalletStatementResource, Staff/LedgerOverviewResource}.php
  app/Http/Controllers/Api/V1/Customer/WalletController.php · Dashboard/WalletController.php · routes/api.php
  database/factories/{Account,…} helpers · tests/Support/Ledger.php (test posting helper)
  postman/Dahab-Backend.postman_collection.json (+6 requests)
  tests/Feature/Ledger/{LedgerSchemaTest,AccountProvisioningTest,PostLedgerEntryTest,ReverseLedgerEntryTest,LedgerConcurrencyTest,LedgerIsolationTest}.php
  tests/Feature/Wallet/{CustomerWalletTest,CustomerWalletHistoryTest,WalletOverviewTest,CustomerFileWalletTest,WalletStatementTest,WalletStatementExportTest,WalletPermissionTest}.php
  tests/Performance/LedgerPerformanceTest.php (group perf)
dashboard
  src/api/endpoints.ts · src/types/wallet.ts · src/services/wallet.service.ts · src/composables/useWallet.ts
  src/pages/statement/index.vue · router (replace the placeholder) · src/mock/nav.ts (permission, unhide)
  src/components/wallet/{StatementFilters,StatementSummary,StatementTable}.vue · src/components/customer-file/CustomerWalletPanel.vue
  src/services/overview.service.ts + pages/dashboard/overview.vue (hero + wallets from the API when permitted, hidden otherwise)
  src/types/staff.ts (permission union) · docs, CLAUDE.md
flutter (no git)
  lib/models/wallet.dart (num + fromJson) · lib/services/api/wallet_api.dart (ApiWalletRepository) · lib/main.dart (wire)
  lib/core/i18n (15 event-kind labels en/ar) · features/wallet/wallet_screens.dart (fields, empty state)
  test/flows_test.dart (fake /me/wallet*)
```

**Structure Decision**: the existing layering. `app/Actions/Ledger` holds the writers (platform-internal); `app/Actions/Wallet` holds the reads behind endpoints.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R15)
- [data-model.md](./data-model.md)
- [contracts/wallet-api.md](./contracts/wallet-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no new violations. Principle V is waived temporarily (approved); see Complexity Tracking.

**PR waiver text** (paste into the PR body): *Constitution Principle V waiver (temporary, approved by the product owner 2026-09-28): spec 008 is core-only and has no endpoint that moves money. PostLedgerEntryAction and ReverseLedgerEntryAction are covered by Action-level Pest tests against real PostgreSQL (triggers, RLS, concurrency). HTTP-boundary tests arrive with the first money-moving endpoint, Wallet Top-up.*

## Follow-ups (not in this feature)

- **Wallet top-up** (transfer notice → Finance match) is next, and must land before buy requests. It gives the money service its first HTTP boundary.
- Withdrawals (and their pending-hold account), compensation, wallet adjustment, bank movements, daily close, settlement.
- References (`reference`) fill in when orders and listings exist.

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `ledger` RLS scope + trigger self-scope | Customer-context postings touch internal accounts; deferred triggers fire after the scope frame pops | `system` elevation opens every customer table; no RLS violates Principle II |
| Posting tested at the Action boundary, not HTTP (Principle V) | The user chose "core only": no endpoint moves money yet | A test-only posting endpoint would be an unspecified money endpoint in the app |
| Trigger on `customer` for accounts | One guarantee for registration, seeders and factories | Per-call-site creation is easy to forget |
| Bank sign correction (R15) | The zero-sum invariant makes the bank's ledger balance the negative of its cash | Normal-balance accounts redefine the documented model |
