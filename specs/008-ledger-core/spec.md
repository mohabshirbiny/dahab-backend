# Feature Specification: Ledger Core

**Feature Branch**: `feature/ledger-core` (backend and dashboard; the Customer App has no repository yet). Spec work runs on the worktree branch `claude/ledger-core-spec-008-e92642`.

**Created**: 2026-09-28

**Status**: Draft

**Input**: User description: "Ledger core (spec 008). Read CLAUDE.md Part 1 and the ledger docs (docs/Database schema/03_schema_ledger.sql, Technical Spec Part 3), then start with /speckit-specify. Follow the multi-project workflow."

## Context

- **Sources**:
  - `docs/Database schema/03_schema_ledger.sql`: accounts, ledger transactions and postings; signed amounts that sum to zero per transaction; append-only; a customer account never goes negative; balances are derived, never stored (customer wallet, account balance, solvency, global zero).
  - `docs/Database schema/01_schema_core.sql`: the fixed list of ledger event kinds (top-up, deposit hold / release / forfeit, settlement, first-sale payout, commission, spread, VAT, balance payment, withdrawal, compensation, external bank movement, weight adjustment, reversal).
  - Technical Spec Part 3 §0: money is exact to 4 decimal places in EGP, rounding is half-up and any residue goes to a Dahab account, never a customer; every movement is one balanced set written by one money service; every movement names a customer or staff actor; scheduled jobs act as the system actor.
  - Part 1 §4.2 and §5.2: seeing wallet balances and statements is a permission seeded to CEO and Finance, not the COO, editable from the Dashboard. Part 1 §5.1: wallet accounts join customer row-level security when their module lands. Part 1 §6: nobody, founders included, can edit or delete the ledger.
  - Part 2 §1: each customer has an available and a held account from registration. Part 2 §8: the customer reads their own available and held figures and their own history.
  - Dashboard design reference, screen "Wallet statement" (Money section) and the Overview "Customer wallets" panel.
  - Customer App prototype: Wallet screen (available, held, total; movement list with type, amount, reference, balance after, note).
- **What exists**: customers, staff and the system actor; settings and pricing (spec 005); the audit log (spec 006); the customer file (spec 007); the shared idempotency layer. There are no accounts, no ledger and no wallet endpoints. The Dashboard's wallet panels and the Customer App's wallet screen run on mock data.
- **Why now**: every later money feature (top-up, buy-request deposits, settlement, withdrawals, compensation, daily close) posts into this ledger. It must exist, and be impossible to unbalance or overdraw, before any of them.
- **Currency**: the ledger holds **EGP only**. A customer holds no gold balance: a piece of gold is a listing (a physical item), not a wallet amount. Gold appears in the ledger only as the EGP value of a settled sale.

## Clarifications

### Session 2026-09-28

- Q: Does this feature ship any action that actually moves money? → A: No — core only: ledger, accounts, money service, balances and reads. Tests post through the money service. The next feature is wallet top-up (transfer notice → Finance match), before buy requests.
- Q: Which Wallet statement views ship? → A: All three, as the design: one customer wallet, all customer wallets together, and the Dahab wallet; every movement / one line per day / one line per month; export. The Overview's "Customer wallets" and safety-figure panels go live too.
- Q: In the customer's history and the one-customer statement, what is the running balance and how does a deposit hold appear? → A: The running balance is **available**. A hold is an "out" and a release is an "in", labelled as held (not spent), with the held balance shown beside it. The closing card shows available, held and their total. (In the all-customer-wallets view the balance is the total owed, so a hold moves nothing there.)
- Q: (found while planning, research R15) The zero-sum rule makes money arriving in the bank post as `bank −X`, but `solvency_check` and Part 2 §8–§9 treat the bank as rising with deposits. Which wins? → A: The zero-sum rule stays (true double entry: negative = debit, positive = credit). The bank's cash is `−SUM(bank lines)`. `solvency_check`, Part 2 §8 (top-up: `bank −amount, cust_available +amount`) and §9 (release: `hold −amount, bank +amount`) are corrected. Screens and the API always show the bank as positive cash.
- Q: (analysis C1) Posting has no HTTP endpoint in this core-only feature, but Principle V asks for HTTP-boundary tests on ledger paths. → A: A temporary written waiver in the PR. Posting is tested at the Action level against real PostgreSQL; no artificial money-moving endpoint. The first real money-moving endpoint (Top-up) adds the HTTP-boundary tests.
- Q: (found while testing) Finding a customer on the Wallet statement needs `customer.view`, which Finance lacked. → A: Finance's seed roles gain `customer.view` (data only; existing installs add it from the Dashboard).
- Q: (analysis I2) The Overview mixes live wallet panels with mock stats. → A: "Held on open orders" becomes live (from the ledger); "Dahab earned this month" is hidden until Settlement is built.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Money can only move in balanced, attributable, permanent sets (Priority: P1)

