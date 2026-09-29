# Implementation Plan: Wallet Top-up

**Branch**: `feature/wallet-topup` (backend + dashboard; the Customer App has no repository). Spec work is on `claude/wallet-topup-spec-009-1981ef`. | **Date**: 2026-09-29 | **Spec**: [spec.md](./spec.md)

## Summary

The first way money enters Dahab: a manual transfer, never a gateway.

- **Receiving accounts** — CEO/Finance keep Dahab's bank, InstaPay and Vodafone Cash accounts in a typed, never-deleted table, edited from a new Dashboard page and audited.
- **Transfer notice** — a verified, non-suspended customer reads the active accounts and their reference `DAHAB-<display_ref>`, optionally uploads a receipt (encrypted private store, new upload purpose), and submits a notice (`pending`). They can list and cancel their own notices.
- **Incoming transfers** — staff with `topup.match` list, search, export and open notices, view the receipt, and **match** (credit what arrived), **hold / un-hold** or **reject** (fixed reason + note), or **credit by hand** when no notice exists.
- **Credit** — one transaction: row lock → `PostLedgerEntryAction` (`topup`: bank −X, customer available +X) → status `credited` with the unique ledger id → audit. SMS/email after commit.
- **Guarantees in the data** — `topup.ledger_txn_id UNIQUE`, per-status CHECKs, a trigger that freezes final states, forced RLS.
- **Tests** — first HTTP-boundary posting tests; the spec 008 Principle V waiver ends.
- **Crediting a suspended customer** (match or by hand) — staff-side reconciliation only, with the provider's transaction reference (`arrival_reference`) required and the customer's status audited. The customer-side trade gate is unchanged; a suspended customer may still list and cancel their own pending notices (spec FR-016, FR-018, FR-018a).
- **Release** — US4 (Receiving accounts) is P2 in build order but blocks production: the seeded accounts are fake and local-only (analysis C1).

## Impact analysis

```
Backend:          YES — migration, 4 enums, 2 models, 15 Actions, 3 Request groups, Resources, 3 controllers, 2 notifications, 2 permissions, 8 audit events, 1 error code, throttle, seeder, tests, Postman
Database:         YES — 4 types, 2 tables, CHECKs, 3 triggers, RLS on topup, 3 indexes
API:              YES — non-breaking: 4 customer + 12 dashboard endpoints, new upload purpose, new error code; `reference` filled for topup rows in wallet history/statement
Dashboard:        YES — Incoming transfers page (list, detail, receipt, match/hold/reject, credit by hand), Receiving accounts page, nav + 2 permission strings, statement reference shown
Customer App:     YES — AddFundsScreen live (accounts, reference, receipt, submit), top-up history with cancel, idempotency header, models, fake backend
Auth:             NO  — reuses customer.gate (trade/verified) and staff.permission; the gate's check is extracted for the upload purpose, behaviour unchanged
Permissions:      YES — topup.match, topup.accounts.manage (finance + CEO; never coo)
API models/types: YES — Dashboard src/types/topup.ts; Flutter lib/models/wallet.dart (+ TopUp, ReceivingAccount)
```

**Classification**: all additive → **non-breaking**.
- The `reference` field in wallet history and the statement changes from always-null to a string for top-up rows. Both consumers already render it.
- The Dashboard's permission union grows by two codes.
- `TopUpMethod` in Flutter is reshaped internally (no external consumer).

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary Dependencies**:
  - Backend: `PostLedgerEntryAction` and `LedgerEntry` (spec 008), the `idempotent` middleware (spec 007), `RecordAuditLogAction` (spec 006), `IdentityDocumentStorage` and `UploadTokenStore` (spec 001), `EnsureCustomerStanding` (spec 002), the `SmsChannel` notifications, the CSV export pattern (spec 006/008).
  - Dashboard: TanStack Vue Query and `useIdempotencyKey`.
  - Flutter: `file_picker` (already present).
