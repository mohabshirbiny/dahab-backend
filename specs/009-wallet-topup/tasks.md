# Tasks: Wallet Top-up

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/topup-api.md](./contracts/topup-api.md), [quickstart.md](./quickstart.md)

**Tests**: required (Constitution V). Work test-first within each story: write the test, see it fail for the expected reason, implement, run it. Run artisan and tests as the `dahab` DB user.

**Paths**:
- Backend paths are relative to the repo root.
- Dashboard paths are under `../dahab-dashboard/`.
- Flutter paths are under `../dahab-flutter/`, which has no git.

**Git**: branch `feature/wallet-topup` in backend and dashboard, created only when the user asks. Before starting, check `git status` in `../dahab-dashboard`: an uncommitted `src/components/ui/DModal.vue` change from another session was present on 2026-09-29, so don't touch or commit it without asking.

## Format: `[ID] [P?] [Story] Description`

## Phase 1: Setup (docs first — Constitution III)

- [X] T001 Write `docs/features/wallet-topup.md` from `docs/features/_TEMPLATE.md`, with the impact analysis and classification from plan.md, the five clarifications, and links to `specs/009-wallet-topup/`.
- [X] T002 Add a "Top-ups" section to `docs/Database schema/03_schema_ledger.sql` and the matching part of `00_schema_full.sql`, exactly as data-model.md:
  - the four types `topup_method ('bank_transfer','instapay','vodafone_cash')`, `topup_origin ('notice','by_hand')`, `topup_status ('pending','on_hold','credited','rejected','cancelled')`, `topup_reject_reason ('money_not_received','duplicate_notice','sender_not_accepted','other')`;
  - table `receiving_account`, with its per-method details CHECK, the no-delete trigger and the `(method, is_active, sort_order)` index;
  - table `topup` (including `arrival_reference TEXT` ≤ 100 chars, `credit_note` ≤ 1000 chars), with the eight CHECKs (`topup_amounts`, `topup_origin_shape`, `topup_credited_shape`, `topup_rejected_shape`, `topup_hold_shape`, `topup_cancelled_shape`, `topup_difference_explained`, `topup_receipt_pair`), `ledger_txn_id UUID UNIQUE REFERENCES ledger_transaction`, the triggers `trg_topup_guard` (SQLSTATE `DH003`) and `trg_topup_no_delete`, and the two list indexes.