Every later feature that moves money hands the platform one business event — its kind, who did it, what it is about, and the amounts per account. The platform records it as one permanent entry whose amounts sum to exactly zero, or refuses it whole. Nothing can edit or delete a recorded entry; a mistake is corrected by a new entry that reverses it. A customer's available or held balance can never go below zero.

**Why this priority**: this is the ledger. Without it no money feature can be built, and a single silent imbalance would make every balance in the system untrustworthy.

**Independent Test**: Through the platform's money service (in tests), post a top-up-shaped event (bank +1,000, customer available +1,000) and a hold-shaped event (available −400, held +400); balances read 600 / 400 and the whole-system total is 0. Then attempt an unbalanced set, a set that overdraws the customer, an edit and a delete of a recorded entry, and a set with no actor — each is refused and nothing is recorded.

**Acceptance Scenarios**:

1. **Given** a set of amounts that sums to zero with a named actor and an event kind, **When** it is posted, **Then** one entry with all its lines is recorded atomically, together with the caller's other work in the same operation.
2. **Given** a set that does not sum to zero, **When** it is posted, **Then** it is refused and nothing from the operation is kept.
3. **Given** a set that would take a customer's available or held balance below zero, **When** it is posted, **Then** it is refused as insufficient funds and nothing is kept.
4. **Given** a recorded entry, **When** anyone (founders and direct database users included) tries to change or delete it, **Then** it is refused.
5. **Given** a recorded entry, **When** it is reversed, **Then** a new entry with the opposite amounts is recorded that points to the original; the original is unchanged; the same entry cannot be reversed twice.
6. **Given** any number of recorded entries, **Then** the sum of every line in the system is exactly zero.
7. **Given** amounts with more than 4 decimal places, **Then** they are refused; the caller rounds (half-up to 4 places, residue to a Dahab account) before posting.

---

### User Story 2 - Every customer has a wallet from the start (Priority: P1)

Each customer has two EGP accounts — available (spendable, withdrawable) and held (reserved against a specific open order) — from the moment they register, and existing customers get theirs when this feature is deployed. Dahab has exactly one of each internal account: escrow, commission, spread, VAT payable, bank, and external equity.

**Why this priority**: a deposit hold or a top-up cannot be posted to an account that does not exist; Part 2 §1 requires both accounts before any hold.

**Independent Test**: Register a new customer; they have exactly one available and one held account, both at 0. Run the deployment on a database with existing customers; each gains exactly the two accounts, and running it again adds nothing. Each internal account exists exactly once.

**Acceptance Scenarios**:

1. **Given** a completed registration, **Then** the customer has exactly one available and one held account at 0 EGP, created in the same operation as the customer.
2. **Given** existing customers without accounts, **When** the feature is deployed, **Then** each gets both accounts; re-running creates no duplicates.
3. **Given** the platform, **Then** there is exactly one account of each internal kind and none belongs to a customer; a second one cannot be created.
4. **Given** a customer account, **Then** it always has an owner; an internal account never has one.

---

### User Story 3 - A customer sees their own wallet and history (Priority: P2)

