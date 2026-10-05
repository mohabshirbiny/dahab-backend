# Feature Specification: Tax invoices and credit notes (no ETA integration)

**Feature Branch**: `feature/invoices` in all three repositories — backend worktree `tax-invoices-credit-notes-6690eb` (from `main` at `093c967`), dashboard worktree `.claude/worktrees/invoices` (from `main` at `01578f3`), Customer App worktree `.claude/worktrees/invoices` (from `main` at `191f9c8`, github.com/mohabshirbiny/dahab-flutter — public).

**Created**: 2026-10-05

**Status**: Draft (clarified)

**Input**: User description: "Spec 016 — Tax invoices and credit notes (no ETA integration) — all three projects. (1) Tax invoices issued automatically at settlement (`tax_invoice`, one per order per party_role, net/vat/gross, storage_ref; Part 2 §7 'Issue both tax invoices automatically' at pay-balance; nobody issues one by hand); contents, numbering and Dahab's tax details from the docs, VAT on commission only, the spread carries no VAT; ask what the docs leave open, including whether the buyer gets an invoice and what for. (2) Credit notes: a registered invoice is never edited, a correction is a credit note that reverses it (CN-YYYY-NNNNNN, Reverses · Why · Amount); which events produce one; permission 'Issue or correct a tax invoice' (CEO + Finance, never the COO). (3) No ETA integration: `eta_reference` stays empty, nothing is sent to the Tax Authority; decide what replaces the design's Tax Authority column, 'Rejected by the Tax Authority' figure and 'Send again'. (4) The invoice document: a PDF (EN/AR) stored privately, downloadable by its customer and by staff, views audited. (5) Backfill of orders paid before this spec. (6) Dashboard p-invoices live. (7) Customer App 'Transactions and invoices' and the invoice screen live, Account menu item, order screen links its invoice once paid."

## Context

