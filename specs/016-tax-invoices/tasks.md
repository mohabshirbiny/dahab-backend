# Tasks: Tax invoices and credit notes (no ETA integration)

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/invoices-api.md](./contracts/invoices-api.md), [quickstart.md](./quickstart.md)

**Tests**: required (Constitution V; the user's brief: concurrency and reconciliation like specs 012–015 — two pay-balance attempts at once never issue two invoices; invoice totals reconcile with the ledger's commission and VAT lines; a credit note never exceeds its invoice). Work test-first within each story: write the test, watch it fail for the expected reason, implement, then run it.

**Database rules**:
- Run artisan and the tests as the `dahab` DB user, passing `DB_DATABASE=… DB_USERNAME=dahab DB_PASSWORD=secret` explicitly.
- **Never** run the suite, `migrate:fresh`, a rollback or a seeder against the main `dahab` database. Tests: `dahab_wt016`; seeded app: `dahab_wt016_dev`; both owned by `dahab` (as postgres: `CREATE DATABASE dahab_wt016 OWNER dahab;`, `CREATE DATABASE dahab_wt016_dev OWNER dahab;`).
- Run the suite **sequentially**. Any test that commits data puts back every kept table it changes (settings, karat adjustments). New test helper functions have names unique across the suite (prefix `inv016…`).

**Paths**:
- Backend paths are relative to the repo root (worktree `tax-invoices-credit-notes-6690eb`, branch `feature/invoices`). LF. Pint only on changed files.
- Dashboard paths are under `D:\laragon\www\dahab-dashboard\.claude\worktrees\invoices` (branch `feature/invoices` from `main` 01578f3, created). CRLF — edit with Edit/Write.
- Flutter paths are under `D:\laragon\www\dahab-flutter\.claude\worktrees\invoices` (branch `feature/invoices` from `main` 191f9c8, created). CRLF. Public repo: nothing secret (no tax IDs, no issuer details). `dart format --line-length 180` only on changed files. Its `API_BASE_URL` defaults to `http://127.0.0.1:8000/api/v1`; run it on a port listed in `CORS_ALLOWED_ORIGINS`.

**Git**: never commit or push unless told; stage only changed files (never `git add -A`), `git status` right before every commit. Before each phase, check `git status` and file modification times in all three projects (another session may write the same tree).

**Every endpoint task** includes its `#[OA\…]` attributes; its Postman request in `postman/Dahab-Backend.postman_collection.json` (folder "Invoices" for staff, "Customer invoices" for customer; body in step with the FormRequest; the `Idempotency-Key` pre-request script on every POST; saved-id scripts `invoice_id`, `buyer_invoice_id`, `credit_note_id`); and the permission middleware on the route. It is not done without them.

## Format: `[ID] [P?] [Story] Description`

User stories (spec.md): **US1** invoices issued at payment (P1) · **US2** Finance sees and exports invoices (P1) · **US3** the customer sees and downloads invoices (P1) · **US4** credit notes (P2) · **US5** no Tax Authority status faked (P2) · **US6** no backfill (P3).

---

## Phase 1: Setup (docs first — Constitution III)

