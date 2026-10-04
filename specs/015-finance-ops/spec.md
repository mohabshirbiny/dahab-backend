# Feature Specification: Finance operations and order export

**Feature Branch**: `feature/finance-ops` in all three repositories — backend worktree `spec-015-finance-ops-f96ac1` (from `main` at `8fe25f7`), dashboard worktree `.claude/worktrees/finance-ops` (from `main` at `c4e90f2`), Customer App worktree `.claude/worktrees/finance-ops` (from `main` at `2b66253`, github.com/mohabshirbiny/dahab-flutter).

**Created**: 2026-10-04

**Status**: Draft (clarified)

**Input**: User description: "Spec 015 — Finance operations and order export — all three projects. (1) Compensation page (p-compensation): every compensation paid, filters, totals, the per-person caps and what is left today; decide whether staff can pay outside a dispute and under which permission. (2) Wallet adjustment: a staff correction to a customer's wallet with a reason, a balanced entry through the money service — account pair, limits and permission from the docs. (3) Bank movements (p-bankbook): the bank account's movements as the ledger records them, with matching status and export. (4) Daily close (p-closing): what the docs define for closing a day, recorded and audited. (5) The real Overview: every remaining mock figure from Backend data. (6) Order export: CSV of the Orders list with the current filters, audited. (7) Held money per order and per buy request for the Customer App's Held screen, from the ledger. (8) Ask whether this spec adds a public customer endpoint for gold prices and the 'what will I get' quote; if yes wire the rate strip, home calculator, splash rate cards and sell-form estimate and remove their MOCK flags."

## Context

- **Sources**:
  - Technical Spec Part 1 §4.2 (Money matrix): *Pay compensation to a wallet* — CEO, Finance up to cap; *Adjust a wallet balance directly* — **CEO only**; *Record a bank movement outside the app* — CEO, Finance; *Close the day* — CEO, Finance; *View a wallet balance* / *Open a wallet statement* — CEO, Finance; the COO on none of them. §8.3: both founders are notified of a wallet adjustment — channel still **OI-1.3** (open).
  - Technical Spec Part 2 §9: `POST /admin/compensation` (built by spec 014 only inside a dispute resolve; ledger `external_equity −amount`, `cust_available +amount`); `POST /admin/wallet-adjustment` ("the narrowest, most sensitive money action"; reason and idempotency required; "a reversible `compensation`/`reversal`-kind transaction; never an in-place balance edit"); `POST /admin/bank-movements` (`kind` `capital_in | rent | bank_charge | profit_draw`, `amount`, `occurred_on`, `reason`, `proof_ref`; ledger `external_equity ↔ bank`, `event_kind = external_bank_movement`); `POST /admin/daily-close` ("snapshot bank balance, customer liability, Dahab wallet, and the difference; lock when clean"; `daily_close_no_reopen` blocks reopening a locked day; reads `solvency_check`).
  - Schema `05_schema_security.sql` §17: `bank_movement` (kind, signed amount, `occurred_on`, reason, `proof_ref`, `recorded_by`, `ledger_txn_id`) and `daily_close` (`close_date` key, `bank_balance`, `customer_liability`, `dahab_wallet`, `difference`, `is_locked`, `closed_by`, `closed_at`; a locked row can never change). The MySQL rendering adds the kinds `salary | vat_remittance | other`, a non-zero check and a `notes` column. `compensation` (spec 014) has **`dispute_id` and `order_id` NOT NULL**. `01_schema_core.sql`: ledger kinds `compensation`, `external_bank_movement`, `reversal`; the account `external_equity` ("capital in / profit out / rent / bank charges"); settings `compensation.cap_per_payment_egp` = 2000, `compensation.cap_per_day_egp` = 5000. `03_schema_ledger.sql`: the bank is the only asset account (cash = −SUM(bank)); `solvency_check`; `ledger_global_zero`.
  - Dashboard design: `p-overview` (safety figure; *Held on open orders*; *Paid out ahead of buyers*; *Dahab earned this month* — commission and spread; *Transactions, last 30 days* by order status with count, value and held now, Export; *Needs a decision*, oldest first, with who it is on; Customer wallets; Gold price now; *This month* — new sellers, pieces listed, sold, sell-through, *Money reaches sellers in N days*). `p-compensation` ("Pays money into a customer wallet outside the normal flow… appears on their statement as compensation from Dahab"; form: wallet, amount, the five reasons, note, approved by, *Your remaining limit today*; *Paid out N EGP this month*; list *When · Wallet · Amount · Reason · By*, period filter, Export). `p-bankbook` ("Money that moves in or out of the Dahab bank account without going through the app"; kinds *Capital paid in, Operating expense, Bank charge, Profit taken out, Transfer between our own accounts, Refund from a supplier, Something else*; amount, in or out, date on the statement, what it was for, proof; list *Date · What · In · Out · By*, period filter, Export). `p-closing` (today's difference; days closed this month; last difference found; bank balance at close, customer wallets available and held, Dahab wallet earned and not withdrawn, movements outside the app today, difference; *Close the day* — "it is locked, so tomorrow starts from a number you have already checked"; recent days *Day · Bank · Books · Difference · Closed by* with an explanation line).
  - Customer prototype: `#s-held` (what is held and why); the wallet history lines; the rate strip, home calculator, splash rate cards and the sell form's estimate.
- **What exists** (specs 001–014): the double-entry ledger and money service (post, reverse), the Overview's safety figure and wallets panel (`/dashboard/wallets/overview`), the Wallet statement with CSV export (view `customer | customers | dahab`), top-ups (matched credits from the bank), withdrawals (released to the bank) with CSV export, orders with groups and counts, compensation paid inside a dispute resolve (`compensation.pay`, `compensation.uncapped`, the per-payer per-day caps), the pricing calculator (spec 005) behind staff-only endpoints, the customer's wallet with `held_on_orders` and `pending_withdrawals` as totals, and the Customer App wallet labels for `compensation` ("Compensation from Dahab") and `reversal` ("Correction").
- **What does not exist**: no list of compensation; no way to pay compensation outside a dispute (the table needs a dispute and an order); no wallet adjustment; no `bank_movement` or `daily_close` table; no bank book; no daily close; the Overview's *Paid out ahead of buyers*, *Transactions*, *Needs a decision*, *Gold price now* and *This month* are mock; no Orders export; the customer resources carry the deposit amount but not what is actually held; no customer endpoint for gold prices or a quote (the app's rate strip, calculator, splash cards and sell estimate run on a mock feed).

