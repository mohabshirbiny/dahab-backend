# Feature Specification: Withdrawals and payout accounts (money out)

**Feature Branch**: `feature/withdrawals` (backend and dashboard, both from `main` at backend `0ec619c` / dashboard `221afe1`; the Customer App has no repository). Backend worktree `withdrawals-payout-accounts-43d3dd`, dashboard worktree `.claude/worktrees/withdrawals`.

**Created**: 2026-10-03

**Status**: Draft (clarified)

**Input**: User description: "Spec 013 — Withdrawals and payout accounts (money out), all three projects. Customer payout accounts (add with ownership confirmation → pending_review; list with state and recent changes; staff verify name vs ID or refuse with reason; money leaves only to a verified account in the customer's own name; adding/changing an account cancels un-left withdrawals and opens a withdrawal pause of withdrawal.account_change_pause_hours, customer told on phone+email; one or several accounts to settle in clarify). Withdrawals: email second-check (Part 1 §2.4), request/cancel; staff Withdrawals queue review/release/reject; every hold/release/return a balanced `withdrawal` ledger entry (hold available→held, release held→bank); pause-expiry job; audit; notifications. Dashboard Withdrawals page live, payout-account verification, payout account + pause in the Customer file. Customer App Bank accounts, Add a bank account, Payout account in Your details, Withdraw with the email confirmation, EN/AR, on the live API."

## Context

