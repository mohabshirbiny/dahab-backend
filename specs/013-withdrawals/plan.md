# Implementation Plan: Withdrawals and payout accounts (money out)

**Branch**: `feature/withdrawals` (backend from `main` 0ec619c, dashboard from `main` 221afe1; the Customer App has no repository) | **Date**: 2026-10-03 | **Spec**: [spec.md](./spec.md)

## Summary

- **Payout accounts**: a verified customer adds bank accounts (Egyptian IBAN or account number, the declaration tick) that start `pending_review`. Staff with `payout_account.verify` verify them against the verified ID name or refuse them (a new final state `refused`, with a reason). Exactly one active account is *in use*. Making a different account the one in use — not the first time — cancels every un-released withdrawal (money back at once) and opens a `withdrawal_pause` for `withdrawal.account_change_pause_hours`; the customer is told on phone and email. Accounts can be removed (`removing` while a withdrawal still goes to them) or kept.
- **Withdrawals**: the email second-check is a 30-minute, single-use confirmation tied to the amount and the account, confirmed from a Customer App page opened from the email (public read/confirm calls). Submitting holds the amount (`withdrawal`: available → held). The customer may cancel before release.
- **Staff**: the Withdrawals queue (`withdrawal.release`, CEO + Finance, never the COO): figures, filters, signals, take for review, hold/unhold with a customer message, release after the bank transfer with its details (held → bank), reject with a reason (held → available), CSV export. A *Payout accounts to check* tab and Verify / Refuse in the Customer file.
- **Guarantees in the data**: guards `DH007` (withdrawal) and `DH008` (payout account) on transition tables, a deferred money check per withdrawal state, unique txn ids per withdrawal, forced RLS on five tables, append-only account history.
- **Job**: `withdrawals:sweep` tells a customer once when their pause has ended.
- **Apps**: the Dashboard Withdrawals page, the Customer file panel and the split held figure; the Customer App bank screens, Withdraw with the email step, the confirm page and the wallet split, on the live API.

## Impact analysis

```
Backend:          YES — 1 migration; ~16 Actions (Payouts/Customer, Payouts/Staff, Withdrawals/Customer, Withdrawals/Staff, Withdrawals/Public, sweep), 2 concerns, 1 command, 2 notifications, ~10 Requests, ~6 Resources, 5 controllers, 8 error codes, seeder, factories, tests, Postman
Database:         YES — 3 schema tables (+columns), 2 new tables (payout_account_change, withdrawal_confirmation), 1 enum value, 1 new + 1 extended transition table, DH007/DH008 guards, deferred money check, ledger FK, forced RLS ×5, 1 legal document
API:              YES — 11 customer endpoints, 2 public, 10 dashboard (new); changed (additive): wallet, customer wallet, overview, customer file, wallet history reference
Dashboard:        YES — Withdrawals page (+ Payout accounts to check tab), Customer file panel, wallet panel + Overview split; types/services/endpoints/permissions/errors
Customer App:     YES — Bank accounts, Add a bank account, Your details → Payout account, Withdraw + email step, confirm page, wallet split; models/APIs/controller; EN/AR; fake backend
Auth:             YES (small) — one public route pair (bootstrap elevation, as the auth routes); no change to sign-in, tokens or gates
Permissions:      YES — 2 new codes: withdrawal.release (CEO, Finance), payout_account.verify (CEO, Finance, Verification)
API models/types: YES — Dashboard src/types/{withdrawal,payoutAccount}.ts, wallet.ts, customer.ts, staff.ts; Flutter lib/models/{payout_account,withdrawal}.dart, wallet models
```

**Classification**: the new endpoints are **non-breaking**; the wallet/overview/customer-file fields are additive (**non-breaking**). **Potentially breaking**: the wallet history now has `withdrawal` rows with a `WD-{n}` reference (clients map kinds — both apps checked in tasks); the permission union and the audit event list grow; the `payout_account_state` enum gains `refused`. Nothing is renamed or removed.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary dependencies**:
  - **Backend**: `PostLedgerEntryAction`, `LedgerEntry`/`LedgerLine`, `Account::forCustomerKind` (spec 008); `Settings` (spec 005); `RecordAuditLogAction`; the `idempotent` middleware; `DatabaseActor`, `SystemActor`; `LegalDocument` + `agreement_acceptance` (spec 010); `CreditTopUpByHandAction` (seeder); `OrderNotification` / `NotifyCustomerJob` as the notification pattern; `ExportTopUpsAction` as the export pattern; the spec 007 customer search.
  - **Dashboard**: TanStack Vue Query, `useIdempotencyKey`, `usePermissions`, `DModal`.
  - **Flutter**: `ApiClient`, `WalletApi`, the reference API (legal documents), go_router (hash URLs).
