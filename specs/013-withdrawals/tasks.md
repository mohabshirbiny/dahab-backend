# Tasks: Withdrawals and payout accounts (money out)

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/withdrawals-api.md](./contracts/withdrawals-api.md), [quickstart.md](./quickstart.md)

**Tests**: required (Constitution V; spec FR-022). Work test-first within each story: write the test, watch it fail for the expected reason, implement, then run it.

**Database rules**:
- Run artisan and the tests as the `dahab` DB user, never `postgres`, passing `DB_DATABASE=… DB_USERNAME=dahab DB_PASSWORD=secret` explicitly.
- **Never** run the suite, `migrate:fresh`, a rollback or a seeder against the primary `dahab` database. Tests: `dahab_wt013`; seeded app: `dahab_wt013_dev`; both owned by `dahab` (as postgres: `CREATE DATABASE dahab_wt013 OWNER dahab;`, `CREATE DATABASE dahab_wt013_dev OWNER dahab;`).
- Run the suite **sequentially** (no `--parallel`).

**Paths**:
- Backend paths are relative to the repo root (worktree `withdrawals-payout-accounts-43d3dd`, branch `feature/withdrawals`).
- Dashboard paths are under `D:\laragon\www\dahab-dashboard\.claude\worktrees\withdrawals` (branch `feature/withdrawals`); its working tree is CRLF — edit with Edit/Write.
- Flutter paths are under `D:\laragon\www\dahab-flutter` (no git).

**Git**: never commit or push unless told. Before each phase, check `git status` and file modification times in all three projects: another session may be writing the same tree.

**Every endpoint task** includes its `#[OA\…]` attributes; its Postman request in `postman/Dahab-Backend.postman_collection.json` (folder "Withdrawals"; body in step with the FormRequest; the `Idempotency-Key` pre-request script on every POST except the public pair; saved-id scripts `payout_account_id`, `withdrawal_id`, `confirmation_id`, `confirmation_token`); and the gate or permission on the route (`CustomerRouteAccess` for customer routes). It is not done without them.

**Line endings**: LF in the backend. Use the editor tools, not text-mode scripts.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (docs first — Constitution III)

- [X] T001 Write `docs/features/withdrawals.md` from `docs/features/_TEMPLATE.md`: impact summary and classification (plan.md), the 19 clarifications in short, the endpoint table (contracts/withdrawals-api.md), permissions and seeds, follow-ups, links to `specs/013-withdrawals/`; add it to `docs/features/README.md`.
- [X] T002 Update `docs/Database schema/04_schema_market.sql` §12 and the same text in `00_schema_full.sql`, each change marked "spec 013", exactly as data-model.md: `payout_account` (`is_in_use` + partial unique + CHECK "NOT is_in_use OR state IN ('active','removing')", refusal columns with CHECK "(state = 'refused') = (refusal_reason IS NOT NULL)", `removal_requested_at`, `removed_at`, the number CHECK `'^(EG[0-9]{27}|[0-9]{8,20})$'`, bank CHECK 2–80); new `payout_account_change` (append-only); `withdrawal_pause.ended_notified_at`; `withdrawal` (`withdrawal_no` identity UNIQUE, `hold_txn_id`/`return_txn_id` UNIQUE, review, hold (CHECK `withdrawal_hold_shape`; CHECK `withdrawal_hold_state`: `held_at IS NULL OR state IN ('under_review','rejected','cancelled')` — the hold record survives a reject or cancel, analysis A1), rejection, bank-record columns with their CHECKs, `cancelled_by_change`, `ended_at`, queue index); new `withdrawal_confirmation` (one open per customer); the deferred `trg_withdrawal_money`; the legal document `payout_account_declaration`; an as-built note (`on_hold_account_change` and `settled` not reached).
- [X] T003 Update `docs/Database schema/01_schema_core.sql` (`payout_account_state` + `refused`), `03_schema_ledger.sql` (the `lt_withdrawal_fk` DEFERRABLE note on `withdrawal_id`; the three `withdrawal` line shapes of research R4), `05_schema_security.sql` (`withdrawal_transition` + `('requested','rejected','reviewer rejected before taking it; funds returned (spec 013)')`; new `payout_account_transition`; guards DH007/DH008; RLS policies for the five tables replacing the "planned" comment), `02_schema_identity.sql` (the `payout_account` acceptance context note), and `00_schema_full.sql`.
- [X] T004 Amend the Technical Spec, each change "Changed by spec 013" with a link: Part 1 §2.4 (confirm-then-submit, 30 min, public confirm page), §4.2/§4.3 (codes `withdrawal.release`, `payout_account.verify` and seeds), §5.1 (the five tables), §9 (`email_confirmation_required` as built, `confirmation_invalid`); Part 2 §8 (paths, bodies, gates, several accounts / in use / remove / keep, `confirmation_id`), §9 (queue, review, hold, release with the bank record, reject, export, verify/refuse, no pause re-check at release), §11 (the pause job notifies), §12 (new codes); Part 3 §11 (cancel on change, one account in use, refused), §12 (the job).
- [X] T005 [P] Update `docs/platform/api-contract.md`: surfaces (the public `/withdrawal-confirmations/*` pair), authorization (the two codes), money (`pending_withdrawals`/`held_on_orders`, `WD-{n}` references), idempotency list, error codes and `details`, rate limiters `customer.payout_accounts`, `customer.withdrawals`, `public.withdrawal_confirmations`.
- [X] T006 [P] Create `config/dahab-withdrawals.php` (`confirmation_ttl_minutes` 30, `confirm_url` = env `CUSTOMER_APP_URL` (default `http://localhost:8765`) + `/#/withdraw-confirm`, `recent_change_days` 30, `export_cap` 50000) and add `CUSTOMER_APP_URL` to `.env.example`.
- [X] T007 Create the isolated databases (`dahab_wt013`, `dahab_wt013_dev`, owner `dahab`) and confirm the current suite is green on `dahab_wt013` before any change.

