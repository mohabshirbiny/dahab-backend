# Research: Tax invoices and credit notes (spec 016)

Engineering choices behind [plan.md](./plan.md). Product decisions are in the spec's Clarifications; nothing here changes them.

## R1 — Paths and naming

- **Decision**: staff endpoints under `/dashboard/invoices*` and `/dashboard/credit-notes*`; customer endpoints under `/customer/me/invoices*` and `/customer/me/credit-notes/{id}/pdf`. Permission codes `invoice.view` and `invoice.correct` (Clarification).
- **Rationale**: the platform's built prefixes (Part 2's `/admin/*` became `/dashboard/*` in every spec since 002).
- **Alternatives**: `/dashboard/tax-invoices` — longer, and the Dashboard route and page are already `invoices`.

## R2 — Where and how invoices are issued

- **Decision**: a new `IssueTaxInvoices` support class called by `PayBalanceAction` right after `OrderSettlement::post()` and the order's move to `ready_to_collect`, inside the same transaction and the same `order` database scope. It inserts the two `tax_invoice` rows from the `SettlementFigures` just posted (never recomputed) plus the request's and order's locked rates. The order audit entry `order.paid` gains `invoice_numbers`.
- **Row-level security on insert (analysis U1)**: the buyer's `order` scope may insert the seller's invoice but cannot read it, and PostgreSQL applies the SELECT policy to `INSERT … RETURNING`. Invoices are therefore inserted with ids generated in PHP and **without** `RETURNING` or a reload (a plain query-builder insert); the action keeps the values it inserted. The read policy is not loosened.
- **Rationale**: FR-001/FR-003 — all or nothing. `PayBalanceAction` is the only settlement path (no sweep, dispute or staff action settles an order). The order row is already locked, so concurrent payments serialise; `UNIQUE (order_id, party_role)` is the backstop (FR-002).
- **Alternatives**: an after-commit listener — rejected, a crash between commit and listener would leave a settlement without invoices; a database trigger on `"order"` — rejected, the figures and rates live in the application's `SettlementFigures`.

## R3 — Figures stored on the invoice