- **Storage**: PostgreSQL 16 (tables, guards, deferred constraint trigger, forced RLS); Redis (queues, idempotency, rate limits).
- **Testing**: Pest feature tests through HTTP on `dahab_wt013`, sequential, as `dahab` — every endpoint and refusal; schema tests (guards, CHECKs, deferred check, RLS per scope); the job by command; concurrency on two connections with committed fixtures then truncate (spec 012 technique); reconciliation over every withdrawal; leak tests (masked numbers, no other customer, no staff notes to customers); the build tests.
- **Performance goals**: submit / release < 300 ms p95; `GET /dashboard/withdrawals` < 300 ms with 10,000 withdrawals (signals in one query per page); the sweep handles 1,000 ended pauses per minute.
- **Constraints**: bcmath; a named actor on every change and entry; one transaction per operation with the R6/R7 lock order (payout accounts → withdrawals → ledger accounts; paths starting from a withdrawal read its customer unlocked, lock the accounts, lock the withdrawal, re-check); a release goes to an `active` or `removing` account of the customer; the pause hours always from settings; the full account number only to its owner and to holders of `payout_account.verify` / `withdrawal.release`.
- **Scale/scope**: 23 endpoints, 1 command, 1 Dashboard page with 2 tabs + Customer file panel, 5 Customer App screens.