- [X] T001 Create the databases `dahab_wt016` and `dahab_wt016_dev` (owner `dahab`); run the current backend suite on `dahab_wt016` and confirm it is green before any change; confirm the dashboard and Flutter worktrees are clean on `feature/invoices`.
- [X] T002 `composer require mpdf/mpdf:^8.2` (research R9); confirm `gd` and `mbstring` are loaded; record the version in `composer.lock`.
- [X] T003 Write `docs/features/invoices.md` from `docs/features/_TEMPLATE.md`: impact summary and classification (plan.md), the 9 clarifications in short, the endpoint table (contracts), permissions and seeds, recorded deviations (plan.md), the reported commission-VAT disagreement, follow-ups, links to `specs/016-tax-invoices/`; add it to `docs/features/README.md`.
- [X] T004 Update `docs/Database schema/04_schema_market.sql` `tax_invoice` as built (data-model.md: `invoice_no`, `party_role TEXT CHECK IN ('seller','buyer')`, `vat_rate`, `lines`, `issuer`, `party`, `document_at`, the four CHECKs, the guard and reconciliation triggers, forced RLS; comment "issued at pay-balance (spec 016), not filed with ETA — Part 4 §4 not integrated") and add `credit_note` + `credit_note_no_seq` with its triggers and RLS; `01_schema_core.sql` `ledger_event_kind` + `credit_note`; each change marked "spec 016"; mirror in `00_schema_full.sql` (and the `-- tax_invoice is not created yet` note updated).
- [X] T005 Amend the Technical Spec, each change "Changed by spec 016" with a link: Part 1 §4.2 (*Issue or correct a tax invoice* → `invoice.correct`; new `invoice.view`; both CEO + Finance), §5.1 (RLS on `tax_invoice`, `credit_note`); Part 2 §7 pay-balance (invoices issued as built: numbers `DH-…-S/-B`, buyer invoice VAT 0, documents after commit), a new invoices section (`/dashboard/invoices*`, `/dashboard/credit-notes*`, `/customer/me/invoices*`, `/customer/me/credit-notes/{id}/pdf`, `invoice` on orders, `invoice_id` on wallet movements), §12 errors (`invoice_not_creditable`, `credit_exceeds_invoice`, `document_not_ready`); Part 3 §2.5 note (VAT added on top of commission as built; the blueprint/prototype's "VAT included" wording disagrees — reported); Part 4 §4 ("not integrated by spec 016; `eta_reference` stays empty").
- [X] T006 [P] Update `docs/platform/api-contract.md`: surfaces (new paths), binary PDF downloads (`application/pdf`, `Content-Disposition`, `409 document_not_ready`), the two codes, idempotency list (+ credit notes), error codes, CSV export list (+ invoices), wallet movement kinds (+ `credit_note`).

---

## Phase 2: Foundational (blocks every story)

- [X] T007 Write `tests/Feature/Invoices/InvoiceSchemaTest.php` (fails first): both tables exist with forced RLS and policies; every CHECK of data-model.md (`gross_amount = net_amount + vat_amount`; "`party_role = 'seller' OR (vat_amount = 0 AND vat_rate = 0)`"; suffix `-S`/`-B` ↔ role; `(storage_ref IS NULL) = (document_at IS NULL)`; credit note reason "10–1000 characters", `net_amount > 0`, `vat_amount >= 0`, `gross_amount > 0`); `UNIQUE (order_id, party_role)`, `UNIQUE invoice_no`, `UNIQUE credit_note_no`, `UNIQUE ledger_txn_id`; the guard triggers refuse DELETE and any UPDATE except `issuer`/`party`/`storage_ref`+`document_at` from NULL to a value, once (DH012); the deferred reconciliation trigger refuses an invoice whose amounts or party differ from its order's settlement (DH012); the credit-note cap trigger refuses a buyer invoice and a sum above gross (DH012); the recorded trigger refuses a credit note without its exact `credit_note` entry (DH012); `ledger_event_kind` has `credit_note`; both tables truncatable.
- [X] T008 Write `database/migrations/2026_10_09_000010_create_tax_invoices.php` from the updated schema (T004): `ALTER TYPE ledger_event_kind ADD VALUE IF NOT EXISTS 'credit_note'`; `tax_invoice` and `credit_note` (columns, CHECKs and indexes of data-model.md); `credit_note_no_seq` (owned by the column; `credit_note_no` default `'CN-' || to_char(now() AT TIME ZONE 'Africa/Cairo','YYYY') || '-' || lpad(nextval('credit_note_no_seq')::text, 6, '0')`); triggers `trg_tax_invoice_guard`, `trg_tax_invoice_reconciled` (deferred, reads the order and the entry's postings in the `ledger` scope like `wallet_adjustment_recorded()`; compares the invoice with the order's `commission_amount`/`vat_amount`/`final_buyer_total` and the seller invoice with the entry's `dahab_commission`/`vat_payable` lines — exact as built, analysis U2), `trg_credit_note_cap` (BEFORE INSERT, `SELECT … FOR UPDATE` on the invoice), `trg_credit_note_guard`, `trg_credit_note_recorded` (deferred); the new enum value is referenced only inside trigger function bodies — never in a CHECK, index or default of this migration (PostgreSQL forbids using it in the transaction that adds it; analysis L2); forced RLS (`tax_invoice`: `USING ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))`, `WITH CHECK ((SELECT dahab_rls_elevated()) OR ((SELECT dahab_rls_scope()) = 'order' AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = tax_invoice.order_id AND o.buyer_id = (SELECT dahab_current_customer_id()))))`; `credit_note`: same USING, `WITH CHECK ((SELECT dahab_rls_elevated()))`); `down()` refuses while any row exists, else drops tables, sequence and functions and leaves the enum value (R16). Run T007 green.
- [X] T009 [P] `bootstrap/app.php`: map DH012 raised by the credit-note cap trigger to `credit_exceeds_invoice` / `invoice_not_creditable` (by message tag) and any other DH012 to a logged 500; `app/Exceptions/DomainApiException.php` + `invoiceNotCreditable()` 409, `creditExceedsInvoice(string $remaining)` 422 with `details.remaining`, `documentNotReady()` 409.
- [X] T010 [P] Enums: `app/Enums/PartyRole.php` (`seller`, `buyer`; `suffix()` `-S`/`-B`), `app/Enums/InvoiceStatus.php` (`issued`, `partly_credited`, `credited`; `from(credited, gross)`); `LedgerEventKind` + `CREDIT_NOTE = 'credit_note'` with `staffLabel()` "Invoice correction (credit note)"; `StaffPermission` + `INVOICE_VIEW = 'invoice.view'`, `INVOICE_CORRECT = 'invoice.correct'` with labels; `AuditEvent` + `credit_note.issued`, `invoice.document_viewed`, `credit_note.document_viewed`, `invoices.exported`.
- [X] T011 [P] `database/seeders/DashboardRolesAndPermissionsSeeder.php`: add both codes additively to `finance` (founders hold every code; never COO); extend the seeder test.
- [X] T012 [P] Models + factories: `app/Models/{TaxInvoice,CreditNote}.php` (casts: amounts string, `lines`/`issuer`/`party` array, `party_role` PartyRole; relations order, customer, creditNotes, invoice, issuedBy, ledgerTransaction; `isCreditable()`, `credited()`, `remaining()`, `status()`), `Order::invoices()`; `database/factories/{TaxInvoiceFactory,CreditNoteFactory}.php` building through the real pay-balance / credit-note Actions (no raw inserts — the triggers require real entries).
- [X] T013 [P] `config/dahab-invoices.php`: `issuer` with `legal_name_en`, `legal_name_ar`, `address_en`, `address_ar`, `tax_registration_no`, `commercial_register_no` as literal **empty** values (a PHP file, not env — Clarification; no source gives the values), `export_cap` 10000, `render_tries` 5; `app/Support/Invoices/IssuerDetails.php` (`complete(): bool` — all six non-empty; `snapshot(): ?array`).

---

## Phase 3: US1 — Invoices issued automatically at payment (P1) 🎯 MVP

**Goal**: every balance payment issues `DH-…-S` and `DH-…-B` in the same transaction, matching the ledger; documents follow after commit. **Independent test**: spec US1 scenarios 1–5.

- [X] T014 [P] [US1] `tests/Feature/Invoices/IssueInvoicesTest.php`: pay-balance on a gold order → order `ready_to_collect` and exactly two invoices: seller `invoice_no = order_ref.'-S'`, net = `commission_amount`, VAT = `vat_amount`, gross = net + VAT, `vat_rate` = `vat.pct` at payment, `lines` (weight, seller's locked rate, gold value, making total, seller gross, paid to wallet = `seller_proceeds`, commission pct); buyer `-B`, net = gross = `final_buyer_total`, VAT 0, `lines` with the buyer's locked rate; `issued_at` = payment time; `eta_reference` NULL; `order.paid` audit has `invoice_numbers`; a diamond and a gold-with-diamond order (asking price, no spread); the Part 3 §3 worked example to the piastre; changing `vat.pct` afterwards leaves the invoice unchanged; an idempotent replay issues nothing new; a forced failure in issuing rolls back the payment (no settlement entry, order still `awaiting_balance`); with the issuer config complete `issuer` is the snapshot, with it empty `issuer` is NULL and payment still succeeds.
- [X] T015 [US1] `app/Support/Invoices/InvoiceLines.php` (builds the `lines` snapshot from `SettlementFigures`, the order, the listing and the buy request's locked rate) and `app/Support/Invoices/IssueTaxInvoices.php` (`issue(Order, Listing, BuyRequest, SettlementFigures, PricingRates): array{seller: TaxInvoice, buyer: TaxInvoice}` — inserts both rows in the current transaction and `order` scope; `issuer` snapshot when complete; dispatches `RenderTaxDocumentJob` `afterCommit()` for each). Insert with ids generated in PHP through a plain query-builder insert — **no `RETURNING`, no model reload** — because the buyer's `order` scope cannot read the seller's row (analysis U1); `lines` include `karat_label`, `piece_type_en`, `piece_type_ar` (analysis I3).
- [X] T016 [US1] `app/Support/Orders/OrderSettlement.php`: let `compute()` return / expose the `PricingRates` it used (the VAT rate in force), and `app/Actions/Orders/Customer/PayBalanceAction.php`: call `IssueTaxInvoices::issue()` after `moveOrder(… READY_TO_COLLECT …)`, add `invoice_numbers` to the `order.paid` audit context. Run T014 green; run the spec 012 order tests (pay-balance) green.
- [X] T017 [P] [US1] `tests/Feature/Invoices/InvoiceDocumentTest.php`: with a fake renderer bound — the job stores `tax-documents/{customer_id}/{number}.pdf.enc` encrypted, sets `storage_ref` + `document_at` once, fills `party` (`full_name`, `display_ref`) and `issuer` when NULL; with the issuer config empty the job leaves the document pending and `invoices:render-pending` renders it once the config is complete; a rendering exception leaves the payment committed and the row pending; a second run never re-renders a stored document; one test with the real `MpdfTaxDocumentRenderer` produces a PDF (`%PDF-` header) whose extracted text contains the invoice number and an Arabic label.
- [X] T018 [US1] `app/Support/Invoices/Documents/{TaxDocumentRenderer.php (interface: renderInvoice(TaxInvoice): string, renderCreditNote(CreditNote): string), MpdfTaxDocumentRenderer.php, TaxDocumentStore.php}` (mPDF A4, `autoScriptToLang`, `autoLangToFont`, OTL, an mPDF-bundled Arabic-capable font; store via `IdentityDocumentStorage::putAt`) and the views `resources/views/documents/invoice.blade.php`, `resources/views/documents/credit-note.blade.php` — one bilingual layout per FR-023 (number and date; party and reference; piece; settlement lines; seller: Dahab's charges with net, VAT rate and VAT shown separately, *Paid to your wallet*; buyer: *Total paid*, VAT 0; order reference; Dahab's copied details; no Tax Authority wording); bind the renderer in `AppServiceProvider`.
- [X] T019 [US1] `app/Jobs/RenderTaxDocumentJob.php` (system scope via `DatabaseActorEvents`, `tries` from config, backoff; fills `party` and `issuer` once, renders, stores, sets `storage_ref` + `document_at`; no-op when already stored or the issuer is incomplete) and `app/Console/Commands/RenderPendingTaxDocuments.php` (`invoices:render-pending`, `DatabaseActor::elevate('system')`, every pending invoice and credit note); `routes/console.php` every five minutes `withoutOverlapping()`. Run T017 green.

---

## Phase 4: US2 — Finance sees and exports invoices (P1, Backend)

**Goal**: list with figures, filters, status, search; detail; audited PDF and CSV; credit-notes list. **Independent test**: spec US2 scenarios 1–5.

- [X] T020 [P] [US2] `tests/Feature/Invoices/StaffInvoicesTest.php`: list newest first with number, date, order ref, party, customer ref/name, net, VAT, gross, credited, remaining, status, `document_ready`; filters `from`/`to` (Cairo dates, default last 30 days, `to ≥ from`, span ≤ 366 days), `party`, `status`, `q` (invoice number, order ref, customer ref, name; ≤ 100 chars); keyset pages; `meta.figures.month` and `.period` (`issued_count`, `net_invoiced` = Σ seller net, `vat_collected` = Σ seller VAT − Σ credit-note VAT, `credit_notes_count`, `credit_notes_amount`) equal sums of the rows; detail with credit notes, `creditable`, `customer`; PDF → `application/pdf`, audited `invoice.document_viewed`; pending document → `409 document_not_ready` (not audited); credit-notes list with period filter and `issued_by`; credit-note PDF audited `credit_note.document_viewed`.
- [X] T021 [P] [US2] `tests/Feature/Invoices/InvoiceExportTest.php`: CSV equals the filtered rows (Number, Date, Order, Party, Customer, Net, VAT, Gross, Credited, Status), BOM, formula-neutralised, audited `invoices.exported` with the filters; more than `export_cap` rows → truncated with the closing line and `X-Export-Truncated: true` (the platform's CSV convention).
- [X] T022 [US2] `app/Support/Invoices/{InvoiceQuery,InvoiceCursor,InvoiceFigures}.php` (one query with a `LEFT JOIN LATERAL` credited sum; status derived; figures in one aggregate per window) and `app/Actions/Invoices/{ListInvoicesAction,ShowInvoiceAction,ExportInvoicesAction,ViewInvoiceDocumentAction,ListCreditNotesAction,ViewCreditNoteDocumentAction}.php` (export per the compensation export technique; document views read via `IdentityDocumentStorage::read`, audited).
- [X] T023 [US2] `app/Http/Requests/Dashboard/Invoices/{ListInvoicesRequest,ListCreditNotesRequest}.php`, `app/Http/Resources/Staff/{InvoiceResource,CreditNoteResource}.php`, `app/Http/Controllers/Api/V1/Dashboard/{InvoiceController,CreditNoteController}.php`; routes under `staff.permission:invoice.view`: `GET /dashboard/invoices`, `/dashboard/invoices/export`, `/dashboard/invoices/{invoice}`, `/dashboard/invoices/{invoice}/pdf`, `/dashboard/credit-notes`, `/dashboard/credit-notes/{creditNote}/pdf` (UUID constraints); `#[OA]`; Postman. Run T020–T021 green.

---

## Phase 5: US3 — The customer sees and downloads invoices (P1, Backend)

**Goal**: own invoices list/detail/PDF, `invoice` on orders, `invoice_id` on wallet movements. **Independent test**: spec US3 scenarios 1–5 (backend side).

- [X] T024 [P] [US3] `tests/Feature/Invoices/CustomerInvoicesTest.php`: the seller lists one invoice under `role=seller`, the buyer one under `role=buyer`; detail with `lines`, `vat_rate`, `issuer`, credit notes; PDF download (`Content-Disposition` filename = number) and `409 document_not_ready`; not audited; another customer's invoice / credit note / PDF → 404; a suspended customer may read (verified gate); unverified → `verification_required`; `GET /customer/me/orders/{id}` has `invoice: {id, number}` once paid (own party's) and `null` before payment; wallet history `invoice_id` on the seller's and the buyer's `balance_payment` rows (own invoice) and on `credit_note` rows, `null` elsewhere.
- [X] T025 [US3] `app/Actions/Invoices/Customer/{ListOwnInvoicesAction,ShowOwnInvoiceAction,DownloadOwnTaxDocumentAction}.php` (customer scope; RLS does the isolation, the Action also checks `customer_id`), `app/Http/Requests/Customer/Invoices/ListOwnInvoicesRequest.php` (`role` in `seller|buyer`, `per_page` ≤ 50), `app/Http/Resources/Customer/{CustomerInvoiceResource,CustomerCreditNoteResource}.php`, `app/Http/Controllers/Api/V1/Customer/InvoiceController.php`; routes in a `customer.gate:verified` group: `GET /customer/me/invoices`, `/customer/me/invoices/{invoice}`, `/customer/me/invoices/{invoice}/pdf`, `/customer/me/credit-notes/{creditNote}/pdf`; `#[OA]`; Postman folder "Customer invoices".
- [X] T026 [US3] `app/Http/Resources/Customer/CustomerOrderResource.php` + `invoice` (the caller's own party invoice, eager-loaded without N+1 on the list) and `app/Actions/Wallet/ListCustomerWalletHistoryAction.php` + `invoice_id` (LEFT JOIN `tax_invoice` on the entry's `order_id` and the caller's `customer_id` for `balance_payment`; via `credit_note.ledger_txn_id` for `credit_note`); `WalletController` `#[OA]` (`invoice_id`, `kind` + `credit_note`), `CustomerOrder` schema. Run T024 green; spec 008/012 wallet and order tests green.

---

## Phase 6: US4 — Credit notes (P2, Backend)

**Goal**: `invoice.correct` credits a seller invoice in part or full; money moves; never above the remainder. **Independent test**: spec US4 scenarios 1–6.

- [X] T027 [P] [US4] `tests/Feature/Invoices/CreditNoteTest.php`: full credit of 684 on net 600 / VAT 84 at 14% → `CN-YYYY-000001`, entry kind `credit_note` with `dahab_commission −600`, `vat_payable −84`, seller available `+684`, staff actor, `order_id`; partial 300 → VAT = round½↑(300 × 14 / 114, 4) = 36.8421, net 263.1579; status `partly_credited` then `credited`; above the remainder → `422 credit_exceeds_invoice` with `details.remaining`; buyer invoice → `409 invoice_not_creditable`; amount ≤ 0, > 2 dp, reason < 10 or > 1000 → 422; Idempotency-Key required and replay creates one note; the invoice row unchanged; audited `credit_note.issued` (reason, amounts, invoice number, ledger txn); the seller notified after commit (`credit_note_issued`, SMS + email); the seller's wallet history shows `credit_note` with `invoice_id`; a suspended seller is still credited; `invoice.view` without `invoice.correct` → 403; whole ledger sums to zero.
- [X] T028 [US4] `app/Support/Invoices/CreditNoteSplit.php` (gross → VAT round½↑ at 4 dp, net = gross − VAT) and `app/Actions/Invoices/IssueCreditNoteAction.php` (one transaction in the staff scope: lock the invoice `FOR UPDATE`, refuse buyer / over-remainder, post through `PostLedgerEntryAction` with `LedgerEventKind::CREDIT_NOTE`, insert the row, dispatch `RenderTaxDocumentJob` after commit, audit, notify after commit).
- [X] T029 [US4] `app/Notifications/WalletNotification.php` + `credit_note_issued` (EN/AR inline like the existing events: "Dahab corrected invoice {number}: {amount} EGP added to your wallet.").
- [X] T030 [US4] `app/Http/Requests/Dashboard/Invoices/IssueCreditNoteRequest.php` (`amount` numeric > 0 with ≤ 2 dp, `reason` string 10–1000), `InvoiceController@storeCreditNote`; route `POST /dashboard/invoices/{invoice}/credit-notes` with `staff.permission:invoice.correct` and `idempotent`; `#[OA]`; Postman (saves `credit_note_id`). Run T027 green.

---

## Phase 7: US5 + US6 — No Tax Authority status, no backfill (P2/P3, Backend)

- [X] T031 [P] [US5] `tests/Feature/Invoices/NoTaxAuthorityTest.php`: no response field of any new endpoint claims a Tax Authority status (`eta_reference` never exposed, no `registered`/`rejected` values); the rendered views contain no "Tax Authority"/"مصلحة الضرائب" filing claim.
- [X] T032 [P] [US6] `tests/Feature/Invoices/NoBackfillTest.php`: an order paid before the migration (simulated by binding a no-op `IssueTaxInvoices` in the container for that payment only, analysis L1) has no invoice; its customer order detail has `invoice: null`, the staff list omits it, and `invoices:render-pending` creates nothing for it.

---

## Phase 8: Dashboard (US2, US4, US5)

- [X] T033 Types and services: `src/types/invoice.ts` (InvoiceSummary, InvoiceDetail, CreditNote, figures, filters), `src/types/staff.ts` (+ `invoiceView`, `invoiceCorrect` in `PERMISSIONS`); `src/api/endpoints.ts`; `src/services/invoice.service.ts` (list, detail, export blob + filename, pdf blob, credit notes list, credit-note pdf, issue credit note with idempotency key); `src/services/errors.ts` + `invoice_not_creditable`, `credit_exceeds_invoice` (shows `remaining`), `document_not_ready` messages.
- [X] T034 [P] `src/composables/useInvoices.ts` (Vue Query keys; invalidate list, detail and figures after a credit note).
- [X] T035 [US2] `src/pages/invoices/index.vue` + `src/components/invoices/{InvoiceFigures,InvoicesTable,CreditNotesTable,InvoiceDetailModal}.vue`: lead text from the design with "Invoices are issued automatically at settlement… e-invoicing with the Tax Authority is not connected yet." (no "sent to the Tax Authority"); four figures *Issued this month*, *Net invoiced*, *VAT collected* ("On commission only"), *Credit notes*; period select (*Last 30 days / 60 / 90 / This year / Custom dates*), search, party and status filters, *Export* (blob, truncation toast); columns Number, Date, Order, Party (Seller/Buyer), Customer, Net (commission on seller rows, price paid on buyer rows — analysis I2), VAT, Status (*Issued / Partly credited / Credited*), View; detail with lines, credit notes and *Open PDF* (pending → `document_not_ready` message); credit notes table *Credit note · Reverses · Why · Amount · Date · By*. No *Send again*, no Tax Authority column.
- [X] T036 [US4] `src/components/invoices/IssueCreditNoteModal.vue`: `DModal` with `useIdempotencyKey`; amount (≤ remaining, shown), reason (10–1000, counter); shown only on seller invoices and only with `invoice.correct`; errors mapped; refresh after success.
- [X] T037 `src/router/index.ts` (Placeholder → `pages/invoices/index.vue`, `meta.permission: 'invoice.view'`) and `src/mock/nav.ts` (Invoices shown with `invoice.view`, `hidden` removed); run `npm run type-check`, `npm run lint`, `npm run build`.

---

## Phase 9: Customer App (US3, US4, US5)

- [X] T038 [US3] `lib/models/invoice.dart` (InvoiceSummary, InvoiceDetail with lines and issuer, CreditNote from the API), `lib/models/order.dart` + `invoice` (id, number), `lib/models/wallet.dart` + `invoiceId` (the mock-only `InvoiceSummary` replaced), `lib/models/wallet_labels.dart` + `credit_note` ("Invoice correction", "Credit note", "Dahab corrected one of your invoices and added the difference to your wallet.").
- [X] T039 [US3] `lib/services/api/invoices_api.dart` (list with role + cursor, detail, `pdf(id)` / `creditNotePdf(id)` bytes via `ApiClient.getBytes(auth: true)`, `document_not_ready` → a typed result), `lib/services/repositories.dart` (`InvoiceRepository`), the mock repository's invoice data removed, and a bytes download in `lib/services/text_download*.dart` (web: Blob + anchor with the filename; stub: false).
- [X] T040 [US3] `lib/features/wallet/wallet_screens.dart`: `InvoicesScreen` live (All / Sold / Bought tabs, rows "{number} · {date}, sold/bought {piece}, {amount} EGP", empty and error states, load more), `InvoiceScreen` live by id (`R.invoice` takes the id; seller layout: settlement lines, Dahab charges with net, VAT at {rate}%, total, *Paid to your wallet*; buyer layout: price lines, VAT 0, *Total paid*; credit notes with their own download; *Download this invoice*; "not ready yet" message); remove *Download all as one file* and the "filed with the Tax Authority" lines; the wallet line with an `invoiceId` opens its invoice (*Open the invoice*); routing (`lib/routing/routes.dart`, `app_router.dart`) passes the id; the prototype's mock invoice routes point to the live screens.
- [X] T041 [US3] `lib/features/orders/order_screen.dart`: *View invoice* once `order.invoice` is present (ready to collect / completed); `lib/features/account/account_screen.dart`: *Transactions and invoices* live (no `mock: true`, no fake "14 records"); `lib/widgets/mock_flag.dart`: remove `R.invoices`, `R.invoice` from `mockScreens`.
- [X] T042 [US3] `lib/core/i18n/`: Arabic for every new string, with number patterns for "{n} records", "VAT at {rate}%", "{amount} EGP", invoice/credit-note number lines and dates.
- [X] T043 `test/test_app.dart` / `test/flows_test.dart` / `test/mock_flags_test.dart`: fake endpoints for invoices, invoice detail, PDF bytes, the order `invoice` field and wallet `invoice_id`; flows (list Sold/Bought, open, download, not-ready message, order → View invoice, wallet line → invoice, credit note in history with the Arabic label); mock-flag test updated; `dart format --line-length 180` on changed files; `flutter analyze`, `flutter test`, `flutter build web --release`.

---

## Phase 10: Polish & cross-cutting

- [X] T044 [P] `tests/Feature/Invoices/InvoicePermissionsTest.php`: every new staff route × (CEO, COO, Finance, Operations, Verification, IGI) per the seed (CEO + Finance only); `invoice.view` without `invoice.correct`; a frozen staff member refused; the POST without `Idempotency-Key` refused.
- [X] T045 [P] `tests/Feature/Invoices/InvoiceIsolationTest.php`: under the customer scope a direct `SELECT` on `tax_invoice` / `credit_note` returns only the caller's rows; a customer cannot `INSERT` an invoice outside the `order` scope nor for an order where they are not the buyer; a customer can never insert a credit note; the seller's `order` scope cannot issue invoices; the buyer's payment succeeds while the buyer still cannot read the seller's invoice (analysis U1).
- [X] T046 `tests/Feature/Invoices/InvoiceConcurrencyTest.php` (two connections, committed fixtures, then truncate with the existing keep lists; put back any setting changed): two pay-balance attempts at once (different idempotency keys) → one settlement, two invoices; two credit notes on one invoice whose sum exceeds it → at most the gross credited, the loser `credit_exceeds_invoice`; same idempotency key on two connections → one note; a credit note racing a render job → both succeed, the document set once.
- [X] T047 `tests/Feature/Invoices/InvoiceReconciliationTest.php`: several settled orders (gold, diamond, gold-with-diamond) with partial and full credit notes → Σ seller net − Σ CN net = the net `dahab_commission` movement of the `balance_payment` + `credit_note` entries; same for VAT and `vat_payable`; Σ buyer gross = Σ `final_buyer_total`; the seller's wallet rose by Σ CN gross; `ledger_global_zero = 0`; figures endpoint equals these sums.
- [X] T048 [P] `tests/Feature/Performance/InvoicesPerformanceTest.php`: staff list and figures < 300 ms p95 with 10,000 invoices; export of 10,000 rows < 10 s; customer list < 300 ms.
- [X] T049 Inventory tests: `PrincipalIsolationTest` route count (+11), `OpenApiGenerationTest` (new paths and schemas), `AuditCatalogueTest` (+4 events), `StaffDashboardAccessTest` and `StaffAuthorizationMigrationTest` (+2 codes), `LedgerSchemaTest` rollback order (the new migration first; enum value left); confirm the truncating suites (`OrderConcurrencyTest`, `OrdersPerformanceTest`, `WithdrawalConcurrencyTest`, `WithdrawalPerformanceTest`, `DisputeConcurrencyTest`, `DisputePerformanceTest`, `FinanceConcurrencyTest`) pass — no transition table added, keep lists unchanged; any test bound to the wallet movement `kind` enum updated.
- [X] T050 `database/seeders/LocalInvoiceSeeder.php` (+ `DatabaseSeeder`, local only) through the real Actions: two settled orders (gold, diamond) and one partial credit note; run on `dahab_wt016_dev`.
- [X] T051 Quality gates (`laravel-quality-gates`): `./vendor/bin/pint --test` on changed files, the full suite sequentially on `dahab_wt016`, `migrate:fresh` + rollback check (refused with rows, clean when empty), `composer swagger:generate`, `php artisan route:list --path=invoices`; N+1 review on both lists and the order list.
- [X] T052 CLAUDE.md "Current state" (Backend, Dashboard, Flutter lines for spec 016; "Invoices are not built" removed) and the roadmap memory; Postman README if variables were added.
- [X] T053 Step 5 report: per project, API classification, tests and builds, docs, what is still mock in each app; that `config/dahab-invoices.php` ships empty (documents wait until filled); that the main `dahab` DB needs `php artisan migrate` and `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder` (and `composer install` for mPDF); what else reaches the customer (the credit-note wallet line and its SMS/email — nothing else).

## As built (notes)

- T001: `dahab` has no CREATEDB; the user created `dahab_wt016` / `dahab_wt016_dev`. The baseline run happened after the first
  changes were in (the full suite was green: 1,937 tests). The worktree needed `composer install --ignore-platform-req=ext-pcntl`
  (Windows has no pcntl; Horizon requires it) and a local `.env` pointing at `dahab_wt016_dev`.
- The migration hit two PostgreSQL/PDO quirks: the jsonb `?` operator is read by PDO as a placeholder (now `jsonb_exists`), and in
  PL/pgSQL a bare `CASE … THEN` inside an `IF` condition is cut at the first `THEN` (now parenthesised); an apostrophe in an
  SQL comment also broke PDO's scanner.
- `KeysetPage::byTime()` resets the select list, so the credited sum is attached per page (`ListInvoicesAction::withCredited`).
- Show / own-download actions are folded into the controllers plus one `ViewTaxDocumentAction`; the customer credit-note
  schema lives on `CustomerInvoiceResource` (no separate resource class). `IssueTaxInvoices` is not final (a test replaces it).
- The export follows the platform CSV convention: truncated at `export_cap` with `X-Export-Truncated`, not refused.
- Documents are rendered by `TaxDocumentWriter` (shared by the job and `invoices:render-pending`) under a row lock; the party
  copy is `{full_name, display_ref}` and `full_name` may be null.
- Dashboard: `npm run lint` reports 46 errors, identical on `main` (01578f3) — none in the files of this change.
- Flutter: two flows (`switching the account in use…`, `withdraw in Arabic`) fail on `main` too since 2026-10-05 10:00 —
  a hard-coded pause end in the fixture; left alone (separate task suggested). The invoice list row shows the invoice total.
- CLAUDE.md said Flutter's `API_BASE_URL` defaults to `:8010`; the code (and the user) say `:8000` — corrected.

## Dependencies & execution order

- Phase 1 → Phase 2 → US1. US2, US3 and US4 depend on US1 (they read the invoices it issues); US2, US3, US4 are independent of each other afterwards (US4's status figures are asserted in US2 only through derived values). US5/US6 tests after US1–US4.
- Backend API before each consumer: Phase 8 needs US2 + US4 green; Phase 9 needs US3 + US4 green.
- Phase 10 after all backend stories; T046/T047 need US1 + US4.

## Parallel examples

- Phase 2: T009–T013 touch different files.
- US1: T014 + T017 written together; T018 (documents) alongside T015–T016 (issuing).
- After US1: US2 (T020–T023), US3 (T024–T026) and US4 (T027–T030) in parallel.
- Phase 8 (Dashboard) and Phase 9 (Customer App) in parallel once their backend stories are green.

## Implementation strategy

1. **MVP**: Phases 1–3 — invoices issued at every payment, reconciled with the ledger, with documents.
2. Then staff reads and export (US2), customer reads (US3), credit notes (US4), the two guard stories, the apps (Phases 8–9), and the polish.