- **Sources**:
  - Technical Spec Part 1 §2.1 (email is the second confirmation channel on withdrawal; "one-time signed token, single use, short TTL"), §2.2 (a suspended customer may still withdraw a remaining balance), §2.4 (the email second-check: no confirmed email token, no withdrawal; independent of the session), §4.2 "Release a withdrawal" (CEO, Finance — never the COO), §4.3 "Verify a payout bank account" (CEO, Finance, Verification), §9 (`email_confirmation_required` 403).
  - Technical Spec Part 2 §8 "Payout accounts & withdrawals" (`POST /me/payout-accounts`, `POST /me/withdrawals`, `POST /me/withdrawals/{id}/cancel`), §9 "Admin — money" (the withdrawals queue, review, release, reject; `POST /admin/payout-accounts/{id}/verify`), §11 (withdrawal-pause expiry), §12 (`withdrawals_paused`, `payout_account_not_active`, `illegal_withdrawal_transition`).
  - Technical Spec Part 3 §11 (request → hold, the two gates, account change → pause + cancel, release by a person, money out only to the customer's own name), §12 (withdrawal-pause expiry job).
  - Schema: `04_schema_market.sql` §12 (`payout_account`, `withdrawal_pause`, `withdrawal`), `01_schema_core.sql` (`withdrawal_state`, `payout_account_state`, ledger kind `withdrawal`, setting `withdrawal.account_change_pause_hours` = 48), `05_schema_security.sql` (`withdrawal_transition`, the planned RLS owner predicates, `one_time_token`), `03_schema_ledger.sql` (`ledger_transaction.withdrawal_id`, "FK added in Part 3"; release = customer hold −X, bank +X), `02_schema_identity.sql` (`agreement_acceptance` context `payout_account`).
  - Business documents: blueprint (money out only to an account in the customer's own name; every withdrawal checked by a person; changing the payout account cancels any withdrawal not yet left and pauses new ones), terms draft §9.3 and the "When adding a payout account" tick, admin-roles (Finance reviews and releases withdrawals and verifies payout accounts, including re-checking a changed account; Verification confirms the name matches the ID; no role can skip the pause; audit "any withdrawal released, and by whom", "any payout-account change, and the pause it triggers"), open questions §3 (pause read from the admin panel; no fee on payouts) and §5 ("at what point does manual review of every withdrawal stop being possible" — unanswered, not in scope).
  - Customer prototype: Bank accounts (`#s-bank`: the accounts with *Active*, *Under review* and *Removing* tags, *Use this one*, *Remove*, *Cancel this request*, *Keep it after all*, *Add another account*, *Recent changes*, "No payout account yet"), Add a bank account (`#s-bankadd`: bank, account holder name, account number or IBAN, the ownership tick, *Send for review*), Your details → *Payout account* (`#s-account`), Withdraw (`#s-withdraw`: available to withdraw, amount with quick picks, *Goes to*, *Use a different account*, *Confirm it's you*, *Withdraw*).
  - Dashboard design `p-withdrawals`: figures (waiting for review, released today, on hold, average time to release), date range, search, *Export to Excel*, rows (requested, amount of available, where it goes with the name check, *Before you release* signals), actions *Release* (record the bank transaction number, the reference on the transfer and the value date — "Send the transfer at the bank first, then record its details here"), *Transfer file*, *Hold* (a reason from a list and what the customer is told), *Ask for the full name*, *Refuse*. The Customer file shows *Payout account* and *Name on the account — Matches ID*.
- **What exists** (specs 001–012): the money service and each customer's available and held accounts; the wallet reads for customers and staff; verified customers with a confirmed email (registration requires it); the trade and verified gates; suspension; the idempotency layer, audit log, system actor, forced row-level security; SMS + email after commit; the settings catalogue with `withdrawal.account_change_pause_hours`; legal documents and agreement acceptances (spec 010); the Dashboard Customer file with the wallet panel and the Incoming transfers CSV export pattern (spec 009); the Customer App wallet screens on the live API.
- **What does not exist**: no `payout_account`, `withdrawal_pause` or `withdrawal` tables; no money ever leaves a wallet; the Dashboard Withdrawals page is a placeholder; the Customer App's bank and withdraw screens run on mock data (`MockAccountRepository`, `AccountController`).

## Clarifications

### Session 2026-10-03

- Q: How many payout accounts, and which one does a withdrawal go to? → A: **Several, exactly one in use.** Withdrawals always go to the in-use verified account. When an account is verified and the customer has no account in use, it becomes the account in use. Adding another account only waits for review. **The change that cancels un-left withdrawals and opens the pause is an account becoming the one in use** (*Use this one*, or a newly verified account taking the place of a removed one) — except the very first time the customer ever has an account in use.
- Q: Can a customer remove an account or cancel one under review? → A: **Yes, the prototype's rules.** *Cancel this request* removes an account under review at once. *Remove* on a verified account removes it at once, unless a withdrawal not yet released goes to it: then it is *Removing* (no new withdrawal may use it; *Keep it after all* returns it to active, with no pause because nothing changed) and becomes removed in the same operation that ends its last withdrawal. Removing the account in use leaves none in use until another becomes the one in use (that is a change: pause).
- Q: What happens to a refused account? → A: **A new final state `refused`**, with a reason from a list (`name_mismatch` · `name_shortened` (ask for the full name) · `not_in_customer_name` · `details_invalid` · `other`) and a staff note; the customer sees the reason, never the note, and adds a new account.
- Q: Who verifies, and where? → A: New permission **`payout_account.verify`** (seeded CEO, Finance, Verification). A *Payout accounts to check* tab on the Withdrawals page (oldest first, the holder name beside the verified ID name) **and** Verify / Refuse in the Customer file.
- Q: How does the email second-check work? → A: **Confirm, then submit.** The customer enters the amount and taps Withdraw → a link naming the amount and the account is emailed to their confirmed address. The link opens a Customer App page with a *Confirm* button that works without signing in (so a mail scanner opening the link confirms nothing). The Withdraw screen shows *Waiting* then *Confirmed*; tapping Withdraw again submits the request with the confirmation. The confirmation is tied to the customer, the amount and the account.
- Q: How long is the link valid? → A: **30 minutes**, single use (a configuration value, not a Dashboard setting). Asking again replaces any earlier unconfirmed link.
- Q: Does a withdrawal also need an SMS code (the prototype shows one)? → A: **Email only** (Part 1 §2.4). The phone row is dropped from the Withdraw screen.
- Q: Fees, minimum, maximum? → A: **None.** No fee; any amount from 0.01 EGP (2 decimals) up to the available balance.
- Q: What may a suspended customer do? → A: **Withdraw, no account changes.** Request and cancel withdrawals to an already verified account in use (verified gate). Adding, switching to, removing or keeping an account needs the trade gate (`account_suspended`). Staff may still hold or reject any withdrawal.
- Q: When the account in use changes, what happens to un-left withdrawals, and what does the pause-expiry job do? → A: **Cancel them; the job tells the customer.** Every withdrawal of the customer not yet released (`requested`, `under_review`) is cancelled and its money returned in the same operation as the change. The state `on_hold_account_change` is never used. A scheduled job notices each pause that has ended and tells the customer once that withdrawals are open again (system actor).
- Q: How does review work (the design's Release, Hold, Refuse)? → A: **Take for review, a hold flag, release or reject.** Take for review: `requested → under_review` with the reviewer. *Hold*: a flag on an `under_review` withdrawal with a reason from the design's list (`name_mismatch` · `account_changed_recently` · `identity_pending` · `money_in_straight_out` · `other`), a message the customer is told and a staff note; *Unhold* clears it; a held withdrawal cannot be released. *Reject*: a reason (`account_not_in_name` · `money_in_straight_out` · `identity_unconfirmed` · `customer_request` · `other`) and a required staff note; the money returns. No new state.
- Q: How does money leave, and what is recorded at release? → A: **A person's transfer, then a record.** Finance sends the transfer at Dahab's bank, then releases with the **bank transaction number** (required), the **reference on the transfer** and the **value date**. No bank integration; the design's *Transfer file* is not built; `released` is final in this spec (`settled` and a bounced transfer are out of scope).
- Q: Which permissions cover the queue? → A: **One code `withdrawal.release`** (seeded CEO, Finance; never the COO): it opens the queue and covers take for review, hold, unhold, reject, release and the export.
- Q: How is "held" shown now that withdrawals also hold money? → A: **Split it.** The customer's wallet, the Customer file wallet and the Overview add *pending withdrawals* beside *held on open orders*; the total held is unchanged. The Customer App shows it as "On its way to your bank".
- Q: Export? → A: **CSV export of the filtered list, audited**, as Incoming transfers (spec 009), permission `withdrawal.release`.
- Q: Account number formats? → A: **An Egyptian IBAN (`EG` + 27 digits, checksum checked) or an account number of 8–20 digits**, spaces removed. Shown masked (last 4) everywhere except to staff with `payout_account.verify` or `withdrawal.release`.
- Q: Which "Before you release" signals? → A: **The facts that already exist**: identity verified / suspended; account added, verified when and by whom; first payout to this account, and times paid out to it; account in use changed recently (pause history); completed sales as a seller; topped up and never traded since. Open disputes and "weight short" are not built.
- Q: Bank name? → A: **Free text**, 2–80 characters.
- Q: Rejection reasons? → A: **Short fixed lists** (above) plus a required staff note; the customer sees the reason, never the note.

### Session 2026-10-03 (after `/speckit-analyze`)

- Q: May an in-flight withdrawal still be released to an account the customer is removing? → A: **Yes.** Release accepts an account that is `active` or `removing` (and the customer's own); the release removes a `removing` account when it was its last un-released withdrawal (analysis I1).
- Q: Does a `removing` account stay the one in use? → A: **Yes, until it is removed**, but no new confirmation or withdrawal may use it (`payout_account_not_active`) (analysis I4).
- Q: Are the schema additions and deviations in the plan approved? → A: **Approved in full** by the product owner: `WD-{n}`, `payout_account_change`, `withdrawal_confirmation`, the hold / bank-record / rejection fields, `ended_notified_at`, the four new error codes, and no pause re-check at release (analysis D1).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The customer adds a payout account (Priority: P1)

A verified customer opens Bank accounts, taps *Add another account*, enters the bank, the account holder name exactly as on their ID and the account number or IBAN, ticks that the account is theirs, the details are correct and the name matches their ID, and sends it for review. The account appears as *Under review*; they are told on their phone and email that an account was added.

**Why this priority**: no money can leave without an account in the customer's name.

**Independent Test**: A verified customer with no account adds CIB / "Mona Hassan Ibrahim" / a valid Egyptian IBAN with the tick; the account is `pending_review`, an acceptance of the current payout-account declaration is recorded, no pause is opened and nothing is cancelled. An invalid IBAN checksum is refused (422).

**Acceptance Scenarios**:

1. **Given** a verified, not suspended customer, **When** they submit bank, holder name, account number or IBAN and the ownership confirmation, **Then** the account is stored `pending_review`, the acceptance of the current payout-account declaration is recorded (context `payout_account`), the addition is audited and appears in their recent changes, and after commit they are told on their current phone and email.
2. **Given** the ownership confirmation is missing, a field is empty, the bank name is not 2–80 characters, or the number is neither a valid Egyptian IBAN nor 8–20 digits, **Then** 422 with field errors and nothing is stored.
3. **Given** an unverified customer, **Then** `verification_required` (403); a suspended customer, **Then** `account_suspended` (403).
4. **Given** the same submission twice with the same key, **Then** one account.

---

### User Story 2 - Staff verify or refuse a payout account (Priority: P1)

A staff member allowed to verify payout accounts opens *Payout accounts to check* (or the Customer file), compares the holder name with the customer's verified ID name and either verifies the account or refuses it with a reason.

**Why this priority**: "money leaves only to an account in the customer's own name" is enforced by this check.

**Independent Test**: An account `pending_review` of a customer with no account in use; a staff member with `payout_account.verify` verifies it → `active` and in use, checker and time recorded, an audit row, no pause (first account in use); the customer is told. Another account is refused with `name_shortened` → `refused`, the customer told the reason.

**Acceptance Scenarios**:

1. **Given** an account under review, **When** a permitted staff member verifies it, **Then** it becomes `active` with `name_checked_by`, `name_checked_at`, `activated_at`; if the customer has no account in use it becomes the one in use (a change, with the pause and cancellations of US3, unless it is the customer's first account ever in use); audited; the customer is told.
2. **When** they refuse it with a reason and a note, **Then** it becomes `refused` (final), the reason, note, staff and time are stored and audited, and the customer is told the reason (never the note).
3. **Given** an account not under review, **Then** `illegal_payout_account_transition` (409); no permission, **Then** 403 and an audit row.
4. **Given** the list of accounts to check, **Then** oldest first, each with the customer's display reference, verified ID name, holder name, bank and the full number.

---

### User Story 3 - The customer changes the account in use, removes or keeps one (Priority: P1)

The customer makes another verified account the one in use, removes an account, cancels a request under review, or keeps an account they asked to remove. Changing the account in use cancels any withdrawal not yet released (money back at once) and pauses new withdrawals for the set window; they are told on phone and email.

**Independent Test**: A customer with account A in use and a withdrawal `under_review` makes verified account B the one in use: the withdrawal is `cancelled`, held −X / available +X, a pause until now + 48 h exists, A stays active (not in use), B is in use, SMS and email are sent. Removing A while a withdrawal to it is `requested` makes A `removing`; cancelling that withdrawal removes A in the same operation.

**Acceptance Scenarios**:

1. **Given** a verified account not in use, **When** the customer makes it the one in use, **Then** in one operation: it becomes in use (the previous one no longer is); every `requested`/`under_review` withdrawal of the customer is cancelled with its money returned held → available; a `withdrawal_pause` is opened until now + `withdrawal.account_change_pause_hours` (read live; none when the setting is 0), naming the account; all audited; after commit the customer is told on their current phone and email.
2. **When** the customer cancels a request under review, **Then** the account becomes `removed`.
3. **When** the customer removes an `active` account with no un-released withdrawal to it, **Then** it becomes `removed` (and stops being in use if it was); **with** such a withdrawal, **Then** it becomes `removing`: it keeps its in-use flag until it is removed, but no new confirmation or withdrawal may use it (`payout_account_not_active`); its in-flight withdrawals may still be released to it; and it becomes `removed` (no longer in use) in the same operation that releases, rejects or cancels its last such withdrawal.
4. **When** the customer keeps a `removing` account, **Then** it returns to `active` with no pause.
5. **Given** a suspended customer, **Then** each of these is refused `account_suspended`; a wrong state → `illegal_payout_account_transition` (409); another customer's account → not found.

---

### User Story 4 - The customer withdraws with the email second-check (Priority: P1)

The customer opens Withdraw, sees what is available, enters an amount (or *All of it*), sees the account in use it goes to and taps Withdraw. A link arrives at their confirmed email; they open it and tap *Confirm*; back in the app the screen shows *Confirmed* and they tap Withdraw again. The amount leaves available and is held until a person releases it.

**Why this priority**: this is the feature — taking money out.

**Independent Test**: A customer with 56,760.0000 available and a verified account in use asks to withdraw 42,000.00; a confirmation is created and emailed; it is confirmed through the public confirm call; the withdrawal is submitted: `requested`, available 14,760.0000, held +42,000.0000 in one balanced `withdrawal` entry tied to the withdrawal; the confirmation is used. Without a confirmed confirmation → `email_confirmation_required` (403).

**Acceptance Scenarios**:

1. **Given** a verified customer (suspended or not) with an account in use, an amount of 0.01–available with at most 2 decimals and no open pause, **When** they ask to withdraw, **Then** a confirmation (customer, amount, account, 30-minute life) is created, any earlier unconfirmed one is replaced, and a link is emailed to their confirmed address; the answer carries the confirmation id, its state and expiry and the masked email.
2. **When** the link's page confirms it before expiry, **Then** the confirmation is `confirmed`; the confirm call needs no sign-in, reveals nothing about the customer beyond the amount and the masked account, and refuses an expired, used or unknown token.
3. **When** the customer submits with a confirmed, unused, unexpired confirmation of their own for the same amount and account, **Then** in one operation the gates are re-checked, the withdrawal is created `requested`, one balanced `withdrawal` entry moves available −X / held +X tied to it, and the confirmation is used.
4. **Given** no such confirmation, **Then** `email_confirmation_required` (403); an open pause, **Then** `withdrawals_paused` (409) with the pause end; no verified account in use, or the account is `removing`, **Then** `payout_account_not_active` (409); too little available, **Then** `insufficient_funds` (409) with the available balance and the shortfall.
5. **Given** the same submission twice with the same key, **Then** it takes effect once.

---

### User Story 5 - The customer cancels a withdrawal (Priority: P2)

Before a person releases it, the customer can cancel; the money returns to available at once.

**Acceptance Scenarios**:

1. **Given** a withdrawal `requested` or `under_review` (held or not), **When** its owner cancels, **Then** in one operation it becomes `cancelled` and one balanced `withdrawal` entry returns held → available; a `removing` account with no other un-released withdrawal becomes `removed`.
2. **Given** any other state, **Then** `illegal_withdrawal_transition` (409); another customer's withdrawal is not found.

---

### User Story 6 - Finance reviews, holds, releases or rejects (Priority: P1)

Finance opens the Withdrawals page: the figures, the queue oldest first with the amount, the customer, the account and its name check and the signals to look at before releasing. They take one for review, hold it if something needs checking (the customer is told a message), send the transfer at the bank, then record its details and release it — the held amount leaves to the bank. Or they reject it with a reason and the money returns.

**Why this priority**: every withdrawal is released by a person; without the queue no money leaves.

**Independent Test**: A `requested` withdrawal of 42,000; Finance takes it (`under_review`), holds it (`account_changed_recently`), unholds, then releases with bank transaction number "FT2610031234", reference "DAHAB-4417" and today's value date: held −42,000, bank +42,000 in one `withdrawal` entry; `released`, `released_at`, `reviewed_by`, `release_txn_id` and the bank details stored; the bank cash figure drops by 42,000; the customer is told. A COO gets 403 and an audit row.

**Acceptance Scenarios**:

1. **Given** `withdrawal.release`, **Then** the queue lists withdrawals (state filter, default requested and under review; held only; Cairo date range; search by customer reference, phone or name), oldest first, with figures (waiting count and sum, released today count and sum, on hold count, average time to release over the last 30 days), each with the account (full number), its name check and the signals.
2. **When** they take a `requested` withdrawal, **Then** `under_review` with the reviewer, audited.
3. **When** they hold an `under_review` withdrawal with a reason, a customer message and a note, **Then** it is flagged held, audited, and the customer is told the message; **unhold** clears it, audited.
4. **When** they release an `under_review`, not held withdrawal with the bank transaction number (and optionally the transfer reference and value date), **Then** in one operation the account is re-checked (`active` or `removing`, and the customer's), one balanced `withdrawal` entry moves held −X / bank +X, the withdrawal becomes `released` with the release transaction, time, reviewer and bank details, a `removing` account with no other un-released withdrawal becomes `removed`, an audit row is written, and after commit the customer is told.
5. **When** they reject a `requested` or `under_review` withdrawal with a reason and a note, **Then** in one operation it becomes `rejected`, one balanced entry returns held → available, it is audited, and the customer is told the reason.
6. **Given** a held withdrawal at release, **Then** `withdrawal_on_hold` (409); a wrong state or a lost race, **Then** `illegal_withdrawal_transition` (409); no permission (the COO by default), **Then** 403 and an audit row.
7. **Given** export, **Then** a CSV of the filtered list, audited.

---

### User Story 7 - The pause ends and the customer is told (Priority: P3)

When a pause window passes, the customer can withdraw again without anyone doing anything, and is told so once.

**Acceptance Scenarios**:

1. **Given** a pause whose end has passed, **Then** requests are no longer refused for it.
2. **When** the scheduled job runs, **Then** for each ended pause not yet announced it tells the customer withdrawals are open again and marks the pause announced, as the system actor, one pause per operation, safe to run twice; a pause superseded by a later open pause of the same customer is marked without a message.

---

### User Story 8 - Staff see payout accounts, pauses and withdrawals in the Customer file (Priority: P2)

**Acceptance Scenarios**:

1. **Given** `customer.view`, **Then** the file shows the payout accounts (bank, holder, masked number — full to holders of `payout_account.verify` / `withdrawal.release` — state, in use, checked by and when, refusal reason), any open pause and its end, and (with `wallet.view` or `withdrawal.release`) the customer's withdrawals.
2. **Given** `payout_account.verify`, **Then** the file offers *Verify* / *Refuse* on an account under review.
3. **Given** `wallet.view`, **Then** the wallet panel shows pending withdrawals beside held on open orders.

---

### User Story 9 - The Customer App runs on the API (Priority: P1)

The customer manages Bank accounts, adds an account, sees *Payout account* in Your details, and withdraws with the email confirmation, all on the live API, in English and Arabic, with plain empty and error states (no account yet, under review, refused with the reason, paused until …, not enough available, email not confirmed yet, link expired).

**Acceptance Scenarios**:

1. **Given** the app, **Then** Bank accounts, Add a bank account, Your details → Payout account, Withdraw and the link's *Confirm* page use the API; the wallet shows pending withdrawals; the mock bank data is gone; what stays mock is listed in the report.
2. **Given** a refusal (`withdrawals_paused`, `payout_account_not_active`, `insufficient_funds`, `email_confirmation_required`, `account_suspended`), **Then** the app shows the API's figures and a plain message.

### Edge Cases

- **Change of the account in use while a withdrawal is under review**: the withdrawal is cancelled and its money returned in the same operation; a release racing the change: exactly one wins (released money is never cancelled; a cancelled withdrawal is never released).
- **Release and customer cancel at the same moment**: exactly one wins; the money is either sent or returned, never both.
- **Two submissions at once over the available balance**: the second is refused `insufficient_funds`; available never goes below zero.
- **Suspended customer with money in the wallet**: may withdraw to the verified account in use and cancel; may not change accounts.
- **Pause setting changed while a pause is open**: the open pause keeps its end; the new value applies to the next change. Setting 0: no pause is opened.
- **Two changes in a row**: a new pause opens from the latest change; the customer is refused until the latest pause ends.
- **Email link opened twice, after expiry, or after a newer link**: refused; the app asks for a new link. A confirmation for a different amount or account cannot be used.
- **The account in use is removed or refused while a confirmation is open**: the submission is refused `payout_account_not_active`.
- **Verifying an account when none is in use, after an earlier one was in use**: it becomes the one in use and opens a pause.
- **Removing the only account in use with no withdrawal to it**: removed at once; the customer must have another account become in use (a change) before withdrawing.
- **Removing the account in use while a withdrawal to it is in flight**: the account is `removing` and keeps its in-use flag; the in-flight withdrawal may still be released to it; new confirmations and submissions are refused `payout_account_not_active` until another account becomes the one in use or the customer keeps this one.
- **A withdrawal held by staff and then cancelled by the customer**: allowed; the hold flag ends with it.
- **The held balance**: withdrawal holds and deposit holds share the customer's held account; every view that shows "held" splits pending withdrawals from held on open orders.

## Requirements *(mandatory)*

### Functional Requirements

**Payout accounts**

- **FR-001**: A verified, not suspended customer MUST be able to add a payout account (bank 2–80 characters, holder name, Egyptian IBAN with a valid checksum or an 8–20 digit account number, spaces removed) with an explicit acceptance of the current payout-account declaration (recorded with context `payout_account`); it starts `pending_review`; audited; the customer is told on their current phone and email.
- **FR-002**: A customer MUST be able to list their payout accounts (state, in use, bank, holder, masked number, added, checked, refusal reason) and their recent changes (added, verified, refused, made the one in use, removal scheduled, kept, removed, request cancelled — newest first), and see any open pause, under forced row-level security.
- **FR-003**: Staff with `payout_account.verify` MUST be able to list accounts under review (oldest first, with the customer's verified ID name) and verify (`active`; becomes the one in use when the customer has none) or refuse (`refused`, a reason from the list and a note) each; audited; idempotent; the customer is told (the reason, never the note).
- **FR-004**: Exactly one `active` account per customer MAY be the one in use. A verified customer MUST be able to make another `active` account the one in use, cancel a request under review (`removed`), remove an `active` account (`removed`, or `removing` while an un-released withdrawal goes to it — keeping its in-use flag but refusing new confirmations and withdrawals — becoming `removed` when the last one ends) and keep a `removing` account (`active`, no pause). All need the trade gate; all are audited and idempotent.
- **FR-005**: When an account becomes the one in use, except the first time the customer ever has one, the same operation MUST cancel every `requested`/`under_review` withdrawal of the customer (money returned held → available) and open a `withdrawal_pause` until now + `withdrawal.account_change_pause_hours` (read live; none when 0) naming the account; audited; the customer is told on their current phone and email after commit.
- **FR-006**: Account state changes MUST follow a transition table in the database (`pending_review → active | refused | removed`, `active → removing | removed`, `removing → active | removed`); otherwise `illegal_payout_account_transition` (409).

**Withdrawals**

- **FR-007**: A verified customer (suspended or not) MUST be able to ask for a withdrawal confirmation for an amount (0.01 up to available, 2 decimals, no fee) to their account in use: a single-use confirmation tied to the customer, the amount and the account, valid 30 minutes, replacing any earlier unconfirmed one, sent as a link to their confirmed email (Part 1 §2.4). The gates of FR-009 are checked first so the customer is not sent a link that cannot be used.
- **FR-008**: The link MUST open a page that confirms the confirmation only on an explicit action, without sign-in; the confirm call refuses unknown, expired, replaced or used tokens, is rate-limited, and returns only the amount, the masked account and the state. The customer's app MUST be able to read the confirmation's state.
- **FR-009**: Submitting a withdrawal MUST, in one operation, refuse unless: the customer is verified; the confirmation is theirs, confirmed, unused, unexpired and for the same amount and account (`email_confirmation_required`, 403); no pause covers now (`withdrawals_paused`, 409, with the end); the account is `active`, theirs and in use (`payout_account_not_active`, 409); the amount is within available (`insufficient_funds`, 409, with available and shortfall). Then it creates the withdrawal `requested`, posts one balanced `withdrawal` entry (available −X, held +X) tied to it through the money service, and marks the confirmation used; idempotent.
- **FR-010**: The owner (verified gate) MUST be able to cancel a `requested` or `under_review` withdrawal: `cancelled`, one balanced entry held → available; idempotent.
- **FR-011**: Every withdrawal state change MUST pass `withdrawal_transition` in the database (adding `requested → rejected`, the one move the schema lacks); an illegal move answers `illegal_withdrawal_transition` (409). `on_hold_account_change` and `settled` are not reached in this feature.

**Staff**

- **FR-012**: Staff with `withdrawal.release` MUST be able to list withdrawals (staff with only `wallet.view` may read the list and a withdrawal — no action, no export, numbers masked) (filters: state — default requested and under review — held only, Cairo date range, search by display reference, phone or name; oldest first; keyset pages; figures), open one (customer, amount, available, account with full number and its name check, the signals of the Clarifications, history), export the filtered list as CSV (audited), take for review, hold / unhold, release and reject, as in US6. All actions audited and idempotent; the customer is told on hold, release and reject.
- **FR-013**: Release MUST require `under_review`, not held, the account `active` or `removing` and the customer's (a `removing` account becomes `removed` in the same operation when this was its last un-released withdrawal); record the bank transaction number (required), the transfer reference and the value date (optional); post held −X / bank +X; set `release_txn_id`, `released_at`, `reviewed_by`. Reject MUST post held → available.
- **FR-014**: Permissions MUST be catalogue codes seeded once and editable from the Dashboard: `withdrawal.release` (CEO, Finance; never the COO — wallet-touching) and `payout_account.verify` (CEO, Finance, Verification); never by role name.

**Jobs**

- **FR-015**: A scheduled job MUST tell each customer once when their latest pause has ended (system actor, one pause per operation, idempotent); the pause itself ends by time.

**Ledger and reconciliation**

- **FR-016**: For every withdrawal the ledger MUST reconcile: exactly one hold (available −X, held +X); while `requested`/`under_review` nothing else; when `released` exactly one release (held −X, bank +X); when `rejected` or `cancelled` exactly one return (held −X, available +X). Every entry is `event_kind = withdrawal`, goes through the money service, names its actor and the withdrawal; the global ledger stays at zero.
- **FR-017**: The customer's wallet, the staff customer wallet and the Overview MUST show pending withdrawals (the sum held for `requested`/`under_review` withdrawals) separately from held on open orders, keeping the total held.

**Notifications**

- **FR-018**: After commit, by SMS plus email, in the customer's language: account added, verified, refused (reason), made the one in use (with the pause end and any cancelled withdrawals), removed; withdrawal confirmation link (email only); withdrawal held (the message), released, rejected (the reason), cancelled by an account change; withdrawals open again. A failed message never undoes the change.

**Idempotency, audit, actors, isolation**

- **FR-019**: Every customer and staff POST MUST require an `Idempotency-Key` (except the public confirm, which is single-use by its token); every staff action, every account change, every withdrawal state change and every job effect MUST be audited with its actor; `payout_account`, `withdrawal`, `withdrawal_pause` and the confirmations MUST be under forced row-level security (owner = the customer).

**Apps**

- **FR-020**: The Dashboard MUST make the Withdrawals page live (figures, queue, filters, detail, actions and export, each modal in the shared modal component) with a *Payout accounts to check* tab, offer Verify / Refuse in the Customer file, show payout accounts, any open pause and withdrawals in the Customer file, and show pending withdrawals in the wallet panel and on the Overview.
- **FR-021**: The Customer App MUST run Bank accounts, Add a bank account, Your details → Payout account, Withdraw (with the email confirmation) and the link's Confirm page on the API, show pending withdrawals in the wallet, in English and Arabic, with empty and error states.

**Quality and contract**

- **FR-022**: Feature tests through the HTTP boundary MUST cover every transition and refusal, the confirmation (expiry, replacement, reuse, other customer, wrong amount), idempotent replay, the pause, the job (run by its command), permissions (COO refused), isolation and column privacy, concurrency races (release vs cancel, release vs account change, two submissions over the balance) on committed fixtures with two connections, and a reconciliation test over every withdrawal.
- **FR-023**: Every new endpoint MUST carry OpenAPI annotations and a Postman request; the Technical Spec ("Changed by spec 013"), the schema docs, `docs/platform/api-contract.md` and `docs/features/withdrawals.md` MUST be updated in the same change; a local seeder MUST create payout accounts and withdrawals in each reached state through the real Actions.

### Key Entities

- **Payout account**: the customer, bank name, account holder name, account number or IBAN, state (`pending_review`, `active`, `refused`, `removing`, `removed`), in use, checked by / at, activated at, refusal reason / note, created at.
- **Payout-account change**: the history behind *Recent changes* (what happened, to which account, by whom, when).
- **Withdrawal pause**: the customer, opened at, pause until, the account that triggered it, when the customer was told it ended.
- **Withdrawal**: the customer, the payout account, amount, state (`requested`, `under_review`, `released`, `rejected`, `cancelled`; `on_hold_account_change` and `settled` unused), reviewer, hold (reason, message, note, by, at), rejection (reason, note), bank transaction number, transfer reference, value date, the hold / release / return transactions, requested / released / ended at.
- **Withdrawal confirmation**: the customer, amount, account, state (sent, confirmed, used, expired/replaced), expiry; only a hash of the link token is kept.
- **Ledger transactions**: `withdrawal` (hold, release, return), each tied to the withdrawal.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 withdrawals are released to an account that is not verified, `active` or `removing`, and in the customer's own name.
- **SC-002**: 0 withdrawals are created without a confirmed, unused, unexpired email confirmation of the same customer, amount and account.
- **SC-003**: 100% of withdrawals reconcile (one hold; exactly one release or return once final; nothing else) and the global ledger stays at zero.
- **SC-004**: A change of the account in use cancels 100% of the customer's un-released withdrawals and refuses new ones for exactly the configured window.
- **SC-005**: A customer sees the money leave available within 5 seconds of submitting, and back within 5 seconds of a cancel, rejection or account change.
- **SC-006**: Finance sees every withdrawal waiting for review, oldest first, in one view, and every account waiting for a name check in another.
- **SC-007**: 0 customer-facing responses show another customer's data; 0 responses show a full account number to anyone but its owner and staff holding `payout_account.verify` or `withdrawal.release`.

## Assumptions

- Payout accounts and withdrawals live under `/customer/me/*` and `/dashboard/*` (as specs 009–012), not the Technical Spec's bare `/me/*` and `/admin/*` paths; the email link's confirm call is a public route.
- Money leaves Dahab's bank by a person's transfer; the platform records it, it does not call a bank.
- The customer's email was confirmed at registration (spec 001 requires it); changing the email is out of scope.
- A new legal document `payout_account_declaration` v1 carries the prototype's tick text (EN/AR from the terms draft).
- Out of scope: `released → settled`, a bounced transfer, the design's *Transfer file*, the "Stop everything" switch that also stops withdrawals, cap/pattern alerts, open disputes and "weight short" signals, the first-sale advance, removing an account a released withdrawal went to from history (accounts are never deleted).