A verified customer opens Wallet in the Customer App and sees their available and held amounts and the total, and the list of their movements, newest first: when, what (in plain words from the event kind), the amount in or out, which balance it touched, the balance after it, and the reference of the order or request it belongs to when there is one. They see only their own money.

**Why this priority**: the customer must be able to trust the number the app shows; it replaces the mock wallet. Lower than US1–US2 because until a money-in feature ships most wallets are empty.

**Independent Test**: For a verified customer with a top-up and a hold posted, the app's Wallet shows available 600, held 400, total 1,000, and two movements with plain labels and correct balances after. A second customer sees none of it. An unverified customer is refused with "verification required".

**Acceptance Scenarios**:

1. **Given** a verified customer, **When** they open Wallet, **Then** they see available, held and total, derived from their ledger lines.
2. **Given** movements on their accounts, **Then** the history lists each, newest first, paged, with time, plain-language label, signed change to available (a hold is out, a release is in, marked as held rather than spent), available balance after, held balance after, and the related reference when one exists.
3. **Given** another customer's movements, **Then** they are never visible or countable — enforced by the database's customer isolation, not only by the app.
4. **Given** a customer who is not verified, **Then** the wallet requests are refused with "verification required"; **Given** a suspended customer, **Then** they can still read their wallet (reading is not trading).
5. **Given** a customer with no movements, **Then** they see 0 / 0 and an empty-state history, not an error.

---

### User Story 4 - CEO and Finance read wallets and the safety figure (Priority: P2)

Staff holding the wallet view permission (seeded to CEO and Finance, not the COO) can open the Wallet statement in three views — one customer's wallet, all customer wallets together, and the Dahab wallet — and see, for a period, opening balance, money in, money out and closing balance for a period, and every movement (or one line per day or per month) with the balance before and after it. The customer file shows the customer's available and held figures to wallet-permitted staff. The headline safety figure — bank balance minus everything owed to customers — and the whole-system check (must be zero) are shown to the same staff, on the Overview's "Customer wallets" and safety-figure panels.

**Why this priority**: Finance must be able to prove any balance from its movements; the founders' most important number is the solvency figure (blueprint). Depends on US1–US2.

**Independent Test**: As Finance, open the statement for a customer with three movements in August; opening, in, out, closing and each row's before/after reconcile. As the COO (seed roles), the statement, the wallet figures on the customer file and the safety figure are not shown and direct requests are refused.

**Acceptance Scenarios**:

