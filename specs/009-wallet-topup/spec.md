# Feature Specification: Wallet Top-up

**Feature Branch**: `feature/wallet-topup` (backend and dashboard; the Customer App has no repository yet). Spec work runs on the worktree branch `claude/wallet-topup-spec-009-1981ef`.

**Created**: 2026-09-29

**Status**: Draft

**Input**: User description: "Wallet Top-up (spec 009): manual-transfer top-up. Customer picks bank transfer / InstaPay / Vodafone Cash, sees Dahab's receiving details and a personal reference code, optionally attaches a receipt, taps "I've sent the transfer". CEO/Finance match it in the Dashboard "Incoming transfers" screen (by reference or by hand) and the wallet is credited (event_kind = topup; bank −X, cust_available +X). Dahab's receiving accounts are managed from the Dashboard. First money-moving endpoint: HTTP-boundary posting tests end the spec 008 Principle V waiver; Idempotency-Key on every money POST. Top-up requires a verified customer; suspended customers blocked if trade-gated. Wallet-touching staff permissions never go to COO by default."

## Context

- **Sources**:
  - Technical Spec Part 2 §8 `POST /me/wallet/topup`: adds funds with event kind `topup`, bank −amount and customer available +amount (sign corrected by spec 008, research R15; the bank's cash is −SUM(bank)); gate `trade_allowed`; idempotent; not audited.
  - Part 2 §9 `POST /admin/transfers/match`: match an incoming transfer to a customer top-up; permission *Match an incoming transfer*; audited; idempotent.
  - Part 1 §2.2: **wallet top-up requires verification**; a suspended customer can read their own data but every trade action is refused (`account_suspended`). Part 1 §4.2: *Match an incoming transfer* is CEO and Finance; the COO is excluded from every wallet-touching action.
  - Part 3 §11: "Money in only from an account in their own name (top-up matching)" — **changed by this feature** (product-owner decision, below).
  - Customer App: `AddFundsScreen` (`lib/features/wallet/wallet_screens.dart`) and the top-up methods in `lib/mock/mock_wallet.dart` — amount with quick picks (5,000 / 20,000 / 50,000), three methods (bank transfer, InstaPay, Vodafone Cash), a per-method fee line ("charged by the provider, not by Dahab"), the receiving details with a personal reference (e.g. `MONA-4417`) and a daily limit for InstaPay/Vodafone Cash, "Use the reference so your transfer is matched automatically", an optional screenshot/receipt ("needed if you transferred without it"), and "I've sent the transfer" → "We will match your transfer and add it to your wallet. You will get a notification when it lands."
  - Dashboard design, "Incoming transfers" (Money section): "Money coming in from banks and wallets. Matched automatically by reference, or by hand when the reference is missing." An Unmatched list (received, amount, from, reference, best guess) with actions *Credit wallet*, *Match by hand*, *Investigate*, *Hold* and *Sender ID*; date filters, search and export. The Wallet statement shows the credit as "Added by bank transfer — matched by reference".
- **What exists**: the ledger core (spec 008) with `PostLedgerEntryAction` (posts inside the caller's transaction), the customer wallet and history, the Wallet statement; the customer verified gate (spec 002); the private uploads store for identity documents; the shared `Idempotency-Key` layer (spec 007); the audit log (spec 006). No endpoint moves money yet. Dahab has no bank or wallet-provider feed: Dahab learns about incoming money only by staff checking Dahab's own bank and wallet apps.
- **Why now**: the first way money enters the platform. Buy-request deposits (the next module) need customers to hold funds.
- **Product-owner decisions given with the request**:
  - Top-up is a **manual transfer**, never a payment gateway.
  - **The sender's name does not have to be proven.** A receipt showing the customer's name is enough; staff decide. This replaces Part 3 §11's "money in only from an account in their own name" as a system rule.
  - Dahab's receiving accounts are managed from the Dashboard.
  - Every money POST uses the shared `Idempotency-Key` layer.
  - Wallet-touching staff permissions are never seeded to the COO.

## Clarifications

### Session 2026-09-29

- Q: Besides being credited, how can a pending transfer notice end? (docs name only "match"; the Dashboard design adds *Hold* / *Investigate*) → A: Staff can put a notice **on hold** (with a note) or **reject** it (reason from a fixed list + note); the customer can **cancel** their own notice while it is pending. Statuses: pending, on hold, credited, rejected, cancelled. No automatic expiry.
- Q: When the amount that reached Dahab differs from the amount on the notice, what can staff do? → A: Credit what actually arrived (any amount above zero); the claimed amount stays beside it; a note is required when the two differ. One notice gets exactly one credit (no partial credits).
- Q: Is the customer notified when a notice is credited or rejected, and how? → A: Yes, on credited (with the amount) and on rejected (with the plain reason), by SMS plus email when the customer has one — like the verification messages; sent only after the change is committed; no message on hold or cancel.
- Q: What does the customer's personal top-up reference look like? → A: A fixed prefix plus the customer's existing unique display reference, e.g. `DAHAB-004417`; staff search accepts it with or without the prefix. No new code is generated.
- Q: (after analysis) Which customers may be credited by hand? → A: Verified and active — yes. Verified and suspended — only as staff-side reconciliation of money that has already arrived, with the provider's transaction reference recorded and the same audit, idempotency and money-safety rules; the suspended customer still cannot see receiving details or start a top-up. Awaiting verification or rejected — never.
- Q: (second analysis, M1) Does matching a suspended customer's notice also need the transaction reference? → A: Yes. Whenever the customer is suspended at credit time, both matching a notice and crediting by hand require the provider's transaction reference for the arrival.
- Q: (second analysis, L2) May a suspended customer view and cancel their own pending notices? → A: Yes — viewing their notices and cancelling a pending one are allowed. They stay blocked from the receiving details, creating notices and uploading receipts.
- Q: (during user testing, 2026-09-30) The notice shows what the customer sent (10,000) while Add funds told them 9,900 would arrive after Vodafone's 1%. How should the fee show up afterwards? → A: Keep the claim = what they sent. Also show a display-only estimate everywhere after that: `expected_amount` = claim minus the provider fee shown on the account they sent to (half-up to piastres; null with no fee or once closed). The Dashboard pre-fills the match with it and a pre-written fee note (the note is still required when the credited amount differs from the claim); the customer sees "about X reaches your wallet". Staff still credit what actually arrives.
- Q: (2026-09-30) What if InstaPay's or Vodafone Cash's fee changes after a notice was filed? → A: The notice keeps the fee its account showed when it was filed (`notice_fee_percent`, a snapshot frozen with the identity columns); `expected_amount` uses that snapshot, so a later fee change never moves an existing notice's estimate (open notices filed before the snapshot existed took their account's fee at migration). Credits are unchanged: staff credit only what actually arrived.
- Q: Are the per-method daily limits (InstaPay 70,000, Vodafone Cash 60,000) display only or enforced? → A: Display only — an optional field on the receiving account shown to customers; notice amounts are not checked against it.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A customer tells Dahab they have sent money (Priority: P1)

A verified customer opens *Add funds*, enters an amount, picks bank transfer, InstaPay or Vodafone Cash, and sees where to send the money: Dahab's receiving details for that method and the customer's own reference code, with the provider's fee shown as the provider's, not Dahab's. They make the transfer in their bank or wallet app, optionally attach a screenshot or receipt, and tap *I've sent the transfer*. Dahab records a transfer notice waiting to be matched, and the customer sees it as pending.

**Why this priority**: without the notice, staff have nothing to match against and the customer has no trace of the money they sent.

**Independent Test**: As a verified customer, read the top-up methods (receiving details + personal reference), submit a notice for 20,000 EGP by InstaPay with a receipt, and see it listed as pending; the wallet balance is unchanged. Repeat as an unverified customer and as a suspended customer — both are refused and nothing is recorded.

**Acceptance Scenarios**:

1. **Given** a verified, non-suspended customer, **When** they open Add funds, **Then** they see each active method with Dahab's receiving details, the method's daily limit where one is set, and their personal reference code.
2. **Given** that customer, **When** they submit a notice with an amount, the receiving account they sent to (which sets the method) and optionally a receipt, **Then** a pending notice is recorded against them, carrying their reference, and no money moves.
3. **Given** a customer awaiting verification or rejected, **When** they try to read the methods or submit a notice, **Then** they are refused as verification required.
4. **Given** a suspended customer, **When** they try to read the methods or submit a notice, **Then** they are refused as account suspended.
5. **Given** the same submission is sent twice with the same idempotency key, **Then** exactly one notice exists and both responses are the same.
6. **Given** a customer with notices, **When** they open their top-up history, **Then** they see each of their own notices with amount, method, date and status (pending, on hold, credited, rejected with its reason, or cancelled) — never another customer's.
7. **Given** a pending notice of their own (also when the customer is now suspended), **When** the customer cancels it, **Then** it becomes cancelled, no money moves, and it can no longer be matched; a notice that is on hold, credited, rejected or already cancelled cannot be cancelled.

---

### User Story 2 - CEO or Finance match a transfer and the wallet is credited (Priority: P1)

A CEO or Finance staff member opens *Incoming transfers*. They see pending notices (amount, method, customer, reference, date, receipt). After seeing the money in Dahab's own bank or wallet app, they confirm the match: the amount actually received and which Dahab receiving account it arrived in. The customer's available balance rises by that amount in one permanent ledger entry, the notice becomes credited, and the action is in the audit log with their name.

**Why this priority**: this is the only step that moves money; without it top-up delivers nothing.

**Independent Test**: With a pending 20,000 EGP notice, a Finance user matches it for 20,000 EGP; the customer's available balance rises by 20,000, the bank's cash rises by 20,000, the whole-system total stays zero, the customer's history shows a top-up, and the audit log shows the match. A COO (default roles) is refused.

**Acceptance Scenarios**:

1. **Given** a pending notice, **When** a staff member with the match permission confirms it with the received amount and receiving account, **Then** in one operation: a `topup` ledger entry (bank −amount, customer available +amount) is recorded naming the staff member as actor, the notice becomes credited and points to that entry, and the match is audited.
2. **Given** a credited notice, **When** anyone tries to match it again (same or different key, concurrently or later), **Then** it is refused and the wallet is credited only once.
3. **Given** the same match request sent twice with the same idempotency key, **Then** one credit exists and both responses are the same.
4. **Given** a staff member without the match permission (e.g. the COO on default roles), **When** they open Incoming transfers or try to match, **Then** they are refused.
5. **Given** a match whose ledger posting fails, **Then** nothing is kept: the notice stays pending and no audit row claims a credit.
6. **Given** the customer became suspended after sending the notice, **When** staff match it with the provider's transaction reference for the arrival, **Then** the money they already sent is still credited (their money is Dahab's liability; they can withdraw it once withdrawals exist) and the audit row records that the customer was suspended; without the transaction reference the match is refused as a validation error and nothing changes.
7. **Given** a pending notice whose money cannot yet be confirmed (the design's *Investigate* / *Hold*), **When** a staff member puts it on hold with a note, **Then** it shows as on hold to staff and customer, stays matchable, and can be returned to pending; the action is audited.
8. **Given** a pending or on-hold notice whose money never arrived (or that duplicates another notice), **When** a staff member rejects it with a reason from the fixed list and a note, **Then** it becomes rejected, no money moves, it can never be matched, the customer sees the reason (never the note), and the action is audited.
9. **Given** a notice the customer cancelled, **When** staff try to match, hold or reject it, **Then** they are refused; money for it that arrives anyway is credited by hand (Story 3).

---

### User Story 3 - Staff credit money that arrived without a notice ("match by hand") (Priority: P2)

Money sometimes arrives with no notice (the customer forgot to tap the button) or without the reference. A staff member finds the customer by reference code or phone number, checks the receipt/sender details they have, and credits the wallet directly, recording the amount, the receiving account the money reached (which sets the method) and a note on how they identified the customer. The result is the same credit and audit trail as Story 2.

**Why this priority**: the design treats "by hand" as a normal path; without it money that arrived is stuck outside the wallet.

**Independent Test**: With no notice, a Finance user credits 15,000 EGP by InstaPay to a customer found by phone, with a note; the wallet rises by 15,000, a credited transfer record exists with the note, and the audit log shows a hand match.

**Acceptance Scenarios**:

1. **Given** a customer found by reference or phone, **When** a staff member credits a transfer by hand with the amount, the receiving account (which sets the method) and a required note, **Then** a credited transfer record and a `topup` ledger entry are created together and the action is audited as a hand match.
2. **Given** a customer awaiting verification or rejected, **When** staff try to credit by hand, **Then** they are refused as verification required and nothing is written (top-up requires verification; such money is returned outside the app).
3. **Given** a verified customer who is now suspended and money from them that has already reached a Dahab account, **When** staff credit it by hand with the amount, the receiving account, the provider's transaction reference for the arrival and a note, **Then** it is credited with the same ledger, audit and idempotency rules, and the audit row records that the customer was suspended. Without the transaction reference it is refused as a validation error.
4. **Given** a suspended customer, **When** they open Add funds or try to upload a receipt or submit a notice, **Then** they are refused as account suspended — the staff-side allowance never opens the customer side.

---

### User Story 4 - CEO or Finance manage Dahab's receiving accounts (Priority: P2)

From a settings-like Dashboard page, CEO or Finance add, edit, deactivate and reorder the receiving accounts customers see: for each, the method (bank transfer, InstaPay, Vodafone Cash), the details shown to customers (bank name, account holder, account number or IBAN; InstaPay address; wallet number), an optional daily limit to display, and the provider-fee note. Customers only see active accounts. Every change is audited.

**Why this priority**: customers need real details to send to; wrong details send money to the wrong place, so changes must be controlled and traceable. It is P2 in build order (stories 1–2 can be built and tested with test accounts), but it **blocks production release**: the seeded accounts are fake and local-only, so real top-ups cannot start until staff can enter Dahab's real accounts from the Dashboard.

**Independent Test**: A Finance user adds an InstaPay account, a customer sees it in Add funds, Finance deactivates it, the customer no longer sees it; the audit log shows both changes with before/after values. A COO is refused.

**Acceptance Scenarios**:

1. **Given** a staff member with the receiving-accounts permission, **When** they add or edit an account, **Then** customers see the new details immediately and the change is audited with before/after values.
2. **Given** an account that credited notices point to, **When** it is deactivated, **Then** customers no longer see it, and the past records still show which account the money arrived in (accounts are never deleted).
3. **Given** a method with no active account, **Then** customers do not see that method.

---

### Edge Cases

- **Amount received differs from the notice** (the provider's fee came off, or the customer sent more/less): the wallet is credited with what arrived, any amount above zero; the notice keeps the claimed amount beside the credited one, and staff must write a note explaining the difference.
- **One transfer, two notices** (customer tapped twice with different keys): staff credit one and reject the other as `duplicate_notice` (or the customer cancels it).
- **One notice, two transfers** (customer split the payment): the notice is credited once, for the first transfer; the second transfer is credited by hand (Story 3). There are no partial credits.
- **Money that never arrives**: the notice stays pending (no automatic expiry) until staff reject it or the customer cancels it.
- **Reference matches but the sender is someone else** (design's "Hold"): staff put the notice on hold while they check; per the product owner a receipt with the customer's name is enough to credit it.
- **Customer cancels while staff are matching**: exactly one wins; a cancelled notice is never credited, and a credited notice cannot be cancelled.
- **A rejected notice whose money turns up later**: the rejected notice stays rejected; staff credit the money by hand (Story 3), referring to it in the note.
- **Two staff match the same notice at the same moment**: exactly one succeeds; the other is refused as already matched.
- **Receipt too large or not an image/PDF**: refused with a validation error before anything is recorded.
- **Receiving account deactivated while a customer is on the screen**: submitting a notice for that account is refused as a validation error; the customer re-picks from the refreshed list. Money already sent there is still matched by staff, who record the account it actually reached (inactive accounts remain selectable for staff).
- **A verified customer later rejected on re-review**: their pending notices stay matchable; money they sent is theirs.
- **Customer's reference used on a transfer from another person's account**: allowed to be credited to the reference holder on staff judgement (product-owner decision), always audited.
- **Idempotency key reused with a different body**: refused by the shared idempotency layer.

## Requirements *(mandatory)*

### Functional Requirements

**Receiving accounts and methods**

- **FR-001**: The system MUST offer exactly three top-up methods: bank transfer, InstaPay and Vodafone Cash. No payment gateway is involved.
- **FR-002**: Staff with the receiving-accounts permission MUST be able to create, edit, activate/deactivate and order Dahab's receiving accounts; each has a method, the details customers copy, an optional display daily limit, an optional customer-facing note, and an active flag. Accounts are never deleted.
- **FR-003**: Every receiving-account change MUST be audited with actor and before/after values.
- **FR-004**: A customer MUST see only active receiving accounts, grouped by method, plus their personal reference code; a method with no active account is not offered.

**Personal reference code**

- **FR-005**: A customer's top-up reference MUST be the fixed prefix `DAHAB-` followed by their existing display reference (e.g. `DAHAB-004417`). It is unique and stable because the display reference is; no separate code is stored. A notice records the reference shown at submission.
- **FR-006**: Staff MUST be able to find a customer and their notices in Incoming transfers by the reference with or without the prefix (case- and space-insensitive), as well as by phone or name.

**Transfer notice (customer)**

- **FR-007**: A customer who passes the trade gate (verified and not suspended) MUST be able to submit a transfer notice with an amount in EGP (greater than zero, at most 2 decimal places), the active receiving account they sent to (which sets the method), and optionally one receipt image or PDF.
- **FR-008**: Customers awaiting verification or rejected MUST be refused with `verification_required`; suspended customers MUST be refused with `account_suspended`. The same gate applies to reading the top-up methods.
- **FR-009**: Submitting a notice MUST require an idempotency key; a replay returns the original result without creating a second notice.
- **FR-010**: Submitting a notice MUST NOT move money. The notice is recorded as pending with the customer's reference, the claimed amount, the method, the receipt if any, and the submission time.
- **FR-011**: A receipt MUST be stored privately and encrypted (the same private uploads store as identity documents). Only staff with the match permission can open it; customers see only whether their notice has a receipt (no customer endpoint returns the file).
- **FR-012**: A customer MUST be able to list their own notices, newest first, with amount claimed, amount credited (when credited), method, date and status; row-level isolation MUST prevent seeing another customer's notices.

**Incoming transfers (staff)**

- **FR-013**: A new staff permission *Match an incoming transfer* MUST gate the Incoming transfers list, notice details, receipt viewing, matching and crediting by hand. It is seeded to CEO and Finance only, never the COO; role managers may change it from the Dashboard (spec 002).
- **FR-014**: A new staff permission to manage receiving accounts MUST gate FR-002; seeded to CEO and Finance only, never the COO.
- **FR-015**: The Incoming transfers list MUST show notices filtered by status (default: pending and on hold) and date range, searchable by customer name, phone or reference, with the customer, claimed amount, method, reference, submission time and whether a receipt is attached; it MUST be exportable.
- **FR-016**: Matching a pending notice MUST record, in one all-or-nothing operation: the amount actually received, the receiving account it arrived in, an optional note, a `topup` ledger entry through the money service (bank −amount, customer available +amount, actor = the staff member, reference = the notice), the notice's move to credited with a link to that ledger entry, and an audit row. When the customer is suspended at match time, the provider's transaction reference for the arrival is also required and the audit row records the suspension (same rule as FR-018).
- **FR-017**: A notice MUST be credited at most once, enforced by the data (not only by application checks), including under concurrent matches.
- **FR-018**: Crediting by hand MUST create a credited transfer record (no prior notice) for a chosen customer with the amount, the receiving account (which sets the method) and a required note, together with the same ledger entry, audit row, idempotency and money-safety rules as FR-016, audited as a hand match. Who may be credited by hand:
  - **verified and active** — allowed;
  - **verified and suspended** (suspended from the active state) — allowed only as staff-side reconciliation of money that has already arrived: staff MUST also record the provider's transaction reference for the arrival (the bank/InstaPay/Vodafone Cash reference from Dahab's statement), and the audit row records that the customer was suspended;
  - **awaiting verification or rejected** (including suspended from those states) — refused with `verification_required`; nothing is written.
- **FR-018a**: Crediting a suspended customer (by matching or by hand) is staff-side reconciliation only. A suspended customer MUST NOT be able to read the receiving details or their reference, upload a top-up receipt, or submit a notice (FR-008); nothing a customer can read while suspended exposes Dahab's receiving details. A suspended customer MAY list their own notices and cancel a pending one (FR-012, FR-025b) — neither moves money nor shows receiving details.
- **FR-019**: Every staff money action (match, credit by hand) MUST require an idempotency key.
- **FR-020**: The credited amount MUST be what actually arrived (greater than zero, at most 2 decimal places, with no tolerance against the claim); the claimed amount on the notice is kept unchanged beside it. When the two differ, a note is required. A notice is credited exactly once; there are no partial credits.
- **FR-021**: The credit MUST show as a top-up everywhere it appears: the customer's wallet history shows it with its top-up number (`TOP-n`) as the reference; the customer's top-up list shows the method; the Wallet statement shows the number, the method and whether it was matched from a notice or credited by hand (the design's "Added by bank transfer — matched by reference / by hand").
- **FR-022**: The system MUST NOT check the sender's name automatically; staff decide on the evidence (receipt, reference, phone). This replaces Part 3 §11's "money in only from an account in their own name" for top-ups, and `docs/` MUST be updated.

**Quality and contract**

- **FR-023**: The notice submission, the match and the credit-by-hand MUST each be covered by feature tests through the HTTP boundary that assert on the persisted notice, the ledger entry and postings, the resulting balances, the audit row, idempotent replay, and refusal paths (gate, permission, double match). This ends the spec 008 Principle V waiver.
- **FR-024**: Every new endpoint MUST carry OpenAPI annotations and a Postman request; Technical Spec Part 2 §8–§9, Part 3 §11 and the ledger/security schema docs MUST be updated in the same change.

**Notice lifecycle, notifications and limits (clarified 2026-09-29)**

- **FR-025**: A notice MUST have exactly one of the statuses pending, on hold, credited, rejected, cancelled. Allowed moves: pending → on hold / credited / rejected / cancelled; on hold → pending / credited / rejected. Credited, rejected and cancelled are final. Every other move MUST be refused with a conflict error, and moves MUST be safe under concurrency (one wins).
- **FR-025a**: Staff with the match permission MUST be able to put a notice on hold (note required) and return it to pending, and reject a pending or on-hold notice with a reason from a fixed list (`money_not_received`, `duplicate_notice`, `sender_not_accepted`, `other`) and a required note. Hold, un-hold and reject require an idempotency key and are audited. The customer sees the reason code, never the note.
- **FR-025b**: The customer MUST be able to cancel their own pending notice (idempotency key required); a cancelled notice is never matched. There is no automatic expiry.
- **FR-026**: When a transfer is credited (from a notice or by hand) the customer MUST be sent a message with the credited amount; when a notice is rejected, a message with the plain reason (never the staff note). Channel: SMS, plus email when the customer has one, in their preferred language. Messages are sent in the background only after the change is committed; a failed message never undoes the credit. No message on hold, un-hold or cancel.
- **FR-027**: A receiving account's daily limit MUST be display-only: shown to customers with the account's details, never checked against notice or credit amounts. Dahab enforces no minimum or maximum top-up beyond FR-007.

### Key Entities

- **Receiving account**: one Dahab account customers send money to — method, display details (bank name / account holder / account number or IBAN; InstaPay address; wallet number), optional daily limit, optional note, sort order, active flag, who changed it last. Never deleted.
- **Top-up reference**: `DAHAB-` + the customer's existing display reference; derived, not stored separately.
- **Transfer notice** (top-up): one claim or record of money sent to Dahab — customer, method, claimed amount, optional receipt, reference, status (pending, on hold, credited, rejected, cancelled — FR-025), the hold note, the reject reason and note, who acted and when, and once credited: the received amount, the receiving account, the staff member, the time, a note, the provider's transaction reference for the arrival (required for a by-hand credit to a suspended customer), whether it was matched from a notice or by hand, and the ledger entry it produced (at most one).
- **Ledger entry** (existing, spec 008): the `topup` entry the match posts — bank −amount, customer available +amount.
- **Receipt** (existing uploads store): a private image/PDF attached to a notice.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A verified customer can go from opening Add funds to a submitted notice in under 2 minutes, not counting the time spent in their bank app.
- **SC-002**: A Finance user can match a pending notice in under 30 seconds from opening Incoming transfers.
- **SC-003**: 100% of credits appear in the customer's wallet and history as soon as the match is confirmed, and the whole-ledger total stays exactly zero after every credit.
- **SC-004**: 0 notices are ever credited twice, including under repeated or simultaneous match attempts.
- **SC-005**: 100% of matches, hand credits and receiving-account changes appear in the audit log with the staff member's name.
- **SC-006**: 0 customers who are unverified or suspended can create a notice; 0 staff without the match permission (including the COO on default roles) can credit a wallet.

## Assumptions

- The customer's gate is **trade** (verified and not suspended), per Part 1 §2.2 and Part 2 §8 `trade_allowed`: a suspended customer may still read their wallet (spec 008) but cannot start a top-up.
- The provider's fee is the customer's cost, shown for information only (as the prototype says); Dahab takes nothing and posts only what arrived. The fee percentages shown in the prototype are display text on the receiving account, not calculated by Dahab.
- Staff confirm arrivals by looking at Dahab's own bank and wallet apps; there is no automatic bank feed, so "matched automatically by reference" in the design means staff find the notice by its reference, not that the system matches without a person.
- The credit posts `bank −amount` for every method: InstaPay and Vodafone Cash money reach the same Dahab `bank` account in the ledger (spec 008 has one bank account). The receiving account on the record says where it physically arrived.
- Viewing a receipt is not separately audited (it is not an identity document); the match itself is audited.
- Mistaken credits are corrected by a reversal through the existing money service by a later feature (wallet adjustment / correction); this feature ships no reversal endpoint.
- Seed data provides one receiving account per method for local development only; real details are entered from the Dashboard (never committed).
- Receipts reuse the private uploads store (as the product owner asked): the identity image types (JPEG, PNG, WebP) plus PDF, which bank apps export, with the same size limit as identity uploads.
