# Research: Wallet Top-up (spec 009)

Decisions taken while planning. Each: **Decision**, **Rationale**, **Alternatives considered**.

## R1 — One `topup` table for notices and hand credits; the ledger is not changed

**Decision**: a new table `topup` holds every transfer notice (`origin = notice`) and every hand credit (`origin = by_hand`, created directly as `credited`). A credited row points to its ledger entry through `topup.ledger_txn_id UUID UNIQUE REFERENCES ledger_transaction`. The ledger tables, triggers and views from spec 008 are not altered.

**Rationale**: the unique FK is the data-level guarantee that a notice is credited at most once (FR-017). A column on the append-only `ledger_transaction` (like `withdrawal_id`) would need an `ALTER` on the ledger and still would not stop a second entry for the same notice. One table keeps the Incoming transfers list, export and customer history to one query.

**Alternatives**: separate `transfer_notice` and `incoming_transfer` tables (a bank-feed model) — rejected: Dahab has no feed, staff confirm arrivals by hand, and two tables would need a matching join that adds nothing. A `topup_id` column on `ledger_transaction` — rejected (above).

## R2 — Status machine enforced in the Action, final states enforced by a trigger

**Decision**: statuses `pending`, `on_hold`, `credited`, `rejected`, `cancelled` (Clarification Q1). Allowed moves: `pending → on_hold | credited | rejected | cancelled`; `on_hold → pending | credited | rejected`. The Actions lock the row `FOR UPDATE`, check the move and raise `illegal_topup_transition` (409). A `BEFORE UPDATE` trigger `trg_topup_final` raises SQLSTATE `DH003` on any update of a row whose old status is final, and on any change of `customer_id`, `origin`, `claimed_amount`, `reference` or `submitted_at`. `DELETE` is refused by a trigger for everyone (records are permanent). `DH003` maps to 409 `illegal_topup_transition` in `bootstrap/app.php`, like `DH001`.

**Rationale**: FR-025 needs "one wins" under concurrency: the row lock serialises staff match against customer cancel, and the trigger is the backstop for any code path that forgets the lock.

**Alternatives**: a full transition table in the trigger — rejected as duplication of the Action; PHP-only checks — rejected (no backstop).

## R3 — Row-shape CHECK constraints per status and origin

**Decision** (data-model has the full list):
- `credited` ⇔ `credited_amount`, `receiving_account_id`, `credited_by`, `credited_at`, `ledger_txn_id` all set;
- `rejected` ⇔ `reject_reason`, `reject_note`, `rejected_by`, `rejected_at` set;
- `on_hold` ⇒ `hold_note`, `held_by`, `held_at` set;
- `cancelled` ⇔ `cancelled_at` set;
- `origin = by_hand` ⇒ `status = credited`, `claimed_amount IS NULL`, `credit_note IS NOT NULL`;
- `origin = notice` ⇒ `claimed_amount IS NOT NULL`;
- `credited_amount IS DISTINCT FROM claimed_amount AND origin = notice AND status = credited` ⇒ `credit_note IS NOT NULL` (Clarification Q2);
- amounts `> 0` with at most 2 decimal places (`amount = round(amount, 2)`), stored as `NUMERIC(18,4)` like the ledger.

**Rationale**: Constitution I and III — the shape of a credited record must not depend on remembering a PHP rule.

## R4 — Receiving accounts are typed reference data, not free-form JSON

**Decision**: table `receiving_account` with `method topup_method`, typed detail columns (`bank_name`, `account_holder`, `account_number`, `iban`, `instapay_address`, `wallet_number`), display-only `daily_limit` and `provider_fee_percent`, `customer_note`, `sort_order`, `is_active`, `updated_by`. A CHECK per method requires its details: bank transfer → `bank_name`, `account_holder`, `account_number`; InstaPay → `instapay_address`; Vodafone Cash → `wallet_number` (Egyptian mobile, `^01[0125][0-9]{8}$`). No delete: deactivate only (FR-002). Not customer-owned, so no RLS (like `branch`, `karat`).

`provider_fee_percent` is display text for the Customer App's "You send / provider fee / reaches your wallet" estimate (prototype); Dahab never computes a credit from it — staff credit what arrived (Q2).