- [X] T003 [P] Add `topup` to the RLS list in `docs/Database schema/05_schema_security.sql` (and `00_schema_full.sql`): FORCE RLS, policy `topup_isolation` (elevated OR own customer). Note that `receiving_account` is reference data with no RLS.
- [X] T004 [P] Amend the Technical Spec, marking each change "Changed by spec 009" with a link:
  - Part 1 §4.2: codes `topup.match` beside "Match an incoming transfer", and a new row "Manage Dahab's receiving accounts" (CEO, Finance) → `topup.accounts.manage`;
  - Part 2 §8: replace `POST /me/wallet/topup` with the four as-built customer endpoints (gates, idempotency, receipt purpose), and drop the "payment gateway" sentence (manual transfer only);
  - Part 2 §9: replace `POST /admin/transfers/match` with the as-built dashboard endpoints and the receiving-account endpoints, and state the **suspended-customer credit rule** explicitly:
    - verified and active → match and credit by hand allowed;
    - verified and suspended (from active) → match and credit by hand allowed only as staff-side reconciliation of money that has already arrived, with `arrival_reference` (the provider's transaction reference) required and the customer's status recorded in the audit row; same idempotency, ledger and audit rules;
    - awaiting verification, rejected, or suspended from those → credit by hand refused with `verification_required`;
    - the customer side is unchanged: a suspended customer cannot read receiving details, upload a receipt or submit a notice, but may list and cancel their own pending notices;
  - Part 3 §11: replace "Money in only from an account in their own name" with "staff decide on the evidence (reference, receipt showing the customer's name, phone); no automatic sender-name check — product-owner decision, spec 009".
- [X] T005 [P] Update `docs/platform/api-contract.md`: the error code `illegal_topup_transition` (409), the upload purpose `topup_receipt` (images + PDF), and `reference` = `TOP-{n}` for top-up rows in wallet history and statements.

---

## Phase 2: Foundational (blocks every story)

- [X] T006 Write `tests/Feature/TopUp/TopUpSchemaTest.php` (real Postgres, maintenance scope). Each CHECK in data-model.md refuses its bad shape, including:
  - a by-hand row that isn't `credited`;
  - a `credited` row missing `ledger_txn_id`;
  - a `credited_amount ≠ claimed_amount` without `credit_note`;
  - an amount with 3 decimals;
  - `receipt_ref` without `receipt_mime`.

  It also checks that:
  - a second `topup` with the same `ledger_txn_id` violates the unique constraint;
  - updating a `credited`, `rejected` or `cancelled` row raises `DH003`;
  - changing `claimed_amount` or `customer_id` raises `DH003`;
  - DELETE raises `DH003`;
  - `receiving_account` refuses a `bank_transfer` without `account_number`, a `vodafone_cash` with `wallet_number` `'12345'`, and any DELETE.
- [X] T007 Write the migration `database/migrations/2026_10_02_000010_create_topups.php` mirroring T002/T003 verbatim: types, both tables, CHECKs, triggers, indexes, and `ENABLE` + `FORCE` RLS with the `topup_isolation` policy (reuse `dahab_rls_elevated()` and `dahab_current_customer_id()`). `down()` drops everything in reverse; the docblock notes that the ledger entries survive a rollback. Run `migrate` and `migrate:rollback` as `dahab`; T006 passes.
- [X] T008 [P] Create the enums `app/Enums/TopUpMethod.php`, `TopUpOrigin.php`, `TopUpStatus.php` (with `isFinal()` and `canMoveTo(self)` encoding the data-model state machine) and `TopUpRejectReason.php`, each with `label()` (English) and `labelAr()`.
- [X] T009 [P] Create the models `app/Models/ReceivingAccount.php` (casts, `scopeActive`, `detailRows(): list<array{key,value}>` ordered per method as the contract says) and `app/Models/TopUp.php` (table `topup`, UUID key, enum and decimal casts, `number()` → `TOP-{topup_no}`, relations `customer`, `noticeAccount`, `receivingAccount`, `creditedBy`, `heldBy`, `rejectedBy`, `ledgerTransaction`). Add `topUps()` to `app/Models/Customer.php`.
- [X] T010 [P] Create the factories `database/factories/ReceivingAccountFactory.php` (states `bankTransfer`, `instapay`, `vodafoneCash`, `inactive`) and `TopUpFactory.php` (states `pending`, `onHold`, `rejected`, `cancelled`, `withReceipt`; `credited` builds its ledger entry through `PostLedgerEntryAction` inside a transaction).
- [X] T011 [P] Create `database/seeders/ReceivingAccountSeeder.php` with one fake account per method (`Test Bank` / `Dahab Test` / `0000 0000 0000`, `dahab.test@instapay`, `01000000000`), `updated_by` = the system actor; call it from `DatabaseSeeder` (local/testing only).
- [X] T012 Add `TOPUP_MATCH = 'topup.match'` ("Match an incoming transfer") and `TOPUP_ACCOUNTS_MANAGE = 'topup.accounts.manage'` ("Manage Dahab's receiving accounts") to `app/Enums/StaffPermission.php`: group `Money`; `seedRoles()` = `[SeedRole::FINANCE->value]`, never COO (keep the wallet comment). Check how spec 008 inserted `wallet.view` into existing installs and do the same for both codes (catalogue sync + first-seed grant).
- [X] T013 Add the audit events to `app/Enums/AuditEvent.php`, each with a label and category `MONEY`: `TOPUP_MATCHED = 'topup.matched'`, `TOPUP_CREDITED_BY_HAND = 'topup.credited_by_hand'`, `TOPUP_HELD = 'topup.held'`, `TOPUP_UNHELD = 'topup.unheld'`, `TOPUP_REJECTED = 'topup.rejected'`, `TOPUP_LIST_EXPORTED = 'topup.list_exported'`, `RECEIVING_ACCOUNT_CREATED = 'receiving_account.created'`, `RECEIVING_ACCOUNT_UPDATED = 'receiving_account.updated'`. Extend the Dashboard audit presenter so the new entity types (`topup`, `receiving_account`) render.
- [X] T014 Add `DomainApiException::illegalTopUpTransition()` (`illegal_topup_transition`, 409) in `app/Exceptions/DomainApiException.php`, and map SQLSTATE `DH003` to it in `bootstrap/app.php`, next to `DH001`.
- [X] T015 [P] Create `app/Support/TopUpReference.php`:
  - `for(Customer): string` → `'DAHAB-'.display_ref`;
  - `normalise(string $term): string` → upper-cases, strips spaces and dashes, and strips a leading `DAHAB`.

  Unit test: `tests/Unit/TopUpReferenceTest.php`.

**Checkpoint**: migrations, models, factories, permissions and events exist; the schema test is green.

---

## Phase 3: User Story 1 — A customer tells Dahab they have sent money (P1) 🎯 MVP

**Goal**: a verified, non-suspended customer sees the receiving accounts and their reference, uploads an optional receipt, submits a notice, lists their notices and cancels a pending one. No money moves.

**Independent test**: the US1 scenarios in spec.md; quickstart steps 1–3 and the cancel in step 7.

### Tests (write first)

- [X] T016 [P] [US1] `tests/Feature/TopUp/TopUpMethodsTest.php`:
  - an active customer gets `reference` `DAHAB-<display_ref>` and the active accounts grouped by method in `sort_order`, with `details` rows ordered per contract;
  - an inactive account is hidden, and so is a method with no active account;
  - pending and rejected customers get 403 `verification_required`, a suspended customer 403 `account_suspended`, and an unauthenticated request 401.
- [X] T017 [P] [US1] `tests/Feature/TopUp/SubmitTopUpNoticeTest.php`:
  - happy path with and without a receipt (201, row `pending`, `origin notice`, `reference` stored, `notice_account_id`, `method` taken from the account, `receipt_mime` set);
  - the wallet balance and the ledger row count are unchanged;
  - idempotent replay (same key → same body and `Idempotent-Replayed: true`, one row) and a missing key → 400;
  - validation: amount `0`, `-5`, `10.123`, `100000000`, and a missing, unknown or inactive account are all 422;
  - receipt token: someone else's, an `identity`-purpose token and a reused token are all 422 `upload_token_invalid`;
  - gate refusals for pending, rejected and suspended customers;
  - the throttle returns 429 after the limit.
- [X] T018 [P] [US1] `tests/Feature/TopUp/TopUpReceiptUploadTest.php`: `purpose=topup_receipt` accepts JPEG, PNG, WebP and PDF and refuses others (422). It is refused for pending (403 `verification_required`) and suspended (403 `account_suspended`) customers. `purpose=identity` stays open to pending customers (regression). The stored object is encrypted under `topup-receipts/{customer}/`.
- [X] T019 [P] [US1] `tests/Feature/TopUp/CustomerTopUpListTest.php`: own notices newest first with the contract fields (`number`, `can_cancel`, `reject_reason` only when rejected; `hold_note`, `reject_note`, `credit_note`, `arrival_reference` and any receiving-account details never present — FR-018a); `status` filter; keyset pagination. A suspended customer can list (gate `verified`) and the response still has no receiving-account details; a pending customer gets 403.
- [X] T020 [P] [US1] `tests/Feature/TopUp/CancelTopUpNoticeTest.php`: cancelling a pending notice → 200 `cancelled`, `cancelled_at` set, no ledger change; idempotent replay; cancelling a notice that is `on_hold`, `credited`, `rejected` or already `cancelled` → 409 `illegal_topup_transition`; another customer's notice → 404; a **suspended** customer can cancel their own pending notice (200), while the same customer gets 403 `account_suspended` on `topup-methods`, the `topup_receipt` upload and `POST …/topups` (L2).
- [X] T021 [P] [US1] `tests/Feature/TopUp/TopUpIsolationTest.php`: in the customer DB scope, customer A cannot select or update B's `topup` rows (RLS, raw query), and B's notice id in A's cancel URL → 404.

### Implementation

- [X] T022 [US1] Widen `app/Services/IdentityDocumentStorage.php`:
  - add `storeAt(string $prefix, string $customerId, UploadedFile $file): string` (same encryption, key `{prefix}/{customer}/{uuid}.enc`);
  - reword the docblock to "private customer uploads".

  Add `UploadPurpose::TOPUP_RECEIPT = 'topup_receipt'` in `app/Enums/UploadPurpose.php`. In `app/Actions/Identity/CreateCustomerUploadAction.php`, store `topup_receipt` files under `topup-receipts`.
- [X] T023 [US1] Extract the status check of `app/Http/Middleware/EnsureCustomerStanding.php` into a public static `assert(Customer $customer, string $level): void`, used by `handle()` with unchanged behaviour. In `app/Http/Requests/Identity/StoreUploadRequest.php`:
  - apply `assert(…, 'trade')` when `purpose = topup_receipt` (in `withValidator`/`after`, so the 403 envelope is the gate's);
  - use purpose-specific mimes (identity: `dahab-identity.allowed_mimes`; receipt: those plus `pdf`);
  - update its `#[OA]` enum.

  Record the mime with the token in `app/Services/UploadTokenStore.php` (or detect it on consume).
- [X] T024 [US1] Create `config/dahab-wallet.php` with `'topups_per_minute' => env('DAHAB_TOPUPS_PER_MINUTE', 10)`, and register `RateLimiter::for('customer.topups', …)` in `app/Providers/AppServiceProvider.php`, keyed by the customer id. Add the key to `.env.example`.
- [X] T025 [P] [US1] `app/Actions/TopUp/ListTopUpMethodsAction.php` returns `{reference, methods: [{method, accounts}]}`, using active accounts by `sort_order` (R5).
- [X] T026 [US1] `app/Actions/TopUp/SubmitTopUpNoticeAction.php` runs `handle(Customer, string $amount, int $accountId, ?string $receiptToken)` in one transaction:
  1. lock the active account `FOR SHARE`;
  2. resolve and forget the `topup_receipt` token (`uploadTokenInvalid` on failure);
  3. insert the `pending` row with `reference = TopUpReference::for($customer)`, `method` from the account and `claimed_amount` normalised with bcmath to 2 decimals.

  No audit (Part 2 §8).
- [X] T027 [P] [US1] `app/Actions/TopUp/ListCustomerTopUpsAction.php`: keyset on `topup_no DESC` (it rises with submission time), optional status filter; relies on RLS plus an explicit `customer_id` predicate.
- [X] T028 [US1] `app/Actions/TopUp/CancelTopUpNoticeAction.php`: in one transaction, lock the row `FOR UPDATE` (404 if not own/none), require `canMoveTo(CANCELLED)` (otherwise `illegalTopUpTransition`), then set `cancelled` and `cancelled_at`.
- [X] T029 [US1] Requests `app/Http/Requests/Customer/TopUp/{SubmitTopUpNoticeRequest,ListCustomerTopUpsRequest}.php`, with the rules from the contract:
  - `amount`: `required`, `numeric`, `gt:0`, `decimal:0,2`, `max:99999999.99`;
  - `receiving_account_id`: `required|integer|exists` and active;
  - `receipt_upload_token`: `nullable|string`;
  - `per_page`: 1–50;
  - `status`: enum;
  - `cursor`.

  Resources `app/Http/Resources/Customer/{TopUpResource,ReceivingAccountResource}.php` carry the contract fields, amounts as 4-place strings (`"20000.0000"`, platform money rule), and never a staff note.
- [X] T030 [US1] `app/Http/Controllers/Api/V1/Customer/TopUpController.php` (`methods`, `store`, `index`, `cancel`), with full `#[OA]` attributes: security, gates, idempotency header, error responses. Add the routes to `routes/api.php` under `me/wallet`:
  - `GET topup-methods` and `POST topups` inside `customer.gate:trade`; the POST also takes `throttle:customer.topups` and `idempotent` (last);
  - `GET topups` and `POST topups/{topup}/cancel` (`whereUuid`, `idempotent`) inside the existing `customer.gate:verified` group.

  Check that the customer-route build test (`CustomerRouteAccess`) still passes. T016–T021 pass.

**Checkpoint**: US1 works alone; notices pile up for staff.

---

## Phase 4: User Story 2 — CEO or Finance match a transfer and the wallet is credited (P1)

**Goal**: staff with `topup.match` list, search, export and open notices, view the receipt, match (the credit through the money service), hold, un-hold and reject. The customer gets an SMS/email on credit and on reject.

**Independent test**: the US2 scenarios in spec.md; quickstart steps 4–7. Uses `TopUpFactory` notices, so US1's endpoints aren't needed.

### Tests (write first)

- [X] T031 [P] [US2] `tests/Feature/TopUp/MatchTopUpTest.php`: the first HTTP-boundary posting test (Constitution V; it ends the spec 008 waiver). Finance matches a pending 20,000.00 notice for 20,000.00 → 200 `credited`. It asserts:
  - one `ledger_transaction` of kind `topup` with `staff_id` = Finance and the memo `Top-up TOP-n · InstaPay · matched from notice DAHAB-…`;
  - exactly two postings: bank `-20000.0000` and the customer's `cust_available` `+20000.0000`;
  - `topup.ledger_txn_id` = that transaction;
  - the customer's `GET /customer/me/wallet` `available` +20,000;
  - `GET /dashboard/wallets/overview` bank cash +20,000 and system total `0`;
  - the history row `reference` = `TOP-n`;
  - the audit row `topup.matched` with the before/after status, the amounts and the note as `reason`.

  It also covers:
  - a match from `on_hold`;
  - a different amount without a note → 422, with a note → credited with that amount and the claim kept;
  - an account of another method → 422; an inactive account of the right method → OK;
  - idempotent replay → one ledger entry;
  - a second match with a new key → 409 and the ledger unchanged;
  - matching `rejected` or `cancelled` → 409;
  - a customer suspended after submitting: match without `arrival_reference` → 422 and nothing changes (no ledger entry, notice still pending); with it → credited, audit `after` has `customer_status: suspended` and the reference (M1);
  - an active customer's match accepts `arrival_reference` as optional and stores it;
  - a missing key → 400.
- [X] T032 [P] [US2] `tests/Feature/TopUp/TopUpConcurrencyTest.php` (two DB connections, the pattern of spec 008's `LedgerConcurrencyTest`):
  - two matches on one notice → exactly one ledger entry, the other 409;
  - match vs customer cancel → exactly one wins, and never a cancelled row with a credit;
  - after either race the global posting sum is 0.
- [X] T033 [P] [US2] `tests/Feature/TopUp/HoldRejectTopUpTest.php`:
  - hold with a note → `on_hold`; without a note → 422; unhold → `pending`;
  - reject `money_not_received` with a note → `rejected`; the customer list shows the reason code but no note;
  - illegal moves → 409 (hold `on_hold`, unhold `pending`, reject `credited`, any move on `cancelled`);
  - each action is audited with the right event and requires an idempotency key.
- [X] T034 [P] [US2] `tests/Feature/TopUp/IncomingTransfersListTest.php`:
  - the default filter `pending,on_hold`, the status list, the Cairo `from`/`to` dates, and keyset;
  - `q` matches `DAHAB-004417`, `dahab 004417`, `004417`, the phone and a name fragment;
  - `meta.totals` count and claimed sum;
  - staff fields present (notes, `allowed_actions`);
  - `GET /dashboard/topups/{id}` detail;
  - the CSV export (header row, BOM, filters applied) is audited `topup.list_exported`.
- [X] T035 [P] [US2] `tests/Feature/TopUp/TopUpReceiptTest.php`: the staff receipt stream returns the decrypted bytes with the stored `Content-Type` and `Cache-Control: no-store`; no receipt → 404.
- [X] T036 [P] [US2] `tests/Feature/TopUp/TopUpNotificationTest.php` (`Notification::fake()`):
  - a match sends `TopUpCreditedNotification` with the credited amount;
  - a reject sends `TopUpRejectedNotification` with the reason and never the note;
  - channels are `['mail','sms']` with an email and `['sms']` without one;
  - Arabic and English content by `preferred_lang`;
  - nothing is sent for hold, unhold or cancel, or when the transaction fails (force a failure after posting).
- [X] T037 [P] [US2] `tests/Feature/TopUp/TopUpPermissionTest.php`:
  - on default seeded roles, the COO, Operations and Verification get 403 `permission_denied` on every `/dashboard/topups*` route, and each denial is audited;
  - Finance and the CEO pass;
  - a role given `topup.match` from the Dashboard passes (data, not code);
  - the `/dashboard/permissions` catalogue lists both new codes in group `Money`.

### Implementation

- [X] T038 [P] [US2] Notifications `app/Notifications/TopUpCreditedNotification.php` and `TopUpRejectedNotification.php`: `ShouldQueue`, `$tries = 3`; `via()` returns `sms` plus `mail` when the customer has an email (copy `CustomerVerifiedNotification`); English and Arabic by `preferred_lang`. The credited message gives the amount and `TOP-n`; the rejected message gives the plain reason label and never the note.
- [X] T039 [US2] `app/Actions/TopUp/MatchTopUpAction.php`, per research R8, in one transaction:
  1. lock `topup` `FOR UPDATE`;
  2. `canMoveTo(CREDITED)` (otherwise 409);
  3. the account exists and `method` matches (otherwise a validation error);
  4. require a note when the amount ≠ the claim, and lock the customer row `FOR SHARE` and require `arrival_reference` when `status = suspended` (otherwise validation errors);
  5. build `LedgerEntry(LedgerEventKind::TOPUP, [LedgerLine(bank, -amount), LedgerLine(customer available, +amount)], actorStaffId, memo)` with 4-decimal bcmath strings and memo `Top-up TOP-{n} · {method label} · matched from notice {reference}`, and call `PostLedgerEntryAction`;
  6. update the row;
  7. audit `TOPUP_MATCHED` with `customer_status` (at credit time) and `arrival_reference` in `after`.

  After commit, notify. Find the bank and customer accounts through the same helper spec 008 uses (check `tests/Support/Ledger.php` and the `Account` model; add `Account::bank()` / `Account::availableFor($customerId)` if none exists).
- [X] T040 [P] [US2] `app/Actions/TopUp/{HoldTopUpAction,UnholdTopUpAction,RejectTopUpAction}.php`: each locks the row, checks `canMoveTo`, writes the status columns, and audits (`TOPUP_HELD`, `TOPUP_UNHELD`, `TOPUP_REJECTED` with the reason in `after` and the note as `reason`). Reject notifies after commit.
- [X] T041 [P] [US2] `app/Actions/TopUp/ListTopUpsAction.php`:
  - filters: status list, Cairo date range on `submitted_at`, `q` via `TopUpReference::normalise` against `display_ref`, `phone`, and `full_name ILIKE`;
  - keyset on `topup_no DESC` (it rises with submission time);
  - totals;
  - eager-loads customer, accounts and staff (no N+1).

  Also `ShowTopUpAction.php` and `ReadTopUpReceiptAction.php` (decrypt through `IdentityDocumentStorage::read`, return the bytes and the mime).
- [X] T042 [P] [US2] `app/Actions/TopUp/ExportTopUpsAction.php`: stream CSV (BOM, the contract columns, the same filters, chunked) and audit `TOPUP_LIST_EXPORTED` with the filters and the row count. Follow `ExportWalletStatementAction`.
- [X] T043 [US2] Requests `app/Http/Requests/Dashboard/TopUp/{ListTopUpsRequest,MatchTopUpRequest,HoldTopUpRequest,RejectTopUpRequest}.php`:
  - `amount`: `required|numeric|gt:0|decimal:0,2|max:99999999.99`;
  - `receiving_account_id`: `required|integer|exists:receiving_account`;
  - `note`: `nullable|string|max:1000` on match, `required|string|max:1000` on hold and reject;
  - `arrival_reference`: `nullable|string|max:100` on match (the Action makes it required for a suspended customer);
  - `reason`: `required|enum`;
  - `status`: a comma list of enums;
  - `from`/`to`: dates, with `from ≤ to`;
  - `q`: max 64;
  - `per_page`: 1–50.

  Resource `app/Http/Resources/Staff/TopUpResource.php` carries the staff fields and `allowed_actions` from `TopUpStatus::canMoveTo`.
- [X] T044 [US2] `app/Http/Controllers/Api/V1/Dashboard/TopUpController.php` (`index`, `export`, `show`, `receipt`, `match`, `hold`, `unhold`, `reject`) with full `#[OA]`. Add routes in `routes/api.php` under `dashboard`: `auth:staff`, `abilities:staff:access`, `staff.standing`, `staff.permission:topup.match`; `whereUuid('topup')`; `idempotent` last on the four POSTs; `export` declared before `{topup}`. T031–T037 pass.
- [X] T045 [US2] In `app/Actions/Wallet/ListCustomerWalletHistoryAction.php` and `BuildWalletStatementAction.php`, left-join `topup` on `ledger_txn_id` and fill `reference` with `'TOP-'||topup_no` for top-up rows (research R12); the CSV export picks it up. Extend `tests/Feature/Wallet/CustomerWalletHistoryTest.php` and `WalletStatementTest.php` with one credited top-up asserting `reference`.

**Checkpoint**: money enters the ledger through HTTP; the Principle V waiver is closed.

---

## Phase 5: User Story 3 — Staff credit money that arrived without a notice (P2)

**Goal**: credit by hand to a verified (active or suspended) customer, with a required note. Audited, idempotent, notified.

**Independent test**: the US3 scenarios; quickstart step 8.

- [X] T046 [P] [US3] `tests/Feature/TopUp/CreditTopUpByHandTest.php`. The happy path for an active customer (201, `origin by_hand`, `credited`, `claimed_amount` null, method from the account) asserts the ledger entry, the postings, the balances, the audit row `topup.credited_by_hand` and `TopUpCreditedNotification`. It also covers:
  - the memo `Top-up TOP-n · … · credited by hand` (Wallet statement shows it);
  - a suspended customer whose `status_before_suspension` is `active`: without `arrival_reference` → 422 and nothing written; with it → credited, and the audit `after` has `customer_status: suspended` and the reference;
  - the same suspended customer still gets 403 `account_suspended` on `GET /customer/me/wallet/topup-methods`, the `topup_receipt` upload and `POST /customer/me/wallet/topups` after the credit (FR-018a);
  - pending, rejected, and suspended-from-pending or suspended-from-rejected customers → 403 `verification_required`, with nothing written (no row, no ledger entry, no audit credit row);
  - a missing note → 422; an unknown customer → 422 (`exists` rule, documented in `#[OA]`);
  - an inactive receiving account is accepted (money already arrived there);
  - idempotent replay → one credit;
  - COO → 403.
- [X] T047 [US3] `app/Actions/TopUp/CreditTopUpByHandAction.php`, in one transaction:
  1. lock the customer row;
  2. decide by status: `active` → allowed; `suspended` with `status_before_suspension = active` → allowed only when `arrival_reference` is filled (otherwise a validation error on `arrival_reference`); anything else (`pending_verification`, `rejected`, suspended from those) → `DomainApiException::verificationRequired()`;
  3. insert the `credited` `by_hand` row (with `ledger_txn_id` filled after posting — insert the row after `PostLedgerEntryAction` returns);
  4. audit `TOPUP_CREDITED_BY_HAND` with `customer_status` (at credit time) and `arrival_reference` in `after`; memo `Top-up TOP-{n} · {method label} · credited by hand`.

  After commit, notify. Do not touch any customer-side gate: the suspended allowance lives only in the staff credit Actions (this one and `MatchTopUpAction`). Share the posting and audit code with `MatchTopUpAction` through a small private trait or helper in `app/Actions/TopUp/Concerns/CreditsWallet.php`.
- [X] T048 [US3] `app/Http/Requests/Dashboard/TopUp/CreditTopUpByHandRequest.php`:
  - `customer_id`: `required|uuid|exists:customer,customer_id`;
  - `amount`: as in T043;
  - `receiving_account_id`: `required|integer|exists`;
  - `note`: `required|string|max:1000`;
  - `arrival_reference`: `nullable|string|max:100` (the Action makes it required for a suspended customer).

  Add `store` to the Dashboard `TopUpController` with `#[OA]`, and the route `POST /dashboard/topups` (`idempotent` last). T046 passes.

---

## Phase 6: User Story 4 — CEO or Finance manage Dahab's receiving accounts (P2)

**Goal**: list, create and edit (including deactivate and reorder) the receiving accounts, audited. Customers see only active ones.

**Independent test**: the US4 scenarios; quickstart step 9.

- [X] T049 [P] [US4] `tests/Feature/ReceivingAccount/ReceivingAccountTest.php`:
  - list (both codes pass; `include_inactive`);
  - create per method, including the missing-detail 422s (`bank_transfer` without `account_number`; `wallet_number` not matching `^01[0125][0-9]{8}$`; `iban` not `^EG[0-9]{27}$`; `label` longer than 80; `customer_note` longer than 300; `provider_fee_percent` > 100);
  - PATCH changes details, `sort_order` and `is_active`; sending `method` → 422 (`prohibited`);
  - audit rows `receiving_account.created` and `receiving_account.updated` with before/after;
  - no DELETE route (405);
  - after a deactivation `GET /customer/me/wallet/topup-methods` hides the account;
  - the COO → 403 on create and update; a `topup.match`-only role can list but not edit.
- [X] T050 [P] [US4] `app/Actions/TopUp/{CreateReceivingAccountAction,UpdateReceivingAccountAction}.php`: each runs in a transaction, sets `updated_by`, normalises the details of the other methods to null, and audits with full before/after.
- [X] T051 [US4] Requests `app/Http/Requests/Dashboard/ReceivingAccount/{StoreReceivingAccountRequest,UpdateReceivingAccountRequest}.php`, with per-method `required_if` rules matching the DB CHECK:
  - `label`: `required|string|max:80`;
  - `bank_name`: `max:80`;
  - `account_holder`: `max:120`;
  - `account_number`: `regex:/^[0-9 ]{6,34}$/`;
  - `iban`: `regex:/^EG[0-9]{27}$/`;
  - `instapay_address`: `max:120`;
  - `wallet_number`: `regex:/^01[0125][0-9]{8}$/`;
  - `daily_limit`: `nullable|numeric|gt:0|decimal:0,2`;
  - `provider_fee_percent`: `nullable|numeric|between:0,100|decimal:0,3`;
  - `customer_note`: `nullable|max:300`;
  - `sort_order`: `integer|between:0,999`;
  - `is_active`: `boolean`;
  - on update, `method`: `prohibited`.

  Resource `app/Http/Resources/Staff/ReceivingAccountResource.php`. `app/Http/Controllers/Api/V1/Dashboard/ReceivingAccountController.php` (`index`, `store`, `update`) with `#[OA]`. Routes: `GET` with `staff.permission:topup.match|topup.accounts.manage`, and `POST` / `PATCH {account}` with `staff.permission:topup.accounts.manage`. T049 passes.

---

## Phase 7: Dashboard (`../dahab-dashboard`) — consumers of US2–US4

- [X] T052 [P] Add the endpoints to `src/api/endpoints.ts`. Add `src/types/topup.ts` (TopUp staff view, statuses, reasons, methods, ReceivingAccount, list meta; camelCase mapping as in `src/types/wallet.ts`). Add `topup.match` and `topup.accounts.manage` to the permission union in `src/types/staff.ts` and the `PERMISSIONS` constants.
- [X] T053 [P] `src/services/topup.service.ts` (list, export download, show, receipt blob URL, match, hold, unhold, reject, creditByHand, receiving accounts list/create/update; POSTs send `Idempotency-Key` from `useIdempotencyKey`, as `customer.service.ts` does). Also `src/composables/useTopUps.ts` and `src/composables/useReceivingAccounts.ts` (TanStack Vue Query; invalidate the list, detail, wallet and statement queries after a credit).
- [X] T054 Page `src/pages/transfers/index.vue`, following the design's "Incoming transfers":
  - lead text;
  - date and status filters, search, *Export to Excel*;
  - table columns: received, amount, method/customer, reference, receipt indicator, status chip;
  - the header shows the count and sum.

  Components under `src/components/transfers/`:
  - `TransferFilters.vue`, `TransferTable.vue`;
  - `TransferDrawer.vue` (details, receipt preview via `ReceiptPreview.vue` for image or PDF, a link to the Wallet statement for that customer);
  - `MatchForm.vue` (amount prefilled with the claim, receiving account of the notice's method, note required when the amount differs, and a "provider transaction reference" field that becomes required, with an explanation, when the customer is suspended);
  - `HoldForm.vue`, `RejectForm.vue` (reason select + note);
  - `CreditByHandForm.vue` (customer search by reference or phone through the existing customer search, amount, account including inactive ones, note, and a "provider transaction reference" field that becomes required, with an explanation, when the chosen customer is suspended; pending or rejected customers are shown as not creditable).

  Map error codes to messages (`illegal_topup_transition`: "Someone else already closed this transfer — the list is refreshed"). Include loading, empty and error states.
- [X] T055 Page `src/pages/receiving-accounts/index.vue`, with `src/components/receiving-accounts/{AccountTable,AccountForm}.vue`: fields per method, active toggle, sort order, and "shown to customers" preview text.
- [X] T056 Router `src/router/index.ts`: replace the `transfers` Placeholder with the page (`permissions: [PERMISSIONS.topupMatch]`) and add `dashboard/receiving-accounts` (`topup.match|topup.accounts.manage`; edit controls only with manage). In `src/mock/nav.ts`, unhide *Incoming transfers* with its permission and drop the mock badge (or feed it from `meta.totals.count`), and add *Receiving accounts* under Controls.
- [X] T057 Check that the Wallet statement page shows the new `reference` (`TOP-n`) for top-up rows. Update `../dahab-dashboard/CLAUDE.md` or its docs "live sections" list. Run `npm run type-check`, `npm run lint` and `npm run build`.

---

## Phase 8: Customer App (`../dahab-flutter`) — consumer of US1

- [X] T058 [P] In `lib/services/api/api_client.dart`, `post`/`postMultipart` accept an optional `idempotencyKey` and send `Idempotency-Key`; add a uuid-v4 helper (`Random.secure`, no new package) in `lib/core/`.
- [X] T059 [P] In `lib/models/wallet.dart`, replace `TopUpMethod` with:
  - `ReceivingAccount` (`id`, `method`, `details` key/value list, `dailyLimit`, `providerFeePercent`, `note`);
  - `TopUpMethods` (`reference`, method groups);
  - `TopUp` (contract fields, `TopUpStatus`, `TopUpRejectReason`, `canCancel`).

  Each has `fromJson`. Update `lib/mock/mock_wallet.dart` to the new shapes (prototype data, reference `DAHAB-004417`).
- [X] T060 In `lib/services/repositories.dart`, `WalletRepository` gains `topUpMethods()` → `TopUpMethods`, `uploadReceipt(bytes, filename, mime)` → token, `submitTopUp(amount, accountId, receiptToken, idempotencyKey)`, `topUps({status, cursor})` and `cancelTopUp(id, idempotencyKey)`. Implement them in `lib/services/api/wallet_api.dart` (`ApiWalletRepository`, no more fallback for methods) and `lib/services/mock_repositories.dart`. Map 403 `verification_required` and `account_suspended`, and 409 `illegal_topup_transition`, to the app's existing error types.
- [X] T061 `lib/features/wallet/wallet_screens.dart` — `AddFundsScreen`:
  - loads the live methods;
  - the method list, then the chosen method's account(s);
  - detail rows labelled per key in English and Arabic (`lib/core/i18n`);
  - the reference row and copy;
  - the provider-fee estimate from `providerFeePercent` (labelled as the provider's), and the daily limit when set (display only);
  - receipt pick via `file_picker` (image/PDF) → `uploadReceipt`;
  - *I've sent the transfer* → `submitTopUp` with one idempotency key per screen visit (reused on retry), disabled while sending, then the existing "Thanks" message;
  - error states for suspended and unverified customers; on 422 for a deactivated account, reload the methods and ask the customer to pick again.

  Add `TopUpsScreen` (route `R.topups`, reached from the Wallet screen): own notices with number, method, amounts and status chips (pending, on hold "we're checking", credited amount, rejected with the plain reason, cancelled) and *Cancel* on pending ones.
- [X] T062 Extend `test/flows_test.dart`'s fake backend with `/customer/me/wallet/topup-methods`, `/customer/me/uploads` (`topup_receipt`), `POST`/`GET /customer/me/wallet/topups` and `/cancel`. Add flow tests: add funds → notice listed as pending; cancel; a suspended customer sees the notice. Run `flutter analyze` and `flutter test`.

---

## Phase 9: Polish & cross-cutting

- [X] T063 [P] Postman: add a "Wallet top-up" folder to `postman/Dahab-Backend.postman_collection.json` with all 16 requests, bodies matching the FormRequests, `Idempotency-Key: {{$guid}}` on the keyed POSTs, saved `topup_id` / `receiving_account_id` test scripts, and the `topup_receipt` upload example. Add environment variables as needed, per `postman/README.md`.
- [X] T064 [P] Run `composer swagger:generate`: all 16 operations plus the changed upload purpose are present, and there are no warnings.
- [X] T065 [P] Update `CLAUDE.md` Part 1 "Current state" (Backend: wallet top-up; Dashboard: Incoming transfers and Receiving accounts live; Flutter: Add funds and Top-ups live) and `docs/features/wallet-topup.md` status. Update the memory note [[wallet-topup-is-manual-transfer]] if a decision changed.
- [X] T066 Quality gates (`laravel-quality-gates` skill): `./vendor/bin/pint --test`, `composer test`, `migrate:fresh --seed` and `migrate:rollback` as `dahab`, `route:list` (idempotent on every money POST, no route without a gate or permission), and an N+1 review of the list and export. Security review:
  - the receipt stream is permission-gated and `no-store`;
  - no staff note reaches a customer resource or message;
  - no COO seed.
- [X] T068 [P] (optional, opt-in `perf` group) `tests/Performance/TopUpPerformanceTest.php`, following spec 008's `tests/Performance/LedgerPerformanceTest.php`: seed 100k `topup` rows across statuses and 10k customers, then assert `GET /dashboard/topups` (default filter, a `q` search, and a 30-day range) answers in < 500 ms and a customer's `GET /customer/me/wallet/topups` in < 300 ms; also `EXPLAIN` shows the `(status, topup_no DESC)` and `(customer_id, topup_no DESC)` indexes are used. Excluded from the default `composer test` run. **As built (2026-09-29)**: the targets hold with a wide margin (staff list ≈ 75–100 ms, customer list ≈ 10 ms). PostgreSQL walks the `topup_no` order for both staff filters, even a rare status, so the test asserts "no sequential scan" for staff lists and `idx_topup_customer_list` for the customer's; `idx_topup_status_list` is kept for selective queries but is not chosen today.
- [X] T067 Run the quickstart walk-through. Write the PR text for backend and dashboard, including the Principle V closing note from plan.md and the report shape from CLAUDE.md Part 1 Step 5. Don't commit or push until the user asks.

---

## Dependencies & execution order

- **Phase 1 → Phase 2 → stories.** Docs come before the migration (Constitution III), and T006/T007 block everything.
- **US1 (Phase 3)** and **US2 (Phase 4)** are independent after Phase 2: US2's tests create notices with `TopUpFactory`. US2 needs the notification classes (T038) before T039/T040.
- **US3 (Phase 5)** depends on US2's credit code (T039 → the shared `CreditsWallet`).
- **US4 (Phase 6)** is independent after Phase 2; tests use the seeder and factory accounts until then. US4 must be done before any production release (analysis C1).
- **Dashboard (Phase 7)** needs the US2–US4 endpoints. **Flutter (Phase 8)** needs the US1 endpoints.
- **Polish (Phase 9)** comes last. T045 (history reference) comes after T039. T068 (optional perf) needs US1 and US2 done; it runs before T067.

## Parallel opportunities

- Phase 1: T003, T004 and T005 alongside T002.
- Phase 2: T008–T011 and T015 in parallel after T007; T012–T014 sequentially (shared enums and bootstrap).
- US1: all test tasks T016–T021 in parallel; T025 and T027 in parallel.
- US2: tests T031–T037 in parallel; T038, T040, T041 and T042 in parallel after T039 is started.
- US4 can run alongside US2/US3 by a second worker.
- Phase 7 T052/T053 and Phase 8 T058/T059 in parallel once their endpoints exist.

## Implementation strategy

1. **MVP** = Phases 1–4 (notice + match), with the dashboard page for matching (T052–T054, T056) and the Flutter Add funds (T058–T061). With these, money can enter end to end, and the Principle V waiver closes.
2. Then US3 (by hand) and US4 (the receiving-accounts page). **US4 blocks production release**: until it ships, the only accounts are the fake local seed, so real customers must not see Add funds. Stories 1–2 are built and tested against test accounts only.
3. Polish, quality gates, then report and ask before any commit.