- **Sources**:
  - Technical Spec Part 2 §7 `POST /orders/{id}/pay-balance`: *"Issue both tax invoices automatically now (`tax_invoice`, one per `party_role`) — settlement has occurred, so the invoices belong here, not at handover; nobody issues one by hand (open-questions §3); ETA filing is Part 4."* The settlement is one balanced `balance_payment` transaction: buyer `−balance`/`−deposit`, escrow pass-through, seller `+seller_proceeds`, `dahab_commission +commission`, `vat_payable +vat`, `dahab_spread +spread` (gold only).
  - Technical Spec Part 3 §2.5–§2.6: `commission = max(pct × making-charge total | value above gold, minimum)`; **`vat = vat.pct × commission`, on commission only**, "included in the figure the customer sees and shown separately on the invoice"; "the spread is trading margin, not a service fee, and carries no VAT"; `seller_proceeds = seller_gross − commission − vat`. Rounding half-up to 4 dp, residue to Dahab.
  - Technical Spec Part 1 §4.2 (Money matrix): *Issue or correct a tax invoice* — CEO ✓, COO —, Finance ✓, others —. Admin roles doc: Finance "handles tax invoice corrections and exceptions. Invoices are issued automatically at settlement … nobody issues them by hand."
  - Technical Spec Part 4 §4 (Egyptian Tax Authority e-invoicing): *"Not yet specified."* — this spec does not integrate it.
  - Schema `04_schema_market.sql`: `tax_invoice (invoice_id, order_id, party_role, customer_id, net_amount, vat_amount, gross_amount, eta_reference, issued_at, storage_ref, UNIQUE (order_id, party_role))`. No credit-note table and no invoice-number column exist in the schema.
  - Blueprint §2 step 10: "the moment the balance is paid … a tax invoice is issued automatically to both sides"; the buyer "owns the piece and has a tax invoice". Terms draft §9.4: "A tax invoice is issued automatically on every completed sale and filed with the Egyptian Tax Authority." Open-questions §3: "Invoices are issued automatically at settlement. Nobody issues one by hand."
  - Dashboard design `p-invoices`: lead text; figures *Issued this month*, *Rejected by the Tax Authority*, *VAT collected* ("On commission only"), *Credit notes*; Invoices list *Number · Date · Order · Commission · VAT · Tax Authority · View/Send again* with a period filter (*Last 30/60/90 days, This year, Custom dates*), search and *Export*; Credit notes list *Credit note · Reverses · Why · Amount · Registered* (numbers `CN-2026-000031`); invoice numbers shown as `DH-2026-004417`.
  - Customer prototype: `#s-invoices` (*Transactions and invoices*: All / Sold / Bought, one row per invoice with date, what and amount, *Download all as one file*); `#s-invoice` (*You sold …*; Settlement: gold value at the rate, making charge back, gross; *Dahab charges*: commission, net of VAT, VAT at 14%, total; *Paid to your wallet*; the rate note; the transaction trail; Dahab's tax registration; *Download this invoice*); *View invoice* on a finished order; *Open the invoice* on a *Sale settled* wallet line.
- **What exists** (specs 001–015): settlement at pay-balance (`OrderSettlement`, spec 012) computes and posts commission, VAT and spread on the locked rates and the measured weight; `vat.pct` (14) and the commission settings; orders `DH-YYYY-NNNNNN`; encrypted private storage for customer uploads; the audit log; staff permissions catalogue and seeder; the Customer App routes `R.invoices` / `R.invoice` on mock data with MOCK flags and the Account menu item *Transactions and invoices* (mock); the Dashboard `invoices` route on a placeholder (hidden in the navigation).
- **What does not exist**: no `tax_invoice` table, no invoice number, no credit note, no invoice document, no permission for invoices, nothing about Dahab's legal name, address or tax registration number anywhere in the docs or settings (the prototype shows a placeholder `000-000-000`).
- **Disagreement found (reported)**: the blueprint's worked example and the customer prototype treat commission as **VAT-inclusive** (commission 336 = 294.74 net + 41.26 VAT); Part 3 §2.5 and the built settlement **add** VAT on top (commission 600 + VAT 84). By the platform's order of authority (code → docs), the invoice follows the built ledger: net = the commission posted, VAT = the VAT posted, gross = their sum.

## Clarifications

### Session 2026-10-05 (specify)

- Q: What does the buyer's invoice state? → A: The price paid, with VAT 0: net = gross = `buyer_total`, broken down as gold value at the buyer's locked sell rate on the measured weight plus the making charge (gold), or the asking price (diamond / gold with diamond).
- Q: Which invoice numbering? → A: The order reference plus the party: `DH-YYYY-NNNNNN-S` (seller) and `DH-YYYY-NNNNNN-B` (buyer). Credit notes keep their own sequence `CN-YYYY-NNNNNN`.
- Q: Do orders paid before this spec get invoices? → A: No backfill. Only balance payments made after installation issue invoices; earlier settled orders show no invoice.

### Session 2026-10-05 (clarify)

- Q: Does a credit note move money or is it a document only? → A: It moves money, on seller invoices only: one balanced ledger entry — `dahab_commission −net`, `vat_payable −vat`, seller `cust_available +gross` — partial or full, never more than what is left of the invoice. Buyer invoices (no Dahab charge) cannot be credited.
- Q: Where do Dahab's legal and tax details come from? → A: A Backend PHP configuration file (not environment variables): legal name (EN/AR), address (EN/AR), tax registration number, commercial register number. They are copied onto each invoice and credit note at issue. Missing details never block settlement; the document waits until the configuration is complete.
- Q: One bilingual PDF or one per language? → A: One bilingual PDF per invoice or credit note (every label in English and Arabic), stored once and downloaded as is from the app and the Dashboard; layout from the prototype's `#s-invoice`.
- Q: What replaces the design's Tax Authority column, *Rejected by the Tax Authority* figure and *Send again*? → A: The column becomes **Status** — *Issued*, *Partly credited*, *Credited* (from the credit notes); the figure becomes **Net invoiced** (commission this month); *Send again* is removed (View stays); the page's lead text says e-invoicing with the Tax Authority is not connected yet.
- Q: Does reading invoices need its own permission? → A: Yes — two permissions: `invoice.view` (list, detail, PDFs, export) and `invoice.correct` (issue a credit note), both seeded to CEO and Finance, never the COO.
- Q: Are customer downloads of their own documents audited? → A: No — staff PDF views and CSV exports are audited; a customer downloading their own invoice or credit note is not.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Invoices are issued automatically when the balance is paid (Priority: P1)

When the buyer pays the balance and the sale settles, Dahab issues the tax invoices for that order at the same moment, inside the same settlement: one to the seller and one to the buyer. Nobody presses a button. Each invoice carries a number, the date, the party, the piece, the figures of the sale and Dahab's tax details, and its amounts match exactly what the ledger posted for that settlement.

**Why this priority**: Issuing an invoice at every completed sale is a legal promise in the terms (§9.4) and the blueprint; every other story reads the invoices this one creates.

**Independent Test**: Take an order to `awaiting_balance`, pay the balance — exactly two invoices exist for the order, one per party; the seller's net and VAT equal the order's `commission` and `vat` ledger lines; paying again (replay or a second attempt) creates no third invoice.

**Acceptance Scenarios**:

1. **Given** an order in `awaiting_balance` with a passing inspection, **When** the buyer pays the balance, **Then** the order moves to `ready_to_collect` and, in the same transaction, the seller's invoice and the buyer's invoice are issued with their numbers and `issued_at` = the settlement time.
2. **Given** the settlement posted commission 600.0000 and VAT 84.0000, **When** the seller's invoice is read, **Then** its net is 600.0000, its VAT 84.0000 and its gross 684.0000.
3. **Given** two pay-balance requests for the same order arrive at once (same or different idempotency keys), **When** both run, **Then** exactly one settlement and exactly one invoice per party exist.
4. **Given** the invoice cannot be written (e.g. a database error), **When** the buyer pays, **Then** the whole payment is rolled back — no settlement without its invoices, no invoice without its settlement.
5. **Given** a stone piece (diamond or gold with diamond), **When** it settles, **Then** the invoices show the asking price, the commission on the value above the gold and its VAT, and no spread.

---

### User Story 2 — Finance sees and exports the invoices (Priority: P1)

Finance or the CEO opens *Invoices* in the Dashboard: this month's issued count, VAT collected and credit notes; the invoices list with a period filter and a search by number, order or customer; each invoice's detail and its PDF; and a CSV export of the filtered list. Staff without the invoice permission do not see the page.

**Why this priority**: The tax position (VAT collected, what was invoiced) is what Finance reports and remits; the list must be complete and exportable from day one.

**Independent Test**: Settle three orders, open Invoices as Finance — six invoices (two per order), the month's *VAT collected* equals seller-invoice VAT minus credit-note VAT (FR-011), the CSV holds the filtered rows and is audited; a COO gets 403.

**Acceptance Scenarios**:

1. **Given** invoices issued this month, **When** Finance opens the page with *Last 30 days*, **Then** every invoice of the period appears newest first with number, date, order reference, party (Seller/Buyer), customer reference, net (commission on seller invoices, the price paid on buyer invoices), VAT, gross and status (*Issued*, *Partly credited*, *Credited*), and the figures *Issued this month*, *Net invoiced*, *VAT collected* and *Credit notes* match the rows.
2. **Given** a filter and a search, **When** Finance exports, **Then** the CSV holds exactly those rows and the export is written to the audit log.
3. **Given** an invoice row, **When** Finance opens it, **Then** the detail shows the full invoice and its credit notes, and opening the PDF is audited.
4. **Given** a COO, Operations, Verification or IGI member, **When** they request the list, a detail or a PDF, **Then** they are refused 403 (no `invoice.view`) and the page is not in their navigation.
5. **Given** a staff member with `invoice.view` but not `invoice.correct`, **When** they open an invoice, **Then** they can read and download it but see no *Issue a credit note* action, and the request is refused 403 if sent.

---

### User Story 3 — The customer sees and downloads their invoices (Priority: P1)

A customer opens *Transactions and invoices* from Account: every invoice issued to them (All / Sold / Bought), each with its date, what it was for and the amount. Opening one shows the invoice as in the prototype and lets them download its bilingual (English and Arabic) PDF. A paid order's screen links to its invoice, and a *Sale settled* wallet line links to the invoice of that sale.

**Why this priority**: The invoice is the customer's legal record of the sale ("Owns the piece and has a tax invoice"); without the app showing it, the promise is invisible.

**Independent Test**: Settle an order, sign in as the seller — the invoice is listed under Sold, opens with the right figures and downloads; the buyer sees theirs under Bought; neither can open the other's invoice (404).

**Acceptance Scenarios**:

1. **Given** a seller with one settled sale, **When** they open *Transactions and invoices*, **Then** one invoice is listed under *Sold* with its number, date, piece and amount.
2. **Given** the invoice open, **When** the seller taps *Download this invoice*, **Then** they get that invoice's bilingual PDF (English and Arabic).
3. **Given** another customer's invoice id, **When** a customer requests it or its PDF, **Then** the answer is 404 (no existence leak), enforced by row-level security as well as by the owner check.
4. **Given** an order in `ready_to_collect` or `completed`, **When** its party opens the order, **Then** a *View invoice* link opens their own invoice for that order.
5. **Given** the app in Arabic, **When** the customer opens the list and an invoice, **Then** every label, including those with numbers, is in Arabic.

---

### User Story 4 — A correction is a credit note, never an edit (Priority: P2)

An issued invoice never changes. When an invoice was wrong, a holder of the invoice-correction permission issues a credit note that reverses it — fully or in part — with a reason. The credit note has its own number (`CN-YYYY-NNNNNN`), names the invoice it reverses, the reason and the amount, has its own PDF, and is visible to the customer the invoice belongs to. A credit note can never exceed what is left of its invoice.

**Why this priority**: Required by the design and the permission matrix, but no built flow changes a sale after payment, so corrections are rare; it follows the issuing and reading stories.

**Independent Test**: Issue a credit note for part of an invoice — its number, reason and amount are stored, the invoice is unchanged, the remaining creditable amount falls; a second note larger than the remainder is refused; a COO is refused 403; the action is audited and idempotent.

**Acceptance Scenarios**:

1. **Given** a seller invoice of net 600, VAT 84, gross 684, **When** Finance issues a credit note of 684 with a reason, **Then** a credit note `CN-YYYY-NNNNNN` reversing that invoice is created, one balanced entry moves `dahab_commission −600`, `vat_payable −84`, the seller's available `+684`, the invoice row is unchanged, the seller sees the credit in their wallet history, and the action is audited with the reason.
6. **Given** a buyer invoice, **When** someone tries to credit it, **Then** it is refused and nothing is written.
2. **Given** an invoice with 300 already credited, **When** someone issues a credit note for more than the remaining 384, **Then** it is refused and nothing is written.
3. **Given** two credit-note requests for the same invoice at once whose sum exceeds the invoice, **When** both run, **Then** at most the invoice's amount is credited in total.
4. **Given** the same request sent twice with the same idempotency key, **When** it replays, **Then** only one credit note exists.
5. **Given** a credit note issued, **When** the customer opens the invoice in the app, **Then** they see the credit note against it and can download it.

---

### User Story 5 — No Tax Authority status is faked (Priority: P2)

This spec does not file anything with the Egyptian Tax Authority. The Dashboard and the app never claim an invoice is "registered", "rejected" or "filed" with the Tax Authority, and there is no *Send again*.

**Why this priority**: Showing a filing status that did not happen would be a false statement about a legal obligation.

**Independent Test**: No response field, screen or PDF claims a Tax Authority status; `eta_reference` is empty on every invoice; the Dashboard shows a *Status* column, a *Net invoiced* figure, no *Send again*, and a lead line saying e-invoicing is not connected yet.

**Acceptance Scenarios**:

1. **Given** any issued invoice, **When** it is read by staff or the customer, **Then** its Tax Authority reference is empty and no text says it was filed or registered.
2. **Given** the Customer App invoice screens, **When** they render, **Then** the prototype's "Filed with the Egyptian Tax Authority…" / "Every invoice is also filed with the Tax Authority under your name" lines are not shown as fact.

---

### User Story 6 — Orders paid before this spec get their invoices (Priority: P3)

Orders already settled before this spec was installed are **not** backfilled (FR-021): they keep no invoice, and every screen handles an invoice-less paid order gracefully.

**Why this priority**: A data-completeness concern that matters once, at installation.

**Independent Test**: An order settled before installation shows no invoice link in the app and no row in the Dashboard list, and its order screens load without error.

---

**Acceptance Scenarios**:

1. **Given** an order paid before installation, **When** either party opens it, **Then** the order loads and no *View invoice* link appears.

---

### Edge Cases

- A settlement where VAT is 0 (commission waived — market maker, not built) → no market-maker flow exists yet; every built settlement has commission ≥ the minimum, so the seller's invoice always has VAT > 0.
- A negative spread (allowed by the built settlement) → not on any invoice; the spread is never invoiced (no VAT, not a service fee).
- Rounding residue posted to `dahab_spread`/`dahab_commission` → the invoice shows the commission and VAT **as posted**, so it always reconciles with the ledger.
- The VAT percentage or commission settings change after a sale → the invoice keeps the figures of its settlement; the PDF never recomputes from current settings.
- An order disputed after payment (`ready_to_collect` → `disputed`) → the invoices stand; a dispute resolution after payment never reverses the sale in the built system, so it never issues a credit note automatically.
- A customer suspended or closed later → they keep read access to their own invoices as they do to their wallet history (no trade gate on reading).
- The PDF fails to render at settlement, or Dahab's details are not configured yet → the payment and the invoice row still commit; the document is built once possible and downloads answer a clear "not ready yet" until then.
- A credit note on an invoice that is already fully credited → refused.
- A customer asks for an invoice of an order they are not a party to → 404.

## Requirements *(mandatory)*

### Functional Requirements

**Issuing**

- **FR-001**: The system MUST issue the order's tax invoices automatically inside the pay-balance settlement transaction — never at acceptance, inspection or handover, and never by hand.
- **FR-002**: The system MUST hold at most one invoice per order per party role, enforced by a unique constraint, so that concurrent or replayed payments can never issue a second invoice for the same party.
- **FR-003**: If writing an invoice fails, the settlement MUST roll back with it (all or nothing).
- **FR-004**: The seller's invoice MUST state Dahab's service: net = the commission posted at settlement, VAT = the VAT posted, gross = net + VAT; plus, for information, the settlement figures the prototype shows (gold value at the locked rate and weight, or the asking price for stones; making charge back; gross; *Paid to your wallet* = the seller's settlement credit). The spread never appears as a charge and never carries VAT.
- **FR-005**: The buyer's invoice MUST state the price paid with VAT 0: net = gross = `buyer_total` as settled, VAT = 0, broken down as gold value at the buyer's locked sell rate on the measured weight plus the making charge (gold), or the asking price (diamond / gold with diamond). The spread is not shown as a separate charge.
- **FR-006**: Every invoice MUST carry a unique, sequential, human-readable number formed from the order reference and the party — `DH-YYYY-NNNNNN-S` for the seller, `DH-YYYY-NNNNNN-B` for the buyer — (unique, never reused), the issue date (Cairo), the party's name and customer reference (copied once when the document is generated — the buyer's payment cannot read the seller's record — and frozen), the piece (category, karat, measured weight, piece type), the order reference, and Dahab's legal name and tax details.
- **FR-007**: Invoice amounts MUST be stored to 4 dp, exactly as posted; no invoice figure may be recomputed after issue from current settings or prices.
- **FR-008**: Dahab's legal name (EN/AR), address (EN/AR), tax registration number and commercial register number MUST come from a Backend PHP configuration file (not environment variables), never from a frontend and never in the public Customer App repository. They MUST be copied onto each invoice and credit note at issue when the configuration is complete, otherwise once when its document is generated; once copied they never change, so a later configuration change never alters an issued document. Missing details MUST NOT block settlement or a credit note: the record is issued, and its document is generated once the configuration is complete (until then a download answers that the document is not ready).

**Reading — staff**

- **FR-009**: Two new permissions MUST be added to the catalogue and seeded to CEO and Finance only (never the COO), reaching existing databases through the additive roles-and-permissions seeder: `invoice.view` (the invoices and credit-notes lists, figures, details, PDFs and CSV export) and `invoice.correct` (*Issue or correct a tax invoice* — issue a credit note; the Dashboard also requires `invoice.view` to reach the page).
- **FR-010**: Staff with `invoice.view` MUST be able to list invoices newest first, filtered by period (Cairo dates; presets 30/60/90 days, this year, custom), party role and search (invoice number, order reference, customer reference/name), with keyset paging.
- **FR-011**: The list MUST come with figures for the current month — *Issued this month* (count), *Net invoiced* (seller-invoice net), *VAT collected* (seller-invoice VAT less credit-note VAT), *Credit notes* (count and amount) — and the same totals for the selected period, and each row's status (*Issued*, *Partly credited*, *Credited*).
- **FR-012**: Staff MUST be able to open an invoice's detail with its credit notes, and download its PDF; each PDF view is audited.
- **FR-013**: Staff MUST be able to export the filtered invoice list as CSV (same columns as the list); each export is audited.
- **FR-014**: Staff MUST be able to list credit notes (number, reverses, reason, amount, date, issued by) with the same period filter.

**Reading — customer**

- **FR-015**: A customer MUST be able to list their own invoices (and credit notes against them), filter Sold / Bought, open one, and download its PDF; access to another customer's invoice answers 404 and is also blocked by the database's row-level security.
- **FR-016**: Reading invoices MUST NOT require the trade gate; a suspended customer can still read them.
- **FR-017**: The customer's order detail MUST expose a reference to their own invoice once the order is paid, so the app can link *View invoice*.

**Credit notes**

- **FR-018**: An issued invoice MUST never be edited or deleted (database-enforced); a correction is a credit note referencing it, with a reason (10–1000 characters) and an amount.
- **FR-019**: A credit note MUST be issued only by hand by a holder of `invoice.correct`, under an `Idempotency-Key`, audited with the reason; the sum of an invoice's credit notes MUST never exceed its gross, enforced under a row lock and by the database. Credit notes have their own sequence `CN-YYYY-NNNNNN`.
- **FR-020**: A credit note MUST apply to a **seller** invoice only and MUST move money in the same transaction as its record: one balanced ledger entry through the money service — `dahab_commission −net`, `vat_payable −vat`, the seller's `cust_available +gross` — with the staff member as actor. Its net and VAT split follows the invoice's VAT rate (VAT = gross × rate / (100 + rate), rounded half-up to 4 dp, residue on the net side). A buyer invoice cannot be credited (`invoice_not_creditable`). No built event issues a credit note automatically (nothing reverses a sale after payment). The seller is told by SMS and email (as for compensation), and the entry shows in their wallet history as *Invoice correction* linked to the invoice.

**Backfill, ETA, documents**

- **FR-021**: Orders settled before this spec MUST NOT be backfilled: they have no invoices, their order screens show no invoice link, and nothing fails because an invoice is missing.
- **FR-022**: `eta_reference` MUST stay empty; nothing is sent to the Tax Authority; no response, screen or PDF may state a Tax Authority status. In the Dashboard the design's *Tax Authority* column becomes **Status** — *Issued*, *Partly credited*, *Credited*, derived from the invoice's credit notes; the *Rejected by the Tax Authority* figure becomes **Net invoiced** (commission invoiced this month); *Send again* is removed; the lead text says e-invoicing with the Tax Authority is not connected yet. The Customer App drops the prototype's "filed with the Tax Authority" lines.
- **FR-023a**: Documents MUST be generated **after** the issuing transaction commits (never inside the payment or the credit note), so a rendering failure or missing configuration never rolls back money; generation retries until it succeeds, and a download before then answers that the document is not ready.
- **FR-023**: Each invoice and credit note MUST have exactly one **bilingual** PDF (every label in English and Arabic, Arabic shaped right-to-left), stored privately (encrypted, never public) and referenced from the record; the same file is downloaded by the customer and by staff. Layout follows the prototype's `#s-invoice`: number and date; the party and their reference; the piece; the settlement lines (seller: gold value at the locked rate and weight or the asking price, making charge back, gross; buyer: the same lines to the price paid); Dahab's charges with net, VAT rate and VAT shown separately (seller); *Paid to your wallet* (seller) or *Total paid* (buyer); the order reference; Dahab's copied details. A credit note shows its number, date, the invoice it reverses, the reason, net, VAT and gross. Documents are generated from the stored figures only.
- **FR-024**: Every staff view or download of an invoice/credit note document and every export MUST be audited; a customer downloading their own invoice or credit note is not audited (not a privileged access).

**Frontends**

- **FR-025**: The Dashboard *Invoices* page MUST replace its placeholder: figures, invoices list with period filter, search and CSV export, credit notes list, invoice detail with PDF, and (if allowed) *Issue a credit note* in a DModal with an idempotency key; everything gated on the Backend permission strings; the navigation item shown only to holders.
- **FR-026**: The Customer App MUST serve *Transactions and invoices* (R.invoices) and the invoice screen (R.invoice) from the live API with download, make the Account menu item live, link the order screen and the *Sale settled* wallet line to the invoice, remove those MOCK flags, and have Arabic for every new string; any prototype mock screen duplicating these is routed to the live one.

### Key Entities

- **Tax invoice**: one per order per party role; party, customer, order; number; net, VAT, gross; issued at; private document reference; empty Tax Authority reference. Immutable once issued.
- **Credit note**: a correction against exactly one seller invoice; number `CN-YYYY-NNNNNN`; reason; gross with its net/VAT split; the ledger entry that refunded it; issued by (staff); issued at; private document reference. Immutable.
- **Dahab's tax identity**: legal name (EN/AR), address (EN/AR), tax registration number, commercial register number — a Backend PHP configuration file; a copy is stored on every invoice and credit note at issue.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of balance payments settled after installation have exactly two invoices (seller `-S`, buyer `-B`), issued at the payment moment; 0 duplicates under concurrent payments in the concurrency tests.
- **SC-002**: For any period, seller-invoice net and VAT minus credit-note net and VAT equal the net `dahab_commission` and `vat_payable` movements of the settlement and credit-note entries (reconciliation test), to the piastre; buyer-invoice gross equals the settlements' `buyer_total`.
- **SC-003**: No credit note total ever exceeds its invoice, including under concurrent requests (concurrency test).
- **SC-004**: A customer reaches any of their invoices from Account in at most two taps and can download it; no customer can read another's invoice (isolation test).
- **SC-005**: Finance can produce the month's invoice list and VAT total as a CSV in one action; every export and staff document view appears in the audit log.
- **SC-006**: No screen, document or response states a Tax Authority filing status.

## Assumptions

- Invoices are issued in EGP; amounts are the posted 4-dp figures, shown rounded to 2 dp on documents.
- The invoice's VAT rate shown is the rate in force at settlement, stored with the invoice (not re-read).
- Market-maker sales and the first-sale advance are not built; their invoice treatment is out of scope until they are.
- Invoice numbers are `order_ref` + `-S`/`-B` (specify answer); credit notes `CN-YYYY-NNNNNN` from the design.
- Tax-record retention (open-questions §1) is not decided; invoices are never deleted in this spec.
- The original invoice a seller uploads with a listing (`listing_invoice`) is a different thing and is not touched.

## Decisions taken without a question

- Approved by the user on 2026-10-05 (end of analyze): the seller is told by SMS and email on a credit note (like compensation); the prototype's *Download all as one file* is removed (no source defines it).

- Document generation runs after the commit (FR-023a) — this follows from the answer that missing configuration never blocks settlement.
- A credit note's reason is 10–1000 characters; partial and full credit notes are both allowed.