1. **Given** a wallet-permitted staff member and a customer, **When** they open that customer's statement for a period, **Then** they see opening balance, total in, total out, closing balance, and each movement with time, label, related reference, before, in, out, after — and the figures reconcile exactly. The running balance is available; holds are outs and releases are ins, marked as held; the closing card shows available, held and total.
2. **Given** the customer file, **When** a wallet-permitted staff member opens it, **Then** it shows available and held; for others the wallet panel is absent (not zero).
3. **Given** a wallet-permitted staff member, **Then** they can see the bank balance, the total owed to customers (available + held), the difference, and the whole-system total, which must read 0.
4. **Given** a staff member without the wallet view permission, **Then** every wallet read is refused and the Money → Wallet statement menu item is hidden; the refusal is recorded in the audit log.
5. **Given** a statement opened for one customer, **Then** the opening is recorded in the audit log (it shows a customer's money).
6. **Given** a movement recorded by a staff member rather than by a customer's own action, **Then** the statement marks it as entered by hand, as the design's amber rows do.
7. **Given** the "all customer wallets" view, **Then** it shows the combined available + held of every customer with each movement naming its wallet; the running balance is the total owed, so a move between available and held changes nothing there, and the closing balance equals the total owed to customers.
8. **Given** the "Dahab wallet" view, **Then** it shows Dahab's earnings (commission and spread accounts) with each movement's source; VAT payable is shown beside it as owed, not earned.
9. **Given** the "one line per day" or "one line per month" grain, **Then** each line totals that day's or month's movements with a count, and its figures equal the sum of the rows it summarises.

---

### Edge Cases

- **Two operations spend the same customer's balance at the same moment** (e.g. two holds): the second is refused as insufficient funds if the first leaves too little; the balance never goes negative and neither succeeds half-way.
- **A caller posts lines to the same account twice in one entry**: allowed; the account's movement is the net of its lines, and the customer's balance after each line is still never negative at commit.
- **A zero-amount line**: refused (every line moves something).
- **Reversing a reversal**: allowed as a new entry pointing to the reversal; each entry is reversed at most once.
- **A reversal that would overdraw the customer** (money already spent): refused as insufficient funds; the operator must resolve it another way.
- **Very large histories**: history and statements are paged and read fast regardless of a customer's number of movements; balances are computed from lines, and stay fast at 1 million lines.
- **A customer registered while the deployment backfills accounts**: ends with exactly two accounts.
- **The system actor** (scheduled jobs) posts like any staff actor; it is never shown as "entered by hand".
- **Deleting a customer**: impossible while they have ledger lines (the ledger keeps its references).
- **Time zone**: all times in Cairo time; statement periods (e.g. "August 2026") are Cairo calendar days.
- **Money in JSON**: always decimal strings with 4 places, never floats.

## Requirements *(mandatory)*

### Functional Requirements

**Accounts**

- **FR-001**: The platform MUST have account kinds: customer available, customer held, escrow, Dahab commission, Dahab spread, VAT payable, bank, external equity. Customer kinds MUST have an owning customer; internal kinds MUST NOT.
- **FR-002**: Each customer MUST have exactly one available and one held account, created atomically with registration. Existing customers MUST receive theirs on deployment, idempotently.
- **FR-003**: Each internal kind MUST exist exactly once.
- **FR-004**: All accounts are EGP; amounts are exact to 4 decimal places.

**Posting**

- **FR-005**: A ledger entry MUST carry an event kind from the fixed list, a named actor (a customer or a staff member, the system actor included), an optional memo, the time, and optional references to what it is about (order, listing, buy request, withdrawal — linked when those features exist). It MUST have at least two lines.
- **FR-006**: Each line MUST name an account and a signed, non-zero amount (positive increases the balance, negative decreases it). The lines of one entry MUST sum to exactly zero; otherwise the whole operation is refused.
- **FR-007**: No customer available or held balance may be negative when the operation completes; otherwise the whole operation is refused with the "insufficient funds" error. Internal accounts may have any sign.
- **FR-008**: Entries and lines MUST be append-only: updates and deletes are refused by the database for everyone.
- **FR-009**: All postings MUST go through one money service that validates the set before writing and writes the entry, its lines, and the caller's audit record and state change in the same operation. No other code writes ledger rows.
- **FR-010**: A reversal MUST be a new entry of kind "reversal" with the negated lines of the original, pointing to it, with a required reason and a staff actor. An entry may be reversed at most once.
- **FR-011**: The whole-system sum of all lines MUST always be zero.

**Balances (derived, never stored)**

- **FR-012**: A balance MUST be the sum of the account's lines. The platform MUST expose: each account's balance; each customer's available and held; bank balance, total owed to customers, and their difference (the safety figure); the whole-system total.

**Customer reads**

- **FR-013**: A verified customer MUST be able to read their own available, held and total, and their own movement history (newest first, paged) — one row per ledger entry that touched their accounts — with time, plain-language label per event kind, signed change to available (hold = out, release = in, marked as held), available after, held after, and related reference when one exists. Unverified customers get "verification required"; suspended customers may still read.
- **FR-014**: Customer accounts, entries and lines MUST join the customer row-level isolation: in a customer context only the customer's own accounts and lines are visible; customers can never write ledger rows directly.

**Staff reads**

- **FR-015**: A new permission "View wallets and statements" MUST gate every staff wallet read; seeded to CEO (as for every code) and Finance, not the COO; editable from the Dashboard.
- **FR-016**: Wallet-permitted staff MUST be able to open a statement in three views — one customer's wallet (available + held), all customer wallets together, and the Dahab wallet (commission + spread, with VAT payable shown separately) — for a chosen period (month, last 90 days, custom dates) and grain (every movement, one line per day, one line per month), showing opening, in, out, closing, and each movement with before/in/out/after (one customer: on available, holds as out and releases as in, with held beside it; all customers: on the total owed; Dahab: on commission + spread), related reference, and whether it was entered by a staff member ("by hand") — and export it to a spreadsheet.
- **FR-017**: The customer file MUST show available and held to wallet-permitted staff and omit the panel for others.
- **FR-018**: Wallet-permitted staff MUST see the safety figure (bank, owed to customers, difference) and the whole-system check, and the Overview's "Customer wallets" panel (available, held on open orders, total owed) and its "Held on open orders" stat MUST show live figures. The "Dahab earned this month" stat MUST be hidden until Settlement is built. Other Overview panels stay as they are.
- **FR-019**: Opening a customer's statement MUST write one audit entry. A refused wallet read MUST be refused as "permission denied" and recorded in the audit log as a permission denial naming the wallet permission.

**Contract**

- **FR-020**: Money in every response MUST be a decimal string with 4 places. Event kinds MUST have a stable code plus a plain-language label: staff wording from the API, and customer wording localised by the Customer App (en/ar) from the code.

### Key Entities

- **Account**: a bucket money sits in; kind, owning customer (customer kinds only), currency EGP, created time.
- **Ledger entry** (ledger transaction): one business event; event kind, actor (customer or staff), memo, time, optional references (order, listing, buy request, withdrawal), optional reversed entry.
- **Ledger line** (posting): one signed amount on one account within one entry.
- **Event kind** (existing fixed list): code, customer label, staff label.
- **Derived figures**: account balance, customer wallet (available, held), safety figure (bank, owed, headroom), whole-system total.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 cases in tests where an unbalanced, overdrawing, actor-less or edited/deleted ledger entry is kept — each is refused with nothing written.
- **SC-002**: The whole-system total is exactly 0 after every test that posts money.
- **SC-003**: 100% of customers — new and existing — have exactly one available and one held account after deployment.
- **SC-004**: A customer's wallet and first page of history load in under 1 second with 10,000 movements; a staff statement for one month loads in under 2 seconds with 1,000,000 lines in the system.
- **SC-005**: 0 cases in tests where a customer sees another customer's accounts or lines, or a staff member without the wallet permission sees any balance.
- **SC-006**: Every statement reconciles: opening + in − out = closing, and each row's before + in − out = after, to the piastre, on the view's running balance (available for one customer, total owed for all customers, commission + spread for Dahab).

## Assumptions

- The ledger is EGP only; no gold balances (see Context).
- Customer-facing labels follow the Customer App prototype wording (e.g. "Top-up", "Hold placed", "Hold released", "Sale settled", "Withdrawal"); staff labels follow the Dashboard design.
- The design's "Rows shaded amber were entered by hand" = entries whose actor is a staff member other than the system actor.
- Withdrawals' pending hold (Part 2 §8) is designed with the withdrawal feature; this feature adds no account kind for it.
- **No action in this feature moves real money** (clarified): top-up, daily close, bank movements, compensation, transfer matching, withdrawals and settlement are their own features; they post through this feature's money service.
- Posting is platform-internal: there is no generic "post an entry" endpoint and no staff reversal endpoint yet; reversal is a money-service capability later features expose.
- The "Dahab wallet" in the design is Dahab's earnings: the commission and spread accounts. Escrow, bank and external equity are not part of it (escrow nets to zero per settlement; bank and equity appear in the safety figure and the future bank book).
- Wallet statements are read-only; exports reuse the Dashboard's existing spreadsheet export.
- Times are Cairo time; statement periods are Cairo calendar days.
- The bank is the only asset account: its ledger balance is the negative of the cash it holds, and every figure shown to staff is the cash (positive). Customer, Dahab and VAT accounts show their balance as is.