**Rationale**: typed columns make a wrong account detail a validation error, not a silently broken screen, and the Customer App can lay the rows out per method as the prototype does.

**Alternatives**: a `details JSONB` list of label/value rows — rejected: unvalidated money routing data. Reading the fee and limit from `setting` — rejected: they belong to one provider account, and the product owner wants accounts managed in one place.

## R5 — The reference is derived: `DAHAB-` + `customer.display_ref`

**Decision** (Clarification Q4): `TopUpReference::for(Customer)` returns `'DAHAB-'.display_ref`. The notice stores the reference it showed (`topup.reference`) so later display-ref changes cannot rewrite history. Staff search normalises the term (upper-case, spaces and dashes removed, optional `DAHAB` prefix stripped) and matches `customer.display_ref`, `customer.phone`, or `customer.full_name ILIKE`.

**Rationale**: `display_ref` is already unique and searchable (spec 007); no new column or generator.

## R6 — Customer gate: `trade` for methods, submit and receipt upload; `verified` for history and cancel

**Decision**: `GET /customer/me/wallet/topup-methods`, `POST /customer/me/wallet/topups` and a receipt upload (`POST /customer/me/uploads` with `purpose = topup_receipt`) use the `trade` gate (Part 1 §2.2, Part 2 §8 `trade_allowed`): pending/rejected → 403 `verification_required`, suspended → 403 `account_suspended`. The upload route stays open (identity uploads need it); `StoreUploadRequest` runs the same standing check when `purpose = topup_receipt` (`EnsureCustomerStanding::assert($customer, 'trade')` extracted from the middleware). Reading one's own notices (`GET …/topups`) and cancelling a pending one use `verified`: a suspended customer may still see and withdraw a notice — neither moves money nor returns receiving details (confirmed by the product owner, second-analysis L2). Both new customer routes are gated, so `CustomerRouteAccess` needs no change (the build test keeps passing).

**Rationale**: spec FR-008; reads for suspended customers follow spec 008's wallet reads.

## R7 — Receipts reuse the encrypted private store and upload tokens

**Decision**: new `UploadPurpose::TOPUP_RECEIPT = 'topup_receipt'`. Files go through `IdentityDocumentStorage` (disk `identity_private`, application-side encryption) under `topup-receipts/{customer_id}/{uuid}.enc`; the class gains `storeAt(string $prefix, …)` and its docblock is widened to "private customer uploads" (no rename — no unrelated refactoring). Accepted types for this purpose: JPEG, PNG, WebP and **PDF** (bank apps export PDFs), size limit `dahab-identity.max_upload_kb`. The mime is recorded (`topup.receipt_mime`) so the staff endpoint streams it with the right `Content-Type`. The upload token (`UploadTokenStore`, purpose-bound, single use) is consumed by the submit Action. Viewing a receipt is not audited (spec Assumptions); the match is.

**Alternatives**: a new disk — rejected (the product owner asked to reuse the private uploads disk).

## R8 — Match posts through the money service in the staff scope

**Decision**: `MatchTopUpAction::handle(Staff, topupId, amount, receivingAccountId, ?note, RequestContext)` in one `DB::transaction`:
1. lock the `topup` row `FOR UPDATE`; status must be `pending` or `on_hold`;
2. the receiving account must exist and have the notice's method (it may be inactive now — money already arrived there); lock the customer row `FOR SHARE` and, if `status = suspended`, require `arrival_reference` (otherwise 422) — second-analysis M1;
3. `PostLedgerEntryAction` with `LedgerEntry(kind: topup, lines: [bank −amount, customer available +amount], actorStaffId, memo: "Top-up TOP-{n} · {method} · {reference}")`;
4. update the row to `credited` with the ledger id;
5. audit `topup.matched` (before/after status, claimed and credited amounts, account, ledger id; `reason` = the note).