- **Decision**: `net_amount`, `vat_amount`, `gross_amount` as the schema has them, plus a `vat_rate` column and a `lines` JSONB snapshot of the informational settlement lines (category, karat code and label, piece type id and its EN/AR names, measured weight, per-gram rate used, gold value, making charge total, asking price, seller gross / buyer total, paid to wallet). Seller: net = `commission_amount`, VAT = `vat_amount`, gross = net + VAT. Buyer: net = gross = `final_buyer_total`, VAT 0, `vat_rate` 0.
- **Rationale**: FR-004/FR-005/FR-007 — the document must never be recomputed from current settings; the order's columns hold the posted figures but not the rates, the piece description or the VAT rate in force.
- **Alternatives**: recompute at render from the order + listing — rejected (settings and the listing's description can change; piece type names can be edited).

## R4 — Numbering

- **Decision**: `invoice_no = order_ref || '-S'` (seller) / `'-B'` (buyer), `UNIQUE`. Credit notes: `'CN-' || to_char(now() AT TIME ZONE 'Africa/Cairo','YYYY') || '-' || lpad(nextval('credit_note_no_seq')::text, 6, '0')`, `UNIQUE`; the counter never resets (same rule as `order_ref_seq`).
- **Rationale**: specify answer Q2 and the design's `CN-2026-000031`.
- **Alternatives**: a per-year reset — the order numbers do not reset either; keeping one rule avoids a year-boundary race.

## R5 — Party and issuer snapshots under row-level security

- **Decision**: the issuer (Dahab's details) is copied from `config/dahab-invoices.php` into `issuer` JSONB at issue when the configuration is complete; otherwise `issuer` stays NULL and the document job copies it once it is complete. The party snapshot (`party` JSONB: full name, display reference) is written by the document job, which runs in the `system` scope. Both columns may go from NULL to a value exactly once; every other column is frozen by a trigger (DH012).
- **Rationale**: the buyer's payment runs in the non-elevated `order` scope and cannot read the seller's `customer` row (by design: the buyer never sees the seller). The document is generated once and stored; it is the legal copy. Clarification: "missing details never block settlement".
- **Alternatives**: elevating the payment to `system` — rejected, customer actions never elevate (OrderScopeTest); a `SECURITY DEFINER` function to read names — rejected, it widens the RLS surface for a cosmetic copy.

## R6 — Credit notes move money

- **Decision**: `IssueCreditNoteAction` (staff, `invoice.correct`, idempotent): locks the invoice row (`FOR UPDATE`), refuses a buyer invoice (`invoice_not_creditable`, 409), refuses more than `gross − credited` (`credit_exceeds_invoice`, 422 with `remaining`), splits gross into VAT = round½↑(gross × rate / (100 + rate), 4) and net = gross − VAT, posts one balanced entry through `PostLedgerEntryAction` with a new ledger kind **`credit_note`** — `dahab_commission −net`, `vat_payable −vat`, seller `cust_available +gross`, staff actor, `order_id` set — and inserts the `credit_note` row naming that entry. Audited `credit_note.issued` with the reason; the seller is told (SMS + email, `WalletNotification` `credit_note_issued`, like compensation).
- **Rationale**: Clarification (seller invoices only, money moves). A new kind keeps wallet history and the statement honest ("Invoice correction", not a generic "Correction"), and `one_reversal_per_txn` forbids several `reversal` entries pointing at the same settlement.
- **Alternatives**: kind `reversal` with `reverses_txn_id` = the settlement — rejected (one reversal per entry; partial notes); `reversal` without a reversed entry — rejected (indistinguishable from a wallet adjustment).
- **Database guards (DH012)**: a deferred constraint trigger on `credit_note` checks that its entry is kind `credit_note`, names the order, has exactly the three lines with the stated amounts and the staff actor; a `BEFORE INSERT` trigger locks the invoice row and refuses a credit when the sum of the invoice's credit notes plus the new one exceeds its gross, or the invoice is a buyer invoice. Both `credit_note` and `tax_invoice` are append-only (except the one-time snapshot/storage columns, R5).
- **Enum change**: `ALTER TYPE ledger_event_kind ADD VALUE 'credit_note'` (PostgreSQL 16 allows it inside the migration transaction; the value is used only by later transactions). `down()` cannot drop an enum value; it leaves the value in place (harmless, unused) and refuses once any invoice or credit note exists.

## R7 — Reconciliation guard on invoices

- **Decision**: a deferred constraint trigger on `tax_invoice` (DH012) checks at commit that the order is in a paid state with a `settlement_txn_id`, the party's `customer_id` matches the order's seller/buyer, and the amounts equal the order's `commission_amount`/`vat_amount` (seller) or `final_buyer_total` (buyer), and that the seller invoice's net/VAT equal the settlement entry's `dahab_commission` / `vat_payable` lines.
- **Rationale**: SC-002 enforced by the engine, not only by tests (the platform's pattern: DH007–DH011).
- **Rounding (analysis U2)**: verified in `PriceCalculator::finish()` — `seller_proceeds = seller_gross − commission − vat` and `spread = buyer_total − seller_gross` exactly, so the settlement posts `dahab_commission` = `commission_amount` and `vat_payable` = `vat_amount` with no residue (Part 3 §2.6's residue rule never fires as built). The trigger compares the invoice with the order's columns **and** the entry's lines; T014 covers diamond and gold-with-diamond so a future residue would surface as a failing test, not a silent mismatch.

## R8 — Row-level security

- **Decision**: `tax_invoice` and `credit_note` under **forced** RLS. Read: elevated, or `customer_id = dahab_current_customer_id()` (credit notes carry the seller's `customer_id`). Write: `tax_invoice` — elevated, or scope `order` and the order (visible through `order_isolation`) has `buyer_id` = the current customer (only the buyer's payment issues invoices); `credit_note` — elevated only (staff). The one-time `UPDATE`s run from the document job (system). The `ledger` scope used inside the money service does not see these tables.
- **Rationale**: Constitution II — isolation by the engine; FR-015 (another customer's invoice is invisible → 404).

## R9 — PDF generation

- **Decision**: `mpdf/mpdf` (^8.2) renders one bilingual A4 document from a Blade view (`resources/views/documents/{invoice,credit-note}.blade.php`): English left, Arabic right per label, with Arabic shaping (`autoScriptToLang`, `autoLangToFont`, OTL) and an embedded Arabic-capable font bundled with mPDF. Rendering is behind a `TaxDocumentRenderer` interface so tests can bind a fast fake; one test renders a real PDF and checks its header and that the Arabic text extracts.
- **Rationale**: FR-023 (one bilingual document). mPDF is pure PHP (gd, mbstring present on the dev machine), shapes Arabic and handles RTL; dompdf does not shape Arabic.
- **Alternatives**: dompdf — no Arabic shaping; headless Chrome (Browsershot) — needs Node/Chrome on every server; TCPDF — workable but weaker HTML/CSS layout.
- **Storage**: `IdentityDocumentStorage::putAt('tax-documents/{customer_id}/{number}.pdf.enc', $bytes)` — encrypted, private disk; `storage_ref` set once.

## R10 — When documents are generated

- **Decision**: after commit (`RenderTaxDocumentJob::dispatch(...)->afterCommit()`, `tries = 5`, backoff), and a scheduled `invoices:render-pending` (every five minutes, `withoutOverlapping`, system scope) that renders every invoice or credit note without a `storage_ref`, so missing configuration or a failed job heals itself. Downloads before then answer `409 document_not_ready`.
- **Rationale**: FR-023a — rendering never rolls back money.

## R11 — Customer reads

- **Decision**: `GET /customer/me/invoices` (keyset, newest first, `role=seller|buyer`), `GET /customer/me/invoices/{id}` (with its credit notes), `GET /customer/me/invoices/{id}/pdf`, `GET /customer/me/credit-notes/{id}/pdf` — gate `customer.gate:verified` (like the wallet: a suspended customer may read; FR-016). Not audited (Clarification). `CustomerOrderResource` gains `invoice: {id, number} | null` (the caller's own party invoice). Wallet history rows gain `invoice_id` (nullable) for `balance_payment` and `credit_note` entries — the caller's own invoice for that order — and the kind enum gains `credit_note`.
- **Rationale**: FR-015–FR-017 and the prototype's *Open the invoice* on a *Sale settled* line.
- **Not built**: the prototype's *Download all as one file* — no source defines the combined file; the button is removed (reported).

## R12 — Staff reads and export

- **Decision**: `GET /dashboard/invoices` — keyset (`issued_at DESC, invoice_id`), filters `from`/`to` (Cairo dates, default last 30 days), `party` (`seller|buyer`), `status` (`issued|partly_credited|credited`), `q` (invoice number, order reference, customer reference, name — trigram-free `ILIKE` on the indexed number/ref columns plus a name match), `meta.figures.month` and `meta.figures.period`. `GET /dashboard/invoices/export` (same filters, CSV, cap 10,000 rows then truncated with `X-Export-Truncated` (the platform's convention), audited `invoices.exported`) using the withdrawals/compensation export technique. `GET /dashboard/invoices/{id}` (detail + credit notes + order ref + customer ref/name). PDF downloads audited `invoice.document_viewed` / `credit_note.document_viewed`. `GET /dashboard/credit-notes` with the same period filter.
- **Status** is derived: `credited` when credited = gross, `partly_credited` when 0 < credited < gross, else `issued`; computed from a per-invoice `credited_amount` aggregate (a `LEFT JOIN LATERAL` sum), not stored.

## R13 — Errors

- **Decision**: new codes `invoice_not_creditable` (409), `credit_exceeds_invoice` (422, `remaining`), `document_not_ready` (409). SQLSTATE DH012 maps to `credit_exceeds_invoice` / `invoice_not_creditable` where the trigger fires (concurrency backstop) and to a 500 otherwise (a reconciliation failure is a bug).

## R14 — Concurrency and reconciliation tests

- **Decision**: on two real connections (the specs 012–015 harness): two pay-balance attempts at once → one settlement, two invoices; two credit notes on one invoice whose sum exceeds it → at most the gross credited; a credit note racing a second credit note with the same idempotency key → one row. Reconciliation: for a set of settled orders with partial and full credit notes, Σ seller net − Σ CN net = Σ `dahab_commission` movement of `balance_payment` + `credit_note` entries, same for VAT; Σ buyer gross = Σ `final_buyer_total`; the whole ledger sums to zero.
- **Keep lists**: `tax_invoice` and `credit_note` are not transition tables; the truncating suites truncate them with the rest. No kept table changes.

## R15 — Configuration file

- **Decision**: `config/dahab-invoices.php` returns `issuer` (`legal_name_en`, `legal_name_ar`, `address_en`, `address_ar`, `tax_registration_no`, `commercial_register_no`) as literal values (Clarification: a PHP file, not environment variables), plus `export_cap` and the render schedule. Values ship empty in this change (no source gives them); the user fills them in the backend repository (private). Nothing reaches the Flutter repository.
- **Completeness**: all six keys non-empty.

## R16 — Migration reversibility

- **Decision**: `down()` refuses once any `tax_invoice` or `credit_note` exists; otherwise drops both tables, the sequence and the functions; the enum value stays (R6). LedgerSchemaTest's rollback order gains the new migration first.