---

## Phase 2: Foundational (blocks every story)

- [X] T008 Write `tests/Feature/Withdrawal/WithdrawalSchemaTest.php` (fails first): the five tables exist with forced RLS and their policies; `refused` in the enum; the payout guard refuses a move outside `payout_account_transition` (SQLSTATE DH008) and changes to customer/bank/holder/number; the withdrawal guard refuses illegal moves (DH007), changes to customer/account/amount/number, re-setting a txn id, and DELETE; `trg_withdrawal_money` refuses a commit of `requested` without `hold_txn_id`, `released` without `release_txn_id`, `cancelled` without `return_txn_id`; `payout_account_change` is append-only; the CHECKs of data-model.md (incl. a held withdrawal may become `rejected` or `cancelled` with its hold columns kept, but `released` with `held_at` set is refused); the `lt_withdrawal_fk` FK; one open confirmation per customer; one in-use account per customer.
- [X] T009 Write the migration `database/migrations/2026_10_06_000010_create_withdrawals.php` from the updated schema docs (T002–T003): enum value, tables, columns, CHECKs, indexes, transition tables, guards (DH007, DH008), the deferred money check (DEFERRABLE INITIALLY DEFERRED, reading in its own scope), the ledger FK, forced RLS + policies `dahab_rls_elevated() OR customer_id = dahab_current_customer_id()`, the legal document `payout_account_declaration` v1 (EN/AR text from `docs/dahab-terms-draft.docx` "When adding a payout account"); `down()` refuses while any `withdrawal` row exists, otherwise drops in reverse (the enum value stays; docblock says so). Run T008 green.
- [X] T010 [P] Map SQLSTATE DH007 → `illegal_withdrawal_transition` and DH008 → `illegal_payout_account_transition` in `bootstrap/app.php`; add to `app/Exceptions/DomainApiException.php`: `illegalWithdrawalTransition()` 409, `illegalPayoutAccountTransition()` 409, `emailConfirmationRequired()` 403, `confirmationInvalid()` 422, `declarationRequired()` 422, `withdrawalsPaused(pauseUntil)` 409 with `details.pause_until`, `payoutAccountNotActive()` 409, `withdrawalOnHold()` 409, and `insufficientFunds` with `details { available, shortfall }` for withdrawals.
- [X] T011 [P] Enums in `app/Enums/`: `PayoutAccountState` (`pending_review`, `active`, `refused`, `removing`, `removed` + `canMoveTo`), `WithdrawalState` (all seven + `canMoveTo` mirroring `withdrawal_transition`, `isOpen()` = requested|under_review), `PayoutRefusalReason` (`name_mismatch`, `name_shortened`, `not_in_customer_name`, `details_invalid`, `other`, with EN/AR customer labels), `WithdrawalHoldReason` (`name_mismatch`, `account_changed_recently`, `identity_pending`, `money_in_straight_out`, `other`), `WithdrawalRejectReason` (`account_not_in_name`, `money_in_straight_out`, `identity_unconfirmed`, `customer_request`, `other`, EN/AR labels), `PayoutAccountChangeKind` (8 values), `PayoutEvent` (R11 list).
- [X] T012 [P] `app/Enums/StaffPermission.php` + `withdrawal.release` ("Release a withdrawal", group Money) and `payout_account.verify` ("Verify a payout bank account", group Customers); seed `withdrawal.release` to `ceo`, `finance` and `payout_account.verify` to `ceo`, `finance`, `verification` in the role seeder, never `coo`; `PermissionCatalogueTest` stays green.
- [X] T013 [P] `app/Enums/AuditEvent.php` + the 17 events of data-model.md with labels and categories (`payout_account.*` → accounts, `withdrawal.*` → money).
- [X] T014 [P] Models `app/Models/{PayoutAccount,PayoutAccountChange,WithdrawalPause,Withdrawal,WithdrawalConfirmation}.php` (UUID keys, casts to the enums and `decimal:4` strings, relations, `Withdrawal::number()` → `WD-{n}`, `PayoutAccount::masked()` → `•••• ` + last 4, `WithdrawalConfirmation::state()` derived); `Customer` relations; `LegalDocument::PAYOUT_ACCOUNT_DECLARATION`; factories `database/factories/{PayoutAccountFactory,WithdrawalFactory}.php` (factories never post the ledger — tests reach money states through the Actions).
- [X] T015 [P] `app/Support/Withdrawals/Iban.php` (strip spaces, upper-case, `EG` + 27 digits, ISO 13616 mod-97 = 1 with bcmath) and `AccountNumber.php` (8–20 digits); `tests/Unit/Withdrawals/IbanTest.php` (valid/invalid checksums, spacing, lower case, wrong country).
- [X] T016 `app/Actions/Withdrawals/Concerns/WithdrawalLedger.php`: `hold(Withdrawal|ids, amount, actor)`, `release(…)`, `returnToAvailable(…)` building the R4 lines with `Account::forCustomerKind` and the `bank` singleton, memo `Withdrawal WD-{n} · …`, `withdrawalId`, posted through `PostLedgerEntryAction`; translate the money service's `insufficient_funds` to the `{available, shortfall}` details.
- [X] T017 `app/Actions/Withdrawals/Concerns/MovesWithdrawal.php` (the only code changing `withdrawal.state`: checks `canMoveTo`, sets `ended_at`/`released_at`/`reviewed_by`, keeps the hold columns on reject/cancel (A1), writes the audit row, calls `FinishesRemoval`; `Withdrawal::isOnHold()` = `under_review` and `held_at` set) and `app/Actions/Payouts/Concerns/{ChangesPayoutAccounts,FinishesRemoval}.php` (account moves + `payout_account_change` rows; the R6 lock order: the customer's payout accounts `ORDER BY payout_account_id FOR UPDATE`, then the customer's open withdrawals `ORDER BY withdrawal_id FOR UPDATE`; paths that start from a withdrawal id read its `customer_id` unlocked, lock that customer's accounts, then lock the withdrawal and re-check its state (research R6, analysis I3); a `removing` account keeps its in-use flag until removed, and `makeInUse()` of another account clears it (R6); `makeInUse()` with the cancel-and-pause rule of FR-005 reading `app(\App\Support\Pricing\Settings::class)->integer(SettingKey::WITHDRAWAL_ACCOUNT_CHANGE_PAUSE_HOURS)` live, none when 0, no pause the first time the customer ever has an account in use).
- [X] T018 [P] `app/Notifications/PayoutNotification.php` (SMS + mail, queued, after commit through the existing `NotifyCustomerJob` path, EN/AR, every `PayoutEvent`) and `WithdrawalConfirmationNotification.php` (mail only: the link, amount, masked account, "valid for 30 minutes"); never a staff note, never a full account number in SMS.
- [X] T019 [P] Rate limiters `customer.payout_accounts` (5/min), `customer.withdrawals` (10/min), `public.withdrawal_confirmations` (10/min per IP) where the existing limiters are defined; `tests/Support/Withdrawals.php` helpers (a verified customer with money via `CreditTopUpByHandAction`, an account verified and in use, a confirmed confirmation, a submitted withdrawal — all through the Actions/HTTP).

**Checkpoint**: schema test green; the suite still green.

---

## Phase 3: User Story 1 — The customer adds a payout account (P1) 🎯 MVP

**Goal**: add an account under review with the declaration. **Independent test**: US1 Independent Test in spec.md.

- [X] T020 [P] [US1] `tests/Feature/Withdrawal/AddPayoutAccountTest.php`: 201 `pending_review`, not in use, acceptance row (context `payout_account`, current declaration), `payout_account_change(added)` with the customer actor, audit `payout_account.added`, `account_added` notification (SMS + mail); no pause, no cancellation; 422 for missing tick (`declaration_required`), stale declaration id, bank `bank_name` outside 2–80, holder outside 3–120, an IBAN with a bad checksum, 7 or 21 digits; spaces stripped; 403 `verification_required` (unverified), 403 `account_suspended`; idempotent replay; throttle.
- [X] T021 [P] [US1] `tests/Feature/Withdrawal/PayoutAccountListTest.php`: `GET /customer/me/payout-accounts` shows own accounts (masked `•••• 4417`, `kind`), `pause`, `recent_changes` newest first (last 20, removed accounts only there), `can` flags; a suspended customer may read; another customer's accounts never appear.
- [X] T022 [US1] `app/Actions/Payouts/Customer/AddPayoutAccountAction.php` (one transaction: account, acceptance, history row, audit; notify after commit) and `ListOwnPayoutAccountsAction.php`; `app/Http/Requests/Customer/Payout/AddPayoutAccountRequest.php` (rules of research R14, `declaration_id` must be the current `payout_account_declaration`); `app/Http/Resources/Customer/PayoutAccountResource.php`; `app/Http/Controllers/Api/V1/Customer/PayoutAccountController.php` (`index` gate verified, `store` gate trade + `throttle:customer.payout_accounts` + `idempotent`) + `#[OA]`; routes in `routes/api.php` (`api.v1.customer.me.payout-accounts.*`); Postman. Run T020–T021 green.

---

## Phase 4: User Story 2 — Staff verify or refuse a payout account (P1)

**Goal**: the name check. **Independent test**: US2 Independent Test.

- [X] T023 [P] [US2] `tests/Feature/Withdrawal/VerifyPayoutAccountTest.php`: list `GET /dashboard/payout-accounts` (default `pending_review`, oldest first, full number, the customer's `full_name`, keyset, `q`); verify → `active`, `name_checked_by/at`, `activated_at`, in use when none in use (no pause first time); verifying when the customer once had an account in use and has none now → in use **and** pause; audit, `account_verified` notification; 409 `illegal_payout_account_transition` on a non-pending account; 403 + audit for COO, Operations and IGI; Verification and Finance allowed; idempotent.
- [X] T024 [P] [US2] `tests/Feature/Withdrawal/RefusePayoutAccountTest.php`: refuse with each reason + note (3–1000) → `refused`, reason/note stored, `payout_account_change(refused)`, audit, `account_refused` notification with the reason and never the note; the customer's list shows `refusal_reason` and never the note; 422 bad reason/missing note; 409 wrong state.
- [X] T025 [US2] `app/Actions/Payouts/Staff/{ListPayoutAccountsForReviewAction,VerifyPayoutAccountAction,RefusePayoutAccountAction}.php` (verify uses `ChangesPayoutAccounts::makeInUse` when none is in use); `app/Http/Requests/Dashboard/Payout/{ListPayoutAccountsRequest,RefusePayoutAccountRequest}.php`; `app/Http/Resources/Staff/StaffPayoutAccountResource.php`; `app/Http/Controllers/Api/V1/Dashboard/PayoutAccountController.php` (`staff.permission:payout_account.verify`, POSTs `idempotent`) + `#[OA]`; routes; Postman. Run T023–T024 green.

---

## Phase 5: User Story 3 — Change the account in use, remove, keep (P1)

**Goal**: the change that cancels and pauses. **Independent test**: US3 Independent Test.

- [X] T026 [P] [US3] `tests/Feature/Withdrawal/UsePayoutAccountTest.php`: *use* a verified account → in use, the previous not; every `requested`/`under_review` withdrawal `cancelled` with `cancelled_by_change`, held −X / available +X (`return_txn_id`), a pause until now + 48 h (read from settings: change the setting to 12 → 12 h; to 0 → no pause), `payout_account_change(in_use)` linked to the pause, audit `payout_account.in_use_changed` with the cancelled numbers, `account_in_use` + `withdrawals_cancelled_by_change` notifications; *use* another account while the one in use is `removing` → its open withdrawals are cancelled by the change and it becomes `removed` in the same transaction, the new one in use (analysis I4); *use* on pending/refused/removing → 409; suspended → 403 `account_suspended`; idempotent.
- [X] T027 [P] [US3] `tests/Feature/Withdrawal/RemovePayoutAccountTest.php`: cancel a request (`pending_review → removed`, `request_cancelled`); remove an active account with no open withdrawal → `removed` (+ in use cleared); with an open withdrawal to it → `removing`, a new confirmation/submit to it → `payout_account_not_active`; cancelling / rejecting / releasing that withdrawal removes it in the same transaction (`removed`, history actor = the one ending it); *keep* → `active`, no pause; suspended → 403.
- [X] T028 [US3] `app/Actions/Payouts/Customer/{UsePayoutAccountAction,RemovePayoutAccountAction,KeepPayoutAccountAction}.php` on `ChangesPayoutAccounts`/`FinishesRemoval`; controller actions `use`, `remove`, `keep` (gate trade, `idempotent`) + `#[OA]`; routes; Postman. Run T026–T027 green (T027's withdrawal cases need later tasks — finish its cancel case after T034 and its reject/release cases after T042).

---

## Phase 6: User Story 4 (+ US5 cancel) — The customer withdraws with the email second-check, and may cancel (P1)

**Goal**: confirm then submit; the hold. **Independent test**: US4 Independent Test.

- [X] T029 [P] [US4] `tests/Feature/Withdrawal/WithdrawalConfirmationTest.php`: request → 201 `sent`, expires in 30 min, mail-only notification with the link (`config('dahab-withdrawals.confirm_url')?token=…`), only the HMAC stored; a second request replaces the first (`replaced`); gates checked first (pause 409 with `details.pause_until`, no account in use / `removing` 409 `payout_account_not_active`, amount > available 409 `insufficient_funds` with `details`); 422 for 0, 3 decimals, negative; public `read` has no side effect and shows only amount, masked account, state, expiry; `confirm` → `confirmed`, audited `withdrawal.email_confirmed` (actor = the customer); confirming twice answers the same; unknown/expired (`travelTo` +31 min)/replaced/used → 422 `confirmation_invalid`; the public pair works with no token and is throttled; `GET /customer/me/withdrawals/confirmations/{id}` shows the state and 404s for another customer.
- [X] T030 [P] [US4] `tests/Feature/Withdrawal/SubmitWithdrawalTest.php`: with a confirmed confirmation → 201 `requested`, `WD-{n}`, one `withdrawal` ledger entry available −X / held +X tied to it, `hold_txn_id`, confirmation `used`, audit `withdrawal.requested`; `GET /customer/me/wallet` shows available −X and `pending_withdrawals` +X, `held_on_orders` unchanged; history row `reference` `WD-{n}`; refusals: no/unconfirmed/expired/used/other customer's confirmation, amount or account mismatch → 403 `email_confirmation_required`; pause opened after the confirmation → 409; account removed/refused meanwhile → 409; funds spent meanwhile → 409 `insufficient_funds`; a suspended verified customer may submit; unverified → 403; idempotent replay holds once.
- [X] T031 [P] [US4] `tests/Feature/Withdrawal/CustomerWithdrawalsReadTest.php`: list (keyset, `state=open|closed`), detail; never reviewer, staff notes or bank transaction number; `hold_message` shown while held; 404 for another customer's.
- [X] T032 [US4] `app/Actions/Withdrawals/Customer/{RequestWithdrawalConfirmationAction,ShowWithdrawalConfirmationAction,SubmitWithdrawalAction,ListOwnWithdrawalsAction}.php`, `app/Actions/Withdrawals/Public/{ReadWithdrawalConfirmationAction,ConfirmWithdrawalAction}.php` (bootstrap elevation, lookup by HMAC), `app/Support/Withdrawals/ConfirmationTokens.php`; Requests `Customer/Withdrawal/{RequestConfirmationRequest,SubmitWithdrawalRequest,ListWithdrawalsRequest}.php`, `Public/ConfirmationTokenRequest.php`; Resources `Customer/{WithdrawalResource,WithdrawalConfirmationResource}.php`; controllers `Customer/WithdrawalController.php` (gate verified; POSTs `throttle:customer.withdrawals` + `idempotent`) and `Public/WithdrawalConfirmationController.php` (`/api/v1/withdrawal-confirmations/read|confirm`, `db.elevate:bootstrap`, `throttle:public.withdrawal_confirmations`) + `#[OA]`; routes; Postman (the token saved from the log mail by hand, documented in `postman/README.md`).

**Cancel (US5, moved here so the removal cases of T027 and the reconciliation paths have it early — analysis I2):**

- [X] T033 [P] [US5] `tests/Feature/Withdrawal/CancelWithdrawalTest.php`: cancel from `requested`, `under_review`, held → `cancelled`, held → available, audit `withdrawal.cancelled`, no notification; 409 after release/reject/cancel; 404 for another customer; a suspended customer may cancel; finishes a `removing` account.
- [X] T034 [US5] `app/Actions/Withdrawals/Customer/CancelWithdrawalAction.php`; controller action `cancel` (gate verified, `idempotent`) + `#[OA]`; route; Postman. Run T033 green.

- [X] T035 [US4] The wallet split (FR-017): `ShowCustomerWalletAction` adds `pending_withdrawals` (sum of `requested`/`under_review` amounts) and `held_on_orders`; `ShowLedgerOverviewAction` adds both system-wide; `ListCustomerWalletHistoryAction` gives `withdrawal` rows `reference` `WD-{n}`; the customer and staff wallet `#[OA]` schemas; `tests/Feature/Withdrawal/WalletSplitTest.php` (customer wallet, staff customer wallet, overview, history reference). Run T029–T031 and T035 green; finish T027's cancel case.

---

## Phase 7: User Story 6 — Finance reviews, holds, releases or rejects (P1)

**Goal**: the queue and the release to the bank. **Independent test**: US6 Independent Test.

- [X] T036 [P] [US6] `tests/Feature/Withdrawal/StaffWithdrawalsListTest.php`: `GET /dashboard/withdrawals` default `requested,under_review`, oldest first, keyset; filters `state`, `held=1`, `from`/`to` (Cairo days), `q` (display ref, E.164 phone, name), `customer_id`; `meta.figures` (waiting count/sum, released today count/sum, on hold count, average hours to release over 30 days); every signal of research R8 on crafted fixtures; full number for `withdrawal.release`, masked for `wallet.view`-only; `can` flags; detail with `history[]` and `ledger[]`; query count constant in page size (no N+1).
- [X] T037 [P] [US6] `tests/Feature/Withdrawal/WithdrawalReviewTest.php`: review `requested → under_review` (reviewer, `review_started_at`, audit); hold (reason from the list, message 3–500, note 3–1000) only on `under_review`, `withdrawal_held` notification with the message; unhold clears, audit; 409 wrong states.
- [X] T038 [P] [US6] `tests/Feature/Withdrawal/ReleaseWithdrawalTest.php`: release with `bank_txn_number` (3–64), optional `transfer_reference` (≤ 64), `value_date` (not future, not before request) → `released`, one entry held −X / bank +X, `release_txn_id`, `released_at`, `reviewed_by`, bank fields; overview bank cash −X; `withdrawal_released` notification; audit `withdrawal.released`; 409 `withdrawal_on_hold` while held; 409 `illegal_withdrawal_transition` from `requested`/`released`/`cancelled`; release to a `removing` account (its last open withdrawal) succeeds and moves the account to `removed` (no longer in use) in the same transaction (analysis I1); 409 `payout_account_not_active` if the account is neither `active` nor `removing`, or not the customer's; idempotent.
- [X] T039 [P] [US6] `tests/Feature/Withdrawal/RejectWithdrawalTest.php`: reject from `requested` and `under_review` (held or not) with reason + note → `rejected`, held → available, `return_txn_id`, audit, `withdrawal_rejected` with the reason only; 409 after release.
- [X] T040 [P] [US6] `tests/Feature/Withdrawal/WithdrawalExportTest.php`: CSV (BOM, headers, filtered rows, masked per permission), `X-Export-Truncated` over the cap (config lowered in test), audit `withdrawal.list_exported`; `wallet.view`-only → 403.
- [X] T041 [P] [US6] `tests/Feature/Withdrawal/WithdrawalPermissionsTest.php`: the COO (and Operations, Verification, IGI) get 403 + `auth.staff.permission_denied` audit on every withdrawal action and the export; `wallet.view` reads (masked numbers, no `can` actions) but cannot act or export; Verification can verify accounts but not see the queue.
- [X] T042 [US6] `app/Support/Withdrawals/{WithdrawalCursor,WithdrawalSignals,WithdrawalFigures}.php`; `app/Actions/Withdrawals/Staff/{ListWithdrawalsAction,ShowWithdrawalAction,ExportWithdrawalsAction,TakeForReviewAction,HoldWithdrawalAction,UnholdWithdrawalAction,ReleaseWithdrawalAction,RejectWithdrawalAction}.php` (lock order R6/R7); Requests `Dashboard/Withdrawal/{ListWithdrawalsRequest,HoldWithdrawalRequest,ReleaseWithdrawalRequest,RejectWithdrawalRequest}.php`; `app/Http/Resources/Staff/StaffWithdrawalResource.php`; `app/Http/Controllers/Api/V1/Dashboard/WithdrawalController.php` (reads `staff.permission:withdrawal.release|wallet.view`; export and POSTs `withdrawal.release`; POSTs `idempotent`) + `#[OA]`; routes; Postman. Run T036–T041 green; finish T027's reject/release cases.

---

## Phase 8: User Story 9 — The Customer App runs on the API (P1)

**Goal**: the customer's screens live. **Independent test**: spec US9.

- [X] T043 [US9] Models `lib/models/payout_account.dart` (`PayoutAccount`, `PayoutAccountChange`, `PayoutAccountsView` with `pause`), `lib/models/withdrawal.dart` (`Withdrawal`, `WithdrawalConfirmation`); wallet model + `pendingWithdrawals`, `heldOnOrders`; the `withdrawal` history kind and `WD-` reference in `lib/models/wallet_labels.dart`.
- [X] T044 [US9] `lib/services/api/payout_accounts_api.dart`, `lib/services/api/withdrawals_api.dart` (Idempotency-Key per user action, the public read/confirm without a token); `lib/services/repositories.dart` interfaces; `lib/services/account_controller.dart` on the API (replaces the local `banks`/`bankLog`/`pendingWithdrawal` mocks); remove the bank mocks from `lib/services/mock_repositories.dart` and `lib/mock/mock_account.dart`; wire in `lib/services/app_session.dart`.
- [X] T045 [US9] `lib/features/account/bank_screens.dart`: Bank accounts (accounts with *In use* / *Under review* / *Refused — reason* / *Removing* tags, *Use this one* with a confirm sheet stating the pause and that un-left withdrawals are cancelled, *Remove* / *Cancel this request* / *Keep it after all*, *Recent changes*, the pause banner "Withdrawals paused until …", "No payout account yet"); Add a bank account (bank, holder, IBAN/number with client-side shape check only, the declaration text from `/reference/legal-documents/payout_account_declaration`, field errors from the API). `lib/features/account/account_screen.dart`: Your details → Payout account from the API (in-use account or "Not added yet" / "Under review").
- [X] T046 [US9] `lib/features/wallet/wallet_screens.dart` Withdraw: available, amount + picks (*All of it*), *Goes to* the account in use (or a prompt to add/verify one), the email row (*Send the link* → *Waiting* with polling every 5 s while visible → *Confirmed*, *Send the link again*), *Withdraw* submits; the phone-code row removed; errors (`withdrawals_paused` with the date, `payout_account_not_active`, `insufficient_funds` with the figures, `email_confirmation_required`, `account_suspended`); the wallet shows "On its way to your bank" (pending withdrawals) beside held on open orders, and the customer's withdrawals with *Cancel* while open. New `lib/features/wallet/withdraw_confirm_screen.dart` at `#/withdraw-confirm?token=` (public, no sign-in: amount, masked account, *Confirm*, expired/used states) in `lib/routing/{routes,app_router}.dart`.
- [X] T047 [US9] EN/AR strings for every new label, state, reason and error in `lib/core/i18n/*`; the fake backend in `test/flows_test.dart` / `test/test_app.dart` serving the new endpoints with the real shapes; flow tests: add account, use account (pause shown), withdraw with confirm, cancel; `flutter analyze`, `flutter test`, `flutter build web --release` green.

---

## Phase 9: Dashboard — Withdrawals page and verification (serves US2, US6)

- [X] T048 [US6] `src/api/endpoints.ts`, `src/types/withdrawal.ts`, `src/types/payoutAccount.ts`, `src/types/staff.ts` (+ `withdrawal.release`, `payout_account.verify`), `src/services/withdrawal.service.ts`, `src/services/payout-account.service.ts` (snake → camel, money strings kept as strings), `src/services/errors.ts` (the 8 codes; `details` passed through).
- [X] T049 [US6] `src/pages/withdrawals/index.vue` + `src/components/withdrawals/{WithdrawalFigures,WithdrawalsTable,WithdrawalDetailDrawer,ReviewModal,HoldModal,UnholdModal,ReleaseModal,RejectModal}.vue`: figures, filters (state chips, held, date range, search), the queue with amount "of available", account + name check, the signals as chips, export button (`withdrawal.release`); each action a `DModal` with `useIdempotencyKey`, shown per `can` and permission; Release: "Send the transfer at the bank first, then record its details here", bank transaction number, transfer reference prefilled `WD-{n}`, value date.
- [X] T050 [US2] Tab *Payout accounts to check* `src/components/withdrawals/{PayoutAccountsToCheck,VerifyPayoutModal,RefusePayoutModal}.vue` (holder name beside the verified ID name, full number, oldest first; refuse reasons + note) gated on `payout_account.verify`; `src/mock/nav.ts` unhide `withdrawals` for `withdrawal.release|wallet.view|payout_account.verify` (badge from `meta.figures.waiting_count` when allowed); `src/router/index.ts` real page with the permission guard.
- [X] T051 Run `npm run type-check`, `npm run lint`, `npm run build` green in the dashboard worktree.

---

## Phase 10: User Story 8 — Payout accounts and pauses in the Customer file (P2)

- [X] T052 [P] [US8] `tests/Feature/Withdrawal/CustomerFilePayoutTest.php`: `GET /dashboard/customers/{id}` (customer.view) has `payout_accounts[]` (masked unless `payout_account.verify`/`withdrawal.release`, refusal reason, in use, checked by/at) and `withdrawal_pause`; the staff wallet has the split.
- [X] T053 [US8] `app/Support/CustomerFileLoader.php` + `app/Http/Resources/Staff/CustomerFileResource.php` (+`#[OA]`); run T052 green.
- [X] T054 [US8] Dashboard: `src/types/customer.ts`, `src/services/customer.service.ts`; `src/components/customer-file/PayoutAccountsPanel.vue` (accounts, pause, Verify/Refuse modals reused for `payout_account.verify`, the customer's withdrawals from `GET /dashboard/withdrawals?customer_id=` for `withdrawal.release|wallet.view`) in `src/pages/customers/[id].vue`; wallet panel and Overview card show pending withdrawals (`src/types/wallet.ts`, `src/services/{wallet,overview}.service.ts`); type-check, lint, build green.

---

## Phase 11: User Story 7 — The pause ends and the customer is told (P3)

- [X] T055 [P] [US7] `tests/Feature/Withdrawal/PauseSweepTest.php`: run `php artisan withdrawals:sweep` (via `$this->artisan`): ended pause → `ended_notified_at`, `withdrawals_open` notification, audit `withdrawal.pause_ended` (system actor + `rls.system_elevation`); a superseded pause stamped without a message; not-yet-ended pause untouched; second run changes nothing; a request after the end succeeds through the API.
- [X] T056 [US7] `app/Actions/Withdrawals/AnnounceEndedPausesAction.php`, `app/Console/Commands/SweepWithdrawals.php` (`withdrawals:sweep`, system actor, one pause per transaction, 1,000 per run), `routes/console.php` `->everyMinute()->withoutOverlapping()`. Run T055 green.

---

## Phase 12: Polish & cross-cutting

- [X] T057 [P] `tests/Feature/Withdrawal/WithdrawalIsolationTest.php` and `WithdrawalLeakTest.php`: in the customer scope only own rows of the five tables are visible and writable; no scope sees nothing; customer responses never contain another customer's data, a staff note, the reviewer, the bank transaction number or a full number of anything but their own accounts; staff `wallet.view`-only responses mask numbers; `CustomerTableIsolationTest`, `CustomerRouteGateTest` and `ElevationTest` cover the new tables and routes.
- [X] T058 [P] `tests/Feature/Withdrawal/WithdrawalNotificationTest.php`: each `PayoutEvent` to the right customer, EN/AR, SMS + mail, after commit only (a rolled-back action sends nothing), never a note or a full number in SMS.
- [X] T059 `tests/Feature/Withdrawal/WithdrawalConcurrencyTest.php` (spec 012 technique: committed fixtures, connections `pgsql` + `pgsql_b`, `lock_timeout`, then truncate): release vs customer cancel; release vs *use* another account; two submits over the balance (each with its own confirmation); verify vs remove of the same account; reject vs cancel — exactly one wins, money moves once, the loser gets `illegal_*_transition` or `insufficient_funds`.
- [X] T060 `tests/Feature/Withdrawal/WithdrawalReconciliationTest.php`: drive every path (each state, by customer, staff and change), then for every withdrawal: exactly one hold equal to the amount; `requested|under_review` nothing else; `released` exactly one release (held −X, bank +X) and no return; `rejected|cancelled` exactly one return and no release; the customer's held equals `held_on_orders + pending_withdrawals`; `ledger_global_zero` = 0.
- [X] T061 `database/seeders/LocalWithdrawalSeeder.php` (local only, after `LocalOrderSeeder` in `DatabaseSeeder`) per research R16 through the real Actions; print the seeded customers; run on `dahab_wt013_dev` with `migrate:fresh --seed`.
- [X] T062 [P] Opt-in performance test `tests/Feature/Performance/WithdrawalListPerformanceTest.php` (10,000 withdrawals: list < 300 ms; 1,000 ended pauses swept < 60 s), skipped unless `RUN_PERF=1`.
- [X] T063 `composer swagger:generate` (no warnings for the new schemas); Postman collection validated (every new route present, bodies match the Requests); `./vendor/bin/pint`; full suite on `dahab_wt013`, sequential, green; `migrate:rollback` of the new migration on an empty `dahab_wt013` works and refuses with a withdrawal row.
- [X] T064 Update `CLAUDE.md` "Current state" (Backend, Dashboard, Flutter lines for spec 013), `docs/platform/architecture.md` (withdrawals module, the public confirm pair), the memory roadmap note if asked, and the "As built" notes in this file; mark tasks done.
- [ ] T065 Manual walk of [quickstart.md](./quickstart.md) with the three apps on `dahab_wt013_dev` (EN and AR), noting anything off in the report.
- [X] T066 Final report in the Step 5 shape (CLAUDE.md), listing exactly what is still mock in each app.

---

## Dependencies & execution order

- Phase 1 → Phase 2 → stories. US1 → US2 → US3 → US4 + US5 (Phase 6; US5 moved here, analysis I2) → US6 → US9 (app) and Phase 9 (Dashboard) → US8 → US7 → Polish.
- T027 (removal) is finished in steps: its cancel case after T034, its reject/release cases after T042. US7 (job) is independent once Phase 2 and US3 exist.
- Backend API before each consumer: Phase 8 (Flutter) needs US1, US3, US4, US5 endpoints; Phase 9 (Dashboard) needs US2 and US6 endpoints; T054 needs T053.

## Parallel examples

- Phase 2: T010, T011, T012, T013, T014, T015, T018, T019 touch different files.
- US4/US5: T029, T030, T031, T033 (tests) together; then T032, T034, T035.
- US6: T036–T041 (tests) together; then T042.
- Phase 8 (Flutter) and Phase 9 (Dashboard) run in parallel once the backend for US1–US6 is green.

## Implementation strategy

1. **MVP**: Phases 1–7 (an account added, verified, in use; a withdrawal confirmed by email, submitted, cancelled by the customer, released or rejected by Finance; the money moves exactly once) — fully testable through the API and Postman.
2. Then the apps (Phases 8–9), then the Customer file, the pause job, and the polish (isolation, leaks, concurrency, reconciliation, seeder, docs).

## As built (2026-10-03)

All tasks are done except **T065** (the manual walk of quickstart.md in the three apps was not done; the flows are covered by the Pest, Flutter widget and Dashboard type/build checks instead). Where the code differs from the task text:

- **Email link route**: the Customer App routes are flat ids, so the link is `#/withdraw-confirm?token=…` (not `#/withdraw/confirm`); `config/dahab-withdrawals.php` `confirm_url` and every doc say so.
- **Backend tests** are grouped by flow rather than one file per task: `PayoutAccountsTest` (T020, T021, T023, T024, T026, T027), `WithdrawalFlowTest` (T029–T031, T033, T035), `StaffWithdrawalsTest` (T036–T041), `WithdrawalSchemaTest` (T008 + the per-table isolation of T057), `WithdrawalLeakTest` (the leaks of T057 and the notifications of T058), `PauseSweepTest` (T055), `CustomerFilePayoutTest` (T052), `WithdrawalConcurrencyTest` (T059), `WithdrawalReconciliationTest` (T060), `tests/Unit/Withdrawals/IbanTest` (T015).
- **Support classes**: the ledger moves are `app/Support/Withdrawals/WithdrawalLedger.php`; the state moves, locks, `makeInUse` and the notification outbox live in one concern `app/Actions/Withdrawals/Concerns/WorksWithdrawals.php` (instead of `MovesWithdrawal` / `ChangesPayoutAccounts` / `FinishesRemoval`); the list query and keyset are `WithdrawalListQuery` and `KeysetPage`; the account-number check is inside `Iban.php` (`isAcceptable`).
- **Performance (T062)**: `tests/Feature/Performance/WithdrawalPerformanceTest.php` in the `perf` group like specs 008–012 (`php vendor/bin/pest --group=perf`, `PERF_WITHDRAWALS` overrides the 10,000), not a `RUN_PERF` switch. The click on the email link is stood in for (the token is never stored).
- **Dashboard (T048–T050, T054)**: one `src/types/withdrawal.ts` (accounts included) and one `src/services/withdrawal.service.ts`; one `WithdrawalActionModal.vue` (a `DModal` for review / hold / unhold / release / reject) and one `PayoutAccountModal.vue` (verify / refuse) instead of a file per action; `WithdrawalDetailPanel.vue` instead of a drawer; the figures are `StatCard`s on the page.
- **Customer App (T043–T047)**: models in `lib/models/payout.dart`; one `lib/services/api/payout_api.dart` (`ApiPayoutRepository`, interface `PayoutRepository`) and a `PayoutController` (`lib/services/payout_controller.dart`); `AccountController` keeps only the notification preferences; the withdraw steps of the ledger history are told apart by sign (`withdrawal_hold`, `withdrawal`, `withdrawal_return` labels); the old AppSession "email confirmed" mock flags were removed.