After commit, `TopUpCreditedNotification` is sent. The by-hand path (`CreditTopUpByHandAction`) inserts a `credited` row with `origin = by_hand` and runs steps 3–5 (`topup.credited_by_hand`). It first locks the customer row and decides by status (post-analysis clarification):
- `active` → allowed;
- `suspended` with `status_before_suspension = active` → allowed only with `arrival_reference` (the provider's transaction reference proving the money reached Dahab); without it → 422 (`arrival_reference` required). The audit `after` records `customer_status: suspended` and the reference (matching applies the same reference rule, step 2 above);
- `pending_verification`, `rejected`, or `suspended` from either → 403 `verification_required`, nothing written.

This allowance is staff-side only: the customer-side gates (R6) are unchanged, so a suspended customer still gets `account_suspended` on the methods, receipt upload and submit endpoints, and no customer response carries receiving-account details except `topup-methods`.

The memo distinguishes the path: `… · matched from notice DAHAB-…` vs `… · credited by hand`, so the Wallet statement shows the design's "matched by reference / by hand" (spec FR-021).

**Rationale**: spec 008 R2 (the caller owns the transaction); Part 2 §9 (audited, idempotent). The customer's available account is never debited, so `insufficient_funds` cannot occur.

## R9 — Idempotency on every POST that moves money or changes a notice

**Decision**: the `idempotent` middleware (spec 007) is on submit, cancel, match, hold, unhold, reject and by-hand credit. Receiving-account create/update are not money moves and follow the reference-data pattern (no key), like branches (spec 004).

**Rationale**: the user asked for Idempotency-Key "on every money POST"; hold/reject/cancel are state changes on a money record, cheap to include, and protect the double-tap in the Customer App.

## R10 — Notifications after commit, SMS plus email

**Decision** (Clarification Q3): `TopUpCreditedNotification(amount, topupNo)` and `TopUpRejectedNotification(reason)` — `ShouldQueue`, `via()` = `sms` plus `mail` when the customer has an email, bilingual by `preferred_lang`, never the staff note. Dispatched after the transaction returns, as `ReviewIdentityDocumentAction` does. Tests use `Notification::fake()` and assert the channel list and that nothing was sent when the transaction failed.

## R11 — Permissions: two new codes, Finance only by seed

**Decision**: `topup.match` — "Match an incoming transfer" (list, detail, receipt, export, match, hold, unhold, reject, credit by hand, and reading receiving accounts for the match form); `topup.accounts.manage` — "Manage Dahab's receiving accounts". Group `Money`. `seedRoles()` = `[finance]` (CEO gets every code); never `coo` (Part 1 §4.2, spec 002 FR-051). `GET /dashboard/receiving-accounts` accepts either code (`staff.permission:topup.match|topup.accounts.manage` — `EnforceStaffPermission` already supports `a|b`, spec 006).

## R12 — History and statement show the top-up's number

**Decision**: `ListCustomerWalletHistoryAction` and `BuildWalletStatementAction` left-join `topup` on `ledger_txn_id` and fill `reference` with `TOP-{topup_no}` for top-up rows (it is `null` today), and the statement's memo already carries the method. The Customer App mock's `TOP-88214` style is kept. Non-breaking: a nullable string gains values.

## R13 — Paths

**Decision**: customer — `GET /customer/me/wallet/topup-methods`, `POST|GET /customer/me/wallet/topups`, `POST /customer/me/wallet/topups/{topup}/cancel`. Dashboard — `GET /dashboard/topups`, `GET /dashboard/topups/export`, `GET /dashboard/topups/{topup}`, `GET /dashboard/topups/{topup}/receipt`, `POST /dashboard/topups/{topup}/match|hold|unhold|reject`, `POST /dashboard/topups` (credit by hand), `GET|POST /dashboard/receiving-accounts`, `PATCH /dashboard/receiving-accounts/{account}`. Part 2 §8's `POST /me/wallet/topup` and §9's `POST /admin/transfers/match` are updated to these as-built paths (spec 008 moved the wallet reads the same way).

## R14 — Constitution Principle V waiver ends here

**Decision**: the match and by-hand tests go through HTTP and assert the ledger rows, the postings, the customer's available balance, the bank's cash (`−SUM(bank)`), the global zero, the audit row, the notification and idempotent replay. The PR body states that the spec 008 waiver is closed.

## R15 — Throttle on submit

**Decision**: `throttle:customer.topups` (limit in `config/dahab-wallet.php`, default 10 per minute per customer) on `POST …/topups`, alongside the idempotency key.

**Rationale**: a notice creates work for Finance; the throttle bounds abuse without a business limit (Q5 says amounts are not limited).