No open NEEDS CLARIFICATION: 19 clarifications in the spec; engineering choices R1–R17 in [research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Every account change writes `payout_account_change` with exactly one actor; every withdrawal move is audited with its actor; every ledger entry names the customer (hold, cancel/return), the staff member (release, reject) or the customer for a change-triggered return; the public confirm is attributed to the confirmation's customer; the sweep runs as the system actor. | ✅ |
| II. Isolation by the engine; staff authz by data | Forced RLS on `payout_account`, `payout_account_change`, `withdrawal_pause`, `withdrawal`, `withdrawal_confirmation` (owner = customer). Every customer action touches only the caller's rows (no cross-customer scope). The public confirm uses the existing `bootstrap` elevation, reads one row by token hash and is audited. Staff use 2 new catalogue codes; never role names. | ✅ |
| III. Docs first | Updated before the migration: `04_schema_market.sql` §12 (columns, new tables, guards, money check, RLS), `01_schema_core.sql` (`refused`), `03_schema_ledger.sql` (the FK, the three shapes), `05_schema_security.sql` (transitions, DH007/DH008, RLS, the new tables), `02_schema_identity.sql` (the declaration), `00_schema_full.sql`. Technical Spec Part 1 §2.4, §4.2, §4.3, §5.1, §9; Part 2 §8, §9, §11, §12; Part 3 §11, §12 ("Changed by spec 013"); `api-contract.md`; `docs/features/withdrawals.md`; CLAUDE.md "Current state". | ✅ |
| IV. Foundation before modules | The migration mirrors the updated schema; every endpoint has a Request, Resource, Action, Pest tests (happy path and refusals) and `#[OA]`. | ✅ |
| V. Test the boundary and the ledger | Every movement of money is asserted through HTTP (wallets, the overview's bank cash, the withdrawal's ledger); the sweep by command and observed through the API; the reconciliation test over every withdrawal. | ✅ |
| Reversible migrations | `down()` refuses once any withdrawal exists (append-only ledger ties); otherwise drops in reverse (the `refused` enum value stays — PostgreSQL cannot drop it; documented). | ✅ (documented) |

No Constitution deviation.

**Recorded deviations from the schema / Technical Spec** (fixed in `docs/` in the same change; **approved in full by the product owner on 2026-10-03**, analysis D1):

- Paths under `/customer/me/*`, `/dashboard/*` and a public `/withdrawal-confirmations/*` pair (R1).
- `payout_account_state` gains `refused`; `payout_account` gains `is_in_use`, refusal, removal columns (Clarifications).
- New tables `payout_account_change` (history / *Recent changes*) and `withdrawal_confirmation` (the email second-check, instead of `one_time_token` — R5).
- `withdrawal` gains `withdrawal_no` (`WD-{n}`, the `TOP-{n}` precedent), `hold_txn_id`, `return_txn_id`, review, hold, rejection, bank-record columns, `cancelled_by_change`, `ended_at`.
- `withdrawal_transition` gains `requested → rejected`; `on_hold_account_change` and `settled` are not reached (Clarifications: cancel on change; released is final).
- `withdrawal_pause.ended_notified_at`; the pause-expiry job notifies instead of moving `on_hold_account_change → under_review` (Clarification).
- The release does not re-check the pause (R6: every un-released withdrawal is cancelled at the change).
- `POST /me/withdrawals` takes `confirmation_id` instead of `email_confirmation_token` (R5).
- New error codes `confirmation_invalid`, `declaration_required`, `illegal_payout_account_transition`, `withdrawal_on_hold`; `insufficient_funds` / `withdrawals_paused` carry `details`.
- Wallet figures gain `pending_withdrawals` / `held_on_orders`.

## Project Structure

### Documentation

```text
specs/013-withdrawals/{spec,plan,research,data-model,quickstart}.md, contracts/withdrawals-api.md, checklists/requirements.md, tasks.md (next)
docs/features/withdrawals.md
```

### Source Code

```text
backend
  docs/Database schema/{00,01,02,03,04,05}_*.sql · docs/Technical Spec/part{1,2,3}.md · docs/platform/api-contract.md · docs/features/{withdrawals.md,README.md} · CLAUDE.md
  database/migrations/2026_10_06_000010_create_withdrawals.php
  database/seeders/LocalWithdrawalSeeder.php (+ DatabaseSeeder, local only) · database/factories/{PayoutAccountFactory,WithdrawalFactory}.php · PermissionSeeder/role seeds (+2)
  config/dahab-withdrawals.php · .env.example (CUSTOMER_APP_URL)
  app/Enums/{PayoutAccountState,WithdrawalState,PayoutRefusalReason,WithdrawalHoldReason,WithdrawalRejectReason,PayoutAccountChangeKind,PayoutEvent}.php · StaffPermission (+2) · AuditEvent (+17)
  app/Models/{PayoutAccount,PayoutAccountChange,WithdrawalPause,Withdrawal,WithdrawalConfirmation}.php · Customer (+relations) · LegalDocument (+PAYOUT_ACCOUNT_DECLARATION)
  app/Support/Withdrawals/{Iban,AccountNumber,WithdrawalCursor,WithdrawalSignals,WithdrawalFigures,ConfirmationTokens}.php
  app/Actions/Payouts/Concerns/{ChangesPayoutAccounts,FinishesRemoval}.php
  app/Actions/Payouts/Customer/{ListOwnPayoutAccountsAction,AddPayoutAccountAction,UsePayoutAccountAction,RemovePayoutAccountAction,KeepPayoutAccountAction}.php
  app/Actions/Payouts/Staff/{ListPayoutAccountsForReviewAction,VerifyPayoutAccountAction,RefusePayoutAccountAction}.php
  app/Actions/Withdrawals/Concerns/{MovesWithdrawal,WithdrawalLedger}.php
  app/Actions/Withdrawals/Customer/{RequestWithdrawalConfirmationAction,ShowWithdrawalConfirmationAction,SubmitWithdrawalAction,CancelWithdrawalAction,ListOwnWithdrawalsAction}.php
  app/Actions/Withdrawals/Public/{ReadWithdrawalConfirmationAction,ConfirmWithdrawalAction}.php
  app/Actions/Withdrawals/Staff/{ListWithdrawalsAction,ShowWithdrawalAction,ExportWithdrawalsAction,TakeForReviewAction,HoldWithdrawalAction,UnholdWithdrawalAction,ReleaseWithdrawalAction,RejectWithdrawalAction}.php
  app/Actions/Withdrawals/AnnounceEndedPausesAction.php · Console/Commands/SweepWithdrawals.php · routes/console.php (every minute)
  app/Actions/Wallet/{ShowCustomerWalletAction,ShowLedgerOverviewAction,ListCustomerWalletHistoryAction} (+pending_withdrawals, WD-{n}) · app/Support/CustomerFileLoader + Staff/CustomerFileResource (+payout_accounts, withdrawal_pause)
  app/Notifications/{PayoutNotification,WithdrawalConfirmationNotification}.php
  app/Exceptions/DomainApiException.php (+8) · bootstrap/app.php (DH007, DH008)
  app/Http/Requests/Customer/Payout/{AddPayoutAccountRequest}.php · Customer/Withdrawal/{RequestConfirmationRequest,SubmitWithdrawalRequest,ListWithdrawalsRequest}.php · Public/ConfirmationTokenRequest.php · Dashboard/Withdrawal/{ListWithdrawalsRequest,HoldWithdrawalRequest,ReleaseWithdrawalRequest,RejectWithdrawalRequest}.php · Dashboard/Payout/{ListPayoutAccountsRequest,RefusePayoutAccountRequest}.php
  app/Http/Resources/Customer/{PayoutAccountResource,WithdrawalResource,WithdrawalConfirmationResource}.php · Staff/{StaffWithdrawalResource,StaffPayoutAccountResource}.php
  app/Http/Controllers/Api/V1/Customer/{PayoutAccountController,WithdrawalController}.php · Public/WithdrawalConfirmationController.php · Dashboard/{WithdrawalController,PayoutAccountController}.php · routes/api.php · CustomerRouteAccess (gates) · AppServiceProvider (rate limiters)
  postman/Dahab-Backend.postman_collection.json (folder "Withdrawals": customer, public, dashboard)
  tests/Feature/Withdrawal/{WithdrawalSchemaTest,PayoutAccountGuardTest,AddPayoutAccountTest,PayoutAccountListTest,VerifyPayoutAccountTest,RefusePayoutAccountTest,UsePayoutAccountTest,RemovePayoutAccountTest,WithdrawalConfirmationTest,SubmitWithdrawalTest,CancelWithdrawalTest,StaffWithdrawalsListTest,WithdrawalReviewTest,ReleaseWithdrawalTest,RejectWithdrawalTest,WithdrawalExportTest,PauseSweepTest,WalletSplitTest,CustomerFilePayoutTest,WithdrawalIsolationTest,WithdrawalLeakTest,WithdrawalPermissionsTest,WithdrawalNotificationTest,WithdrawalConcurrencyTest,WithdrawalReconciliationTest}.php
  tests/Unit/Withdrawals/IbanTest.php · tests/Support/Withdrawals.php
dashboard
  src/api/endpoints.ts · src/types/{withdrawal,payoutAccount}.ts · types/{wallet,customer,staff}.ts · services/{withdrawal,payout-account}.service.ts · services/{wallet,customer,overview}.service.ts · services/errors.ts · mock/nav.ts · router/index.ts
  src/pages/withdrawals/index.vue
  src/components/withdrawals/{WithdrawalFigures,WithdrawalsTable,WithdrawalDetailDrawer,ReviewModal,HoldModal,UnholdModal,ReleaseModal,RejectModal,PayoutAccountsToCheck,VerifyPayoutModal,RefusePayoutModal}.vue · components/customer-file/PayoutAccountsPanel.vue · wallet panel / Overview card (pending withdrawals)
flutter (no git)
  lib/models/{payout_account,withdrawal}.dart · models/account.dart (BankAccount → API) · wallet models (+pending_withdrawals)
  lib/services/api/{payout_accounts_api,withdrawals_api}.dart · services/account_controller.dart (API) · repositories.dart · mock_repositories.dart (bank mocks removed) · app_session.dart
  lib/features/account/{bank_screens,account_screen}.dart · features/wallet/wallet_screens.dart (Withdraw, pending, rows) · features/wallet/withdraw_confirm_screen.dart (public) · routing/{routes,app_router}.dart
  lib/core/i18n (strings, errors, en/ar) · test/{test_app,flows_test}.dart (fake payout/withdrawal endpoints)
```

**Structure decision**: the existing layering. Every withdrawal state change goes through `MovesWithdrawal`; every account change through `ChangesPayoutAccounts` (history row + guard); money only through `WithdrawalLedger` → `PostLedgerEntryAction`.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R17)
- [data-model.md](./data-model.md)
- [contracts/withdrawals-api.md](./contracts/withdrawals-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no Constitution violation; the schema deviations above are recorded and land in `docs/` in the same change.

## Follow-ups (not in this feature)

- `released → settled` (confirming arrival) and a bounced transfer (money back to the bank and the wallet).
- The design's *Transfer file* (a bank batch file).
- "Stop everything" (also stops withdrawals), cap/pattern alerts (OI-3.4), open-question §5 (when manual review of every withdrawal stops scaling).
- Email change (the second-check address), daily close and bank movements.
- Signals that need missing modules: open disputes, "weight short".

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `withdrawal_confirmation` table | Single-use, amount/account-bound, confirm-then-submit second check (Part 1 §2.4, Clarification) | `one_time_token` has no confirmed state, amount or account |
| Public read/confirm calls | The email link is opened on any device, possibly signed out | A signed-in confirm defeats the "independent of the session" rule |
| `payout_account_change` table | *Recent changes* and a named actor for every account change, incl. "in use" which is not a state | Reading the audit log for business data; customers cannot read it |
| `is_in_use` + `refused` | Several accounts, one in use; a refusal is not a removal (Clarifications) | Reusing `removed` hides why an account cannot be used |
| `hold_txn_id` / `return_txn_id` + deferred money check | Each withdrawal's money happens exactly once on every path | An Action-only rule has no backstop |
| DH007 / DH008 guards | Withdrawal and account guard errors must be distinguishable from orders' DH006 | Sharing a code answers the wrong error |