## Clarifications

### Session 2026-10-04

- Q: Can staff pay compensation outside a dispute (the built `compensation` row requires a dispute and an order)? → A: Yes, under `compensation.pay` with the same caps and lock and the same `external_equity → cust_available` entry; `dispute_id` and `order_id` become optional (an order may still be named).
- Q: What form does the CEO's wallet adjustment take? → A: A free credit or debit of the customer's available balance against `external_equity`, ledger kind `reversal` with no reversed entry, recorded in a new `wallet_adjustment` row; a debit never below zero; permission `wallet.adjust`, seeded to no role (the CEO holds every code).
- Q: Where does the close's bank balance come from, and when does a day lock? → A: Staff type the real bank statement's closing balance; books = the ledger's bank cash at the day's end; difference = statement − books. A difference of 0 locks the day.
- Q: When the difference is not 0 (money matched or recorded the next day), can the day be locked? → A: Yes, with a required explanation (10–1000 characters); until locked, a day can be saved and re-computed.
- Q: Does this spec add a public endpoint for gold prices and the "what will I get" quote? → A: Yes; the rate strip, home calculator, splash rate cards and sell-form estimate go live and lose their MOCK flags.
- Q: What does the Bank movements page show? → A: Both — the form to record a movement outside the app (design) and every bank posting of the period (top-ups matched in, withdrawals released out, movements recorded by hand, reversals) with opening, in, out and closing cash and a CSV export. Customer refunds never touch the bank and are not in it.
- Q: How is a bank movement's proof attached? → A: An optional staff file upload (PDF/JPG/PNG, at most 10 MB), stored encrypted like customer uploads; opening it is audited.
- Q: When can a day be closed? → A: Only after it ends (from 00:00 Cairo the next day); the books are cut at midnight; days may be closed in any order; today shows live figures with Close disabled.
- Q: The ledger's single `bank` account stands for all of Dahab's accounts — how is *Transfer between our own accounts* handled, and what balance is typed at the close? → A: An own-account transfer is a record only, with no ledger entry (any fee is a separate *Bank charge*); the typed balance is the total across all of Dahab's accounts on that day's statements.
- Q: What happens to a bank movement whose statement date is a locked day? → A: Allowed; its ledger entry posts now (today's books) and the statement date is kept on the record; the locked day never changes.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — See and pay compensation (Priority: P1)

Finance or the CEO opens *Compensation*: every compensation Dahab has paid, newest first, filterable by period, reason, payer and customer, with the total paid in the period and this month, the two caps, and what the viewer has left today. Each row shows when, the customer's wallet reference, amount, reason, who paid it and the dispute/order it came from. The list exports to CSV. A holder of `compensation.pay` can also pay compensation from this page outside a dispute — the customer's wallet, amount, one of the five reasons and a note, optionally naming one of the customer's orders — under the same caps.

**Why this priority**: Compensation is real money leaving Dahab's equity; the people who pay it need to see what has been paid and how much room is left before they pay more.

**Independent Test**: Pay two compensations inside dispute resolves by two payers, open the page as Finance — both rows, the period total, the caps and the viewer's remaining limit appear; export gives the same rows; a staff member without the permission gets 403.

**Acceptance Scenarios**:

1. **Given** compensations paid this month, **When** Finance opens Compensation with *Last 30 days*, **Then** every row in the period appears newest first with its dispute and order references and the period total equals the sum of the rows.
2. **Given** a Finance member who paid 3,000 today against a 5,000 day cap, **When** they open the page, **Then** it shows 2,000 left today and the per-payment cap of 2,000; a holder of `compensation.uncapped` sees "no limit".
3. **Given** the filters set, **When** the viewer exports, **Then** the CSV holds exactly the filtered rows and the export is written to the audit log.
4. **Given** a COO or Operations member, **When** they request the list, **Then** they are refused 403 and the Dashboard does not show the page in the navigation.
5. **Given** a Finance member with 2,000 left today, **When** they pay 800 to a customer's wallet for *a wasted trip* without a dispute, **Then** one balanced `compensation` entry and a compensation row (no dispute) are written, the customer's available rises by 800, the remaining limit becomes 1,200, the payment is audited `compensation.paid`, and the customer sees *Compensation from Dahab* in their wallet history.
6. **Given** the same Finance member, **When** they try to pay 1,500 with 1,200 left, **Then** the request is refused `compensation_cap_exceeded` (403) with the per-payment cap and what is left.

---

### User Story 2 — Adjust a customer's wallet (Priority: P1)

The CEO corrects a customer's wallet: chooses the customer, credit or debit, the amount and a reason. The correction is one balanced ledger entry through the money service, carrying the CEO as actor and the reason; it is never an edit of a balance. A debit can never take the customer's available balance below zero. The customer sees the line in their wallet history with a clear label. The entry is a free credit or debit between the customer's available account and `external_equity`, ledger kind `reversal` with no reversed entry, recorded in a `wallet_adjustment` row so adjustments can be listed.

**Why this priority**: Errors in a wallet must be correctable without touching the ledger's history, and only by the narrowest role.

**Independent Test**: As the CEO, credit 500 to a customer with a reason; the customer's available rises by 500, the whole ledger still sums to zero, the audit log holds the action with the reason; a Finance member is refused 403; a debit larger than the available balance is refused `insufficient_funds`.

**Acceptance Scenarios**:

1. **Given** a customer with 1,000 available, **When** the CEO debits 300 with a reason, **Then** available is 700, one balanced entry exists with the CEO as actor and the reason as memo, and an audit row `wallet.adjusted` records customer, direction, amount and reason.
2. **Given** a customer with 100 available, **When** the CEO debits 300, **Then** the request is refused `insufficient_funds` (409) and nothing is written.
3. **Given** the same Idempotency-Key sent twice, **When** the second request arrives, **Then** it replays the first answer and no second entry is posted.
4. **Given** two adjustments of the same customer sent at the same instant, or an adjustment and a withdrawal submission, **When** both run, **Then** the available balance is never negative and the ledger sums to zero.

---

### User Story 3 — Bank book: record movements outside the app and see the bank's movements (Priority: P1)

Finance or the CEO opens *Bank movements*. They record money that moved in or out of Dahab's bank account without going through the app (capital paid in, an operating expense, a bank charge, profit taken out, …): kind, amount, in or out, the date on the bank statement, what it was for, and proof. Each record is one balanced `external_bank_movement` entry between `bank` and `external_equity`, audited. The page lists, for a period, the bank account's movements as the ledger records them — top-ups matched in, withdrawals released out, and the movements recorded by hand — with what each is, in/out, who recorded or matched it, and the bank cash after; it exports to CSV.

**Why this priority**: Without these entries the bank balance and the books never agree, and the daily close cannot be clean.

**Independent Test**: Record a 14,800 operating expense (out) with proof; the bank's cash drops by 14,800, `external_equity` moves by the same, the ledger sums to zero, the row appears in the bank book next to the period's top-ups and withdrawals; the CSV matches.

**Acceptance Scenarios**:

1. **Given** Finance, **When** they record *Capital paid in* 500,000 dated yesterday, **Then** the bank's cash rises by 500,000, the movement shows *In 500,000 · by* the recorder, and an audit row records it.
2. **Given** a period with a matched top-up, a released withdrawal and a recorded bank charge, **When** the bank book is opened for that period, **Then** all three appear with their direction and the opening + in − out = closing figure holds.
3. **Given** *Something else* as the kind, **When** the description is empty, **Then** the record is refused 422.
4. **Given** a COO, **When** they try to record a movement, **Then** they are refused 403.

---

### User Story 4 — Close the day (Priority: P1)

At the end of a day, Finance or the CEO opens *Daily closing*: the bank balance, customer wallets available and held, the Dahab wallet (earned and not withdrawn), movements recorded outside the app that day, and the difference between the bank and the books. When the difference is explained, they close the day; it is recorded with who closed it and when, audited, and once locked it can never be changed or reopened. The page lists recent days with Bank, Books, Difference and Closed by. The bank figure is typed in from the real bank statement's closing balance; the books are the ledger's bank cash at the end of the Cairo day; the difference is statement − books. A day can be closed only after it has ended. A difference of 0 locks the day; a non-zero difference can be saved (and re-computed later) or locked with a required explanation.

**Why this priority**: The daily close is the platform's money-safety habit: a difference found today is easy to explain; found in three months it is not.

**Independent Test**: With a day's entries posted, close the day — the snapshot figures equal the ledger at the day's cut-off, the row is locked, an audit row exists; a second close of the same day is refused; a later entry does not change the locked snapshot.

**Acceptance Scenarios**:

1. **Given** yesterday not closed, **When** Finance closes it, **Then** a close row with the bank figure, customer liability, Dahab wallet and difference is stored, marked locked with the closer and time, and audited `day.closed`.
2. **Given** a locked day, **When** anyone tries to close or change it again, **Then** the request is refused (`day_already_closed`, 409) and the stored row is untouched.
3. **Given** a close in progress and a ledger entry posted at the same moment, **When** both commit, **Then** the snapshot either includes the entry or not, consistently with the cut-off, and the locked figures never change afterwards.
4. **Given** yesterday's statement balance is 250 lower than the books (a bank charge not yet recorded), **When** Finance closes without an explanation, **Then** the day is saved unlocked with its difference; **When** they record the charge (posted today) and close yesterday again with the explanation "Bank charge, recorded after", **Then** yesterday is locked with −250 and today's close nets it out.
5. **Given** today, **When** anyone tries to close it before midnight Cairo, **Then** the request is refused `day_not_ended` (422).

---

### User Story 5 — The real Overview (Priority: P2)

Every staff member who opens the Overview sees real figures for what they are allowed to see: the safety figure and wallets (exists, `wallet.view`); *Dahab earned this month* (commission + spread, Cairo month, `wallet.view`); *Transactions, last 30 days* — orders by status with count, value and what is held now (`order.view`); *Needs a decision*, oldest first — listings waiting for review, withdrawals waiting, open disputes, identity documents waiting, top-up notices waiting, payout accounts to check, requests for more time — each with its age and who it is on, each row only for staff holding the permission that acts on it; *Gold price now* (`pricing.view`); *This month* — new sellers, pieces listed, sold, sell-through and the average time money took to reach sellers. A figure the viewer may not see is not sent and not shown. Figures for features that do not exist are removed, not faked.

**Why this priority**: The Overview is the first page every staff member sees; mock numbers there mislead.

**Independent Test**: Seed orders in several states, a pending withdrawal, an open dispute and a waiting identity document; open the Overview as the CEO — every figure matches the database; open it as Operations — no money figures are returned, the operations rows are.

**Acceptance Scenarios**:

1. **Given** 6 orders awaiting delivery holding 68,436 in deposits, **When** the CEO opens the Overview, **Then** *Waiting for the seller* shows 6, their total value and 68,436 held now.
2. **Given** a Verification member, **When** they open the Overview, **Then** they see the identity documents waiting and no wallet, earnings or withdrawal figures.
3. **Given** no first-time-seller advances exist in the platform, **When** the Overview loads, **Then** *Paid out ahead of buyers* is not shown (the advance is not built) and nothing mock remains on the page.

---

### User Story 6 — Export the Orders list (Priority: P2)

A staff member with `order.view` exports the Orders list as CSV with the filters on screen (group, past deadline, branch, search), limited to their branch scope like the list itself. The export is audited like the Wallet statement and Withdrawals exports.

**Why this priority**: Operations and Finance work orders outside the Dashboard (reconciliation, reports); today they copy rows by hand.

**Independent Test**: With orders in two branches, a staff member assigned to one branch exports "awaiting delivery" — only that branch's matching orders appear; an audit row records the filters and the row count.

**Acceptance Scenarios**:

1. **Given** filters `group=awaiting_balance` and a search term, **When** the export runs, **Then** the CSV holds exactly the rows the list shows across all its pages, and an audit row `order.exported` records the filters and count.
2. **Given** a staff member without `order.view`, **When** they request the export, **Then** they are refused 403.

---

### User Story 7 — What each order holds, in the Customer App (Priority: P2)

A customer opens *Held on open orders*. Besides the total, they see each buy request and order that holds part of their money: the piece, the reference, and the amount held on it now — every amount sent by the Backend from the ledger, no maths in the app. Each line opens the request or order. The same held amount appears on the buy request and order screens.

**Why this priority**: Customers asked where their held money is; the screen today only shows a total.

**Independent Test**: A customer with two waiting buy requests (deposits 11,640 and 4,000) and one accepted order (deposit 9,000) opens the screen — three lines with those amounts summing to *Held on open orders*; after leaving one request, its line disappears and the total drops.

**Acceptance Scenarios**:

1. **Given** a buy request whose deposit was released, **When** the customer opens its detail, **Then** the held amount is 0 and it is not listed on the Held screen.
2. **Given** an order whose balance was paid, **When** the customer opens it, **Then** its held amount is 0 (the deposit went towards the price).
3. **Given** the app in Arabic, **When** the Held screen shows a line with an amount and a reference, **Then** every word and the number pattern is in Arabic.

---

### User Story 8 — Live gold prices and the quote in the Customer App (Priority: P3)

Anyone — signed in or not — sees today's prices in the rate strip, the splash rate cards and the home calculator: per enabled karat, what sellers get and what buyers pay per gram, with the feed's state and the time of the price. A seller filling the sell form sees the "what will I get" estimate for their karat, weight and (for gold with a diamond) asking price, from the spec 005 calculator. The figures come from the Backend; the app does no pricing maths; the four MOCK flags are removed. While the feed is down and no manual price is in force, the app says prices are paused instead of showing a stale or invented figure.

**Why this priority**: The app's first screen shows a price; a mock one misleads every visitor.

**Independent Test**: Record a feed price, open the splash and home screens signed out — the 21K figures equal the Dashboard's *Gold price now*; ask for a quote for 10 g 21K gold — the estimate equals the calculator's payout for that piece.

**Acceptance Scenarios**:

1. **Given** a current price, **When** the public price endpoint is called, **Then** it returns, per enabled karat, sellers get and buyers pay per gram, the price time and the feed state — never the raw provider bid/ask or the adjustments.
2. **Given** a quote request for a gold piece of 10 g 21K, **When** it is answered, **Then** the estimate equals what the seller would get for that piece today, marked indicative; a disabled karat or a non-positive weight is refused 422.
3. **Given** no usable price (feed down, no manual price), **When** the app loads, **Then** the price endpoint answers `price_unavailable` and the app shows "Prices are paused" in EN/AR.

---

### Edge Cases

- A compensation, adjustment, bank movement or close sent twice with the same Idempotency-Key replays the first answer; with a different key it is a new action (and the caps/guards apply again).
- A Finance payer at their day cap: the list shows 0 left; a payment is refused `compensation_cap_exceeded` with the figures.
- An adjustment on a suspended or unverified customer: allowed (it is a correction, not trade), and the audit row records the customer's status.
- A debit adjustment racing a withdrawal submission or a buy request deposit hold on the same wallet: the customer account is locked in the money service's order; one of them may fail `insufficient_funds`, the balance is never negative.
- A bank movement whose statement date is a locked day: accepted; its ledger entry posts now (today's books) and keeps the statement date on the record; the locked day never changes.
- A close attempted for today before the day has ended or for a future date: refused `day_not_ended` (422). A day before the first ledger entry may be closed (its books are 0).
- A saved, unlocked day re-closed after more entries: the snapshot is re-computed from the ledger at that day's midnight cut-off (entries posted after midnight never count toward it); only the typed statement balance and the explanation change.
- Compensation outside a dispute naming an order the customer is not a party to: refused 422.
- Compensation outside a dispute to a customer who is not verified: refused `verification_required` (403), as for crediting by hand; a suspended verified customer may be paid (it is Dahab's money going in, recorded with their status).
- A proof file of the wrong type or over 10 MB: refused 422 before anything is recorded.
- The public price and quote endpoints under abuse: throttled like the public market reads.
- Two staff members closing the same day at the same moment: exactly one close is stored, the other is refused `day_already_closed`.
- The Overview for a staff member with none of the money or order permissions: only the gold price (if `pricing.view`) and the rows they act on; never an error.
- An Orders export over a large result: streamed, bounded by the same filters; never times out at 10,000 rows.
- A held amount on an order frozen in a dispute: unchanged while frozen (the ledger has not moved).

## Requirements *(mandatory)*

### Functional Requirements

**Compensation**

- **FR-001**: Staff holding `compensation.pay` or `wallet.view` MUST be able to list every compensation paid, newest first, keyset-paginated, filtered by Cairo date range (default last 30 days), reason, payer and customer, with per row: when, the customer's wallet reference, the party, amount, reason, note, payer, dispute and order references.
- **FR-002**: The list MUST return the period total, this Cairo month's total, the two caps from settings and, for the viewer, what is left today (or "uncapped" for `compensation.uncapped`).
- **FR-003**: The list MUST export to CSV with the same filters; each export is audited (`compensation.exported`, filters and row count).
- **FR-004**: A holder of `compensation.pay` MUST be able to pay compensation outside a dispute: customer, amount, a reason from the five, a 10–1000 character note and optionally one of the customer's orders; the same caps under the same per-payer lock (`compensation.uncapped` lifts them), the same `external_equity → cust_available` entry, a compensation row with no dispute, idempotency, audit `compensation.paid`, and SMS + email to the customer after commit. The compensation row's dispute and order become optional; a row inside a dispute still names both.

**Wallet adjustment**

- **FR-005**: A new permission *Adjust a wallet balance directly* (`wallet.adjust`) MUST exist, seeded to no role (the CEO holds every code), editable from the Dashboard, never on the COO by seed.
- **FR-006**: A holder MUST be able to credit or debit a customer's available balance with an amount (> 0, at most 4 decimal places) and a reason (10–1000 characters), as one balanced ledger entry through the money service — kind `reversal` with no reversed entry, `cust_available ±amount` against `external_equity ∓amount` — with the staff member as actor and the reason as memo, and one append-only `wallet_adjustment` row (customer, direction, amount, reason, staff, ledger entry, time). Holders of `wallet.adjust` or `wallet.view` MUST be able to list adjustments (newest first, filtered by period and customer).
- **FR-007**: A debit MUST NOT take available below zero (`insufficient_funds` 409); the adjustment is idempotent, audited `wallet.adjusted` (customer, direction, amount, reason, ledger id, the customer's status), and the customer is told by SMS + email after commit.
- **FR-008**: The adjustment MUST appear in the customer's wallet history and statements labelled *Correction* (the existing label of a `reversal` line; EN/AR in the Customer App).

**Bank movements**

- **FR-009**: A new permission *Record a bank movement outside the app* (`bank.record`) MUST exist, seeded to CEO and Finance, never the COO.
- **FR-010**: A holder MUST be able to record a movement: kind (the design's seven: capital paid in, operating expense, bank charge, profit taken out, transfer between our own accounts, refund from a supplier, something else), amount > 0, direction in/out, the date on the bank statement (not in the future), what it was for (10–500 characters, required), and an optional proof file (PDF/JPG/PNG, at most 10 MB, stored encrypted; opening it is audited `bank.movement_proof_viewed`); a statement date on a locked day is accepted and the entry still posts now; it writes one `bank_movement` row and one balanced `external_bank_movement` entry `bank ↔ external_equity` (money in: `bank −X`, `external_equity +X`; out: the reverse), idempotent and audited `bank.movement_recorded`. *Transfer between our own accounts* writes the row only, with no ledger entry, and is shown as such in the list.
- **FR-011**: Holders of `bank.record` or `wallet.view` MUST be able to read the bank book for a Cairo period: opening cash, in, out, closing cash, and every bank posting (top-up matched, withdrawal released, movement recorded by hand, reversal) with its kind, reference, direction, actor and cash after; keyset-paginated; CSV export audited.

**Daily close**

- **FR-012**: A new permission *Close the day* (`day.close`) MUST exist, seeded to CEO and Finance, never the COO.
- **FR-013**: Holders of `day.close` or `wallet.view` MUST be able to read the closing view for a day: bank balance, customer wallets available and held, Dahab wallet (earned and not withdrawn), movements recorded outside the app that day, books total and difference; and the recent days with their figures and closer.
- **FR-014**: A holder of `day.close` MUST be able to close a Cairo day that has ended (`day_not_ended` 422 otherwise), typing the closing balance across all of Dahab's accounts on that day's statements; the Backend computes the books (the ledger's bank cash at the day's midnight cut-off), customer liability (available and held), the Dahab wallet (commission + spread) and the difference (statement − books). A difference of 0 locks the day; a non-zero difference locks it only with an explanation (10–1000 characters), otherwise the day is saved unlocked and may be closed again later (re-computed, same cut-off). The row holds the closer and the time; every close attempt is audited (`day.closed` when locked, `day.saved` when not); a locked day can never be updated or deleted (database guard) and closing it again is refused `day_already_closed` (409). Days may be closed in any order.
- **FR-015**: The snapshot MUST be computed from the ledger at a defined cut-off (the end of the Cairo day), so entries posted later never change a closed day.

**Overview**

- **FR-016**: A read-only Overview endpoint MUST return, per section, only what the viewer's permissions allow: earnings this Cairo month (commission, spread; `wallet.view`), orders by status over the last 30 days with count, value and held now (`order.view`, branch scope), the needs-a-decision queue (each kind only with its acting permission: `listing.review`, `withdrawal.release`, `dispute.handle`, identity review, `topup.match`, `payout_account.verify`, `order.extend_deadline`), and this month's activity (new sellers, pieces listed, sold, sell-through, average days to pay sellers; `order.view`). Orders by status count every open order whatever its age and completed/cancelled orders of the last 30 days. Each needs-a-decision row says which area it belongs to (e.g. Withdrawals), not a role name, since roles are Dashboard-managed data. The safety figure and wallets keep using the existing wallets overview; the gold price keeps using the existing current-price endpoint.
- **FR-017**: Figures for features that are not built (the first-sale advance, the Rapaport matrix) MUST NOT be returned or shown.

**Order export**

- **FR-018**: `order.view` holders MUST be able to export the Orders list as CSV with the list's filters (group, past deadline, branch, search) and branch scope; streamed; audited `order.exported` with filters and row count.

**Held per order (Customer App)**

- **FR-019**: The customer's buy request and order resources MUST carry the amount currently held on them, derived from the ledger (holds minus releases, forfeits and settlement on that request/order), as a decimal string; the wallet MUST be able to list the requests and orders holding money with their amounts, summing to `held_on_orders`.
- **FR-020**: The Customer App's Held screen MUST list those lines from the Backend's figures only, each opening its request or order, with EN/AR strings.

**Public prices and quote**

- **FR-021**: A public, read-only, throttled price endpoint MUST return, per enabled karat, sellers get and buyers pay per gram (from the spec 005 calculator), the price time and the feed state; nothing else (no provider bid/ask, no adjustments). A public quote endpoint MUST return the "what will I get" estimate for a piece (category, karat, weight, and the asking price for gold with a diamond), marked indicative. With no usable price both answer `price_unavailable` (409).
- **FR-021a**: The Customer App's rate strip, splash rate cards, home calculator and sell-form estimate MUST read these endpoints, show "Prices are paused" (EN/AR) when no price is usable, and lose their MOCK flags.

**Cross-cutting**

- **FR-022**: Every staff POST MUST require an Idempotency-Key and write an audit row; every money movement MUST be one balanced entry through the money service; the whole ledger MUST still sum to zero after every test.
- **FR-023**: Every new endpoint MUST be documented in OpenAPI and Postman; the Technical Spec (Part 1 §4.2, Part 2 §9) gets "Changed by spec 015" notes; the schema docs gain the new tables; `api-contract.md` is updated if a convention changes.
- **FR-024**: The Dashboard pages Compensation, Bank movements and Daily closing MUST be live and visible in the navigation only to holders of their permissions; every modal a `DModal` with `useIdempotencyKey`; the Overview's mock data is removed.
- **FR-025**: The Customer App MUST label adjustment lines in the wallet history in EN/AR; anything else from this spec that reaches the customer is listed in the report, or the report states that nothing else does.

### Key Entities

- **Compensation** (exists): a payment from Dahab's equity to a customer's available balance; today always tied to a dispute and an order.
- **Compensation** gains payments outside a dispute: dispute and order optional.
- **Wallet adjustment**: a correction of one customer's available balance by a `wallet.adjust` holder — customer, direction, amount, reason, staff, ledger entry, time; append-only.
- **Bank movement**: money in or out of Dahab's bank account outside the app — kind (seven), signed amount, statement date, reason, optional proof file, recorder, its ledger entry, time; append-only.
- **Daily close**: one row per Cairo day — typed statement balance, books, customer liability (available, held), Dahab wallet, difference, explanation, locked, closer, time; immutable once locked.
- **Staff upload**: a file a staff member attaches (bank movement proof), stored encrypted, its views audited.
- **Overview figures**: read-only aggregates over orders, listings, withdrawals, disputes, identity documents, top-ups, payout accounts, extension requests and the ledger.
- **Held amount per request/order**: derived from the ledger postings on the customer's held account for that buy request or order.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After every money action of this spec (compensation, adjustment, bank movement), the whole ledger sums to exactly zero and no customer's available balance is negative, including under concurrent requests (two adjustments at once, an adjustment against a withdrawal, a close against a late entry).
- **SC-002**: The Dashboard Overview shows no hard-coded figure; every number on it matches the database for the same moment.
- **SC-003**: A closed day's figures are identical when read a week later, whatever was posted since.
- **SC-004**: Finance can find what was paid as compensation in any period and how much they may still pay today in one page, without leaving it.
- **SC-005**: The sum of the per-request and per-order held amounts a customer sees equals their *Held on open orders* figure, to the piastre.
- **SC-006**: An Orders export of 10,000 rows completes within 10 seconds and matches the list row for row.
- **SC-007**: No wallet-touching action of this spec is available to the COO by seed, and each one is refused 403 without its permission.

## Assumptions

- The Rapaport matrix and the first-sale advance are not built; their Overview rows are removed rather than shown at zero.
- "Money reaches sellers in N days" is the average, over orders whose seller was paid this Cairo month, of the time from acceptance to the seller's payment.
- "Dahab wallet, earned and not withdrawn" is the balance of `dahab_commission` + `dahab_spread` (as the Wallet statement's `view=dahab` already defines it).
- Founder real-time notification of a wallet adjustment (Part 1 §8.3) stays out of scope until OI-1.3 picks a channel; the audit row is the record.
- The bank movement kinds follow the Dashboard design (seven), wider than Part 2's four.
- Orders export reuses the Orders list's filters exactly; no new filter is added.
- New permissions reach an existing database through `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder` (additive) after `php artisan migrate`.