- **Storage**: PostgreSQL 16 (CHECKs, triggers, forced RLS). The private encrypted disk `identity_private` holds receipts. Redis holds upload tokens, the queue and the throttle.
- **Testing**: Pest feature tests through HTTP, asserting on:
  - rows, ledger postings, balances, bank cash and the global zero;
  - audit rows, `Notification::fake`, and idempotent replay.

  Also:
  - a schema test for the CHECKs and triggers (direct SQL);
  - a concurrency test (two connections: match vs cancel, match vs match);
  - an RLS test (customer A cannot see B's notices).
- **Performance Goals**: the Incoming transfers list is < 500 ms at 100k notices (status/date index); the customer's list is < 300 ms. Checked by the optional `perf`-group test T068.
- **Constraints**:
  - no floats (bcmath strings);
  - named actor on every change;
  - at most one credit per notice;
  - final states are immutable;
  - the COO never gets wallet codes by seed;
  - Cairo dates in filters.
- **Scale/Scope**: 16 endpoints, 2 Dashboard pages, 1 Flutter screen wired and 1 added (history).

No open NEEDS CLARIFICATION: the five clarifications are in the spec, and design choices are R1–R15 in [research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Every credit posts with `actorStaffId`. Hold, reject and credit columns name the staff member (CHECKs). Customer submit/cancel rows belong to the customer. Audit on every staff action | ✅ |
| II. Isolation by engine, staff authz by data | `topup` gets forced RLS (own rows or elevated). `receiving_account` is reference data. `topup.match` and `topup.accounts.manage` are catalogue codes editable from the Dashboard, with no PG grants | ✅ |
| III. Docs first | Updated before the migration: `03_schema_ledger.sql` (new "Top-ups" section), `00_schema_full.sql`, the `05` RLS list. Also updated: Part 1 §4.2 (codes beside the matrix rows), Part 2 §8 (as-built customer paths, gate, receipt purpose) and §9 (as-built match/hold/reject/by-hand paths, receiving accounts), and Part 3 §11 ("money in only from an account in their own name" → staff judgement on evidence; the product-owner decision). Plus `api-contract.md` (error code, upload purpose), `docs/features/wallet-topup.md`, CLAUDE.md "Current state" | ✅ |
| IV. Foundation before modules | The migration matches the updated schema; Requests, Resources, Actions, Pest (happy + refusal) and `#[OA]` on every endpoint | ✅ |
| V. Test the boundary and the ledger | Match and by-hand credit are tested through HTTP, with assertions on ledger rows, postings, balances, audit and notifications. **This closes the spec 008 waiver** (R14) | ✅ |
| Reversible migrations | `down()` drops the triggers, policies, tables and types in reverse. Top-up rows are lost on rollback, but the ledger entries they produced remain (append-only), which is documented in the migration's docblock | ✅ |

## Project Structure

### Documentation

```text
specs/009-wallet-topup/{spec,plan,research,data-model,quickstart}.md, contracts/topup-api.md, checklists/requirements.md, tasks.md (next)
docs/features/wallet-topup.md
```

### Source Code

```text
backend
  docs/Database schema/{03_schema_ledger,05_schema_security,00_schema_full}.sql · docs/Technical Spec/{part1,part2,part3} · docs/platform/api-contract.md
  database/migrations/2026_10_02_000010_create_topups.php
  database/seeders/ReceivingAccountSeeder.php (+ DatabaseSeeder) · database/factories/{ReceivingAccountFactory,TopUpFactory}.php
  config/dahab-wallet.php (topups_per_minute) · AppServiceProvider (throttle customer.topups)
  app/Enums/{TopUpMethod,TopUpOrigin,TopUpStatus,TopUpRejectReason}.php · UploadPurpose (+TOPUP_RECEIPT)
  app/Enums/StaffPermission.php (+TOPUP_MATCH, +TOPUP_ACCOUNTS_MANAGE) · AuditEvent.php (+8, labels, category money)
  app/Models/{ReceivingAccount,TopUp}.php · Customer (+topUps())
  app/Support/TopUpReference.php · app/Services/IdentityDocumentStorage.php (+storeAt; doc widened)
  app/Http/Middleware/EnsureCustomerStanding.php (extract static assert() for the upload purpose)
  app/Actions/TopUp/{ListTopUpMethodsAction,SubmitTopUpNoticeAction,ListCustomerTopUpsAction,CancelTopUpNoticeAction,
                     ListTopUpsAction,ExportTopUpsAction,ShowTopUpAction,ReadTopUpReceiptAction,MatchTopUpAction,
                     HoldTopUpAction,UnholdTopUpAction,RejectTopUpAction,CreditTopUpByHandAction}.php
  app/Actions/TopUp/{CreateReceivingAccountAction,UpdateReceivingAccountAction}.php
  app/Actions/Wallet/{ListCustomerWalletHistoryAction,BuildWalletStatementAction}.php (reference TOP-n)
  app/Notifications/{TopUpCreditedNotification,TopUpRejectedNotification}.php
  app/Exceptions/DomainApiException.php (+illegalTopUpTransition) · bootstrap/app.php (DH003 → 409)
  app/Http/Requests/{Customer/TopUp/*, Dashboard/TopUp/*, Dashboard/ReceivingAccount/*}.php · Identity/StoreUploadRequest (purpose rules)
  app/Http/Resources/{Customer/TopUpResource, Customer/ReceivingAccountResource, Staff/TopUpResource, Staff/ReceivingAccountResource}.php
  app/Http/Controllers/Api/V1/Customer/TopUpController.php · Dashboard/{TopUpController,ReceivingAccountController}.php · routes/api.php
  postman/Dahab-Backend.postman_collection.json (folder "Wallet top-up", +16 requests, upload purpose example)
  tests/Feature/TopUp/{TopUpSchemaTest,TopUpMethodsTest,SubmitTopUpNoticeTest,CustomerTopUpListTest,CancelTopUpNoticeTest,
                       IncomingTransfersListTest,TopUpReceiptTest,MatchTopUpTest,TopUpConcurrencyTest,HoldRejectTopUpTest,
                       CreditTopUpByHandTest,TopUpNotificationTest,TopUpIsolationTest,TopUpPermissionTest}.php
  tests/Feature/ReceivingAccount/ReceivingAccountTest.php · tests/Feature/Wallet/* (reference TOP-n assertions)
dashboard
  src/api/endpoints.ts · src/types/topup.ts · src/types/staff.ts (permission union) · src/constants (PERMISSIONS)
  src/services/topup.service.ts · src/composables/{useTopUps,useReceivingAccounts}.ts
  src/pages/transfers/index.vue · src/pages/receiving-accounts/index.vue · router (replace the placeholder, add route)
  src/components/transfers/{TransferFilters,TransferTable,TransferDrawer,MatchForm,HoldForm,RejectForm,CreditByHandForm,ReceiptPreview}.vue
  src/components/receiving-accounts/{AccountTable,AccountForm}.vue · src/mock/nav.ts (transfers unhidden + permission; Receiving accounts under Controls)
  docs, CLAUDE.md
flutter (no git)
  lib/models/wallet.dart (ReceivingAccount, TopUpMethodGroup, TopUp, TopUpStatus; TopUpMethod replaced)
  lib/services/api/api_client.dart (optional Idempotency-Key header; uuid v4 via Random.secure)
  lib/services/api/wallet_api.dart (topUpMethods, uploadReceipt, submitTopUp, topUps, cancelTopUp)
  lib/services/repositories.dart · lib/services/mock_repositories.dart · lib/mock/mock_wallet.dart
  lib/features/wallet/wallet_screens.dart (AddFundsScreen live; TopUpsScreen) · router (R.topups) · i18n en/ar (detail keys, statuses, reasons)
  test/flows_test.dart (fake /me/wallet/topup*, /me/uploads topup_receipt)
```

**Structure Decision**: follow the existing layering. `app/Actions/TopUp` holds every top-up writer and reader. The ledger stays written only by `PostLedgerEntryAction`.

**Dashboard placement**:
- *Incoming transfers* follows the design page. The design's "best guess" column is not built, because there is no bank feed (research R1): the list shows the notice's customer and reference instead.
- *Receiving accounts* is not in the design. It is added as a settings-like page under **Controls**, next to Commission rates, as the product owner asked ("managed from the Dashboard, settings-like place").

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R15)
- [data-model.md](./data-model.md)
- [contracts/topup-api.md](./contracts/topup-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no violations, and the Principle V waiver is closed.

**PR note** (paste into the backend PR body): *Constitution Principle V: the temporary waiver from spec 008 is closed. Wallet Top-up adds the first money-moving endpoints (`POST /dashboard/topups/{topup}/match`, `POST /dashboard/topups`), covered by HTTP feature tests that assert the ledger entry, postings, balances, audit and notifications.*

## Follow-ups (not in this feature)

- Correcting a wrong credit (reversal / wallet adjustment, CEO only) comes with the wallet-adjustment feature.
- Push and in-app notifications come with the Notifications feature. This feature sends SMS and email only.
- Withdrawals, which will let a suspended customer take out credited money.
- A bank or provider feed, if Dahab ever gets one, would feed this table as `origin = feed` (not designed now).

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| Final-state trigger + per-status CHECKs on `topup` | "Credited once" and "final is final" must hold under races and for any code path (FR-017, FR-025) | PHP-only checks have no backstop |
| Typed receiving-account columns with per-method CHECK | Wrong routing details send customers' money to the wrong place | Free-form JSON detail rows cannot be validated |
| Receiving accounts page outside the design | The product owner asked for Dashboard management; the design has no such page | Seed-only accounts would need a deploy to change bank details |
| Upload purpose gate inside `StoreUploadRequest` | `/me/uploads` must stay open for unverified identity uploads | A separate receipt upload route would duplicate storage and token handling |
