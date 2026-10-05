# Implementation Plan: Tax invoices and credit notes (no ETA integration)

**Branch**: `feature/invoices` in all three repositories (backend worktree `tax-invoices-credit-notes-6690eb` from `main` 093c967; dashboard worktree `.claude/worktrees/invoices` from `main` 01578f3; Customer App worktree `.claude/worktrees/invoices` from `main` 191f9c8) | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

## Summary

- **Issuing**: inside `PayBalanceAction`'s transaction, after the settlement posts, two `tax_invoice` rows — `DH-…-S` (net = commission, VAT, gross) and `DH-…-B` (net = gross = buyer total, VAT 0) — with the VAT rate, a snapshot of the settlement lines, and Dahab's details when configured; `UNIQUE (order_id, party_role)`; a deferred DH012 check ties every invoice to its order's posted figures (R2–R5, R7).
- **Credit notes**: `invoice.correct` holders credit a seller invoice in part or in full with a reason; one balanced `credit_note` ledger entry (`dahab_commission −net`, `vat_payable −vat`, seller available `+gross`); `CN-YYYY-NNNNNN`; never above the remainder (row lock + DH012 trigger); seller told (R6).
- **Documents**: one bilingual PDF per invoice / credit note (mPDF, Arabic shaped), rendered after commit by a job and healed by `invoices:render-pending`; stored encrypted; `document_not_ready` until then (R9, R10).
- **Reads**: customer list/detail/PDF (verified gate, not audited), `invoice` on orders, `invoice_id` on wallet movements; staff list with figures, filters, status and CSV export, detail, PDFs (audited), credit-notes list (R11, R12).
- **No ETA**: `eta_reference` NULL; Status column, Net invoiced figure, no *Send again*, "not connected yet" lead line.
- **Dashboard**: the *Invoices* page replaces its placeholder. **Customer App**: *Transactions and invoices* and the invoice screen live, order and wallet links, `credit_note` wallet label, MOCK flags removed.

## Impact analysis

```
Backend:          YES — 1 migration; Actions Invoices/{ListInvoices,ShowInvoice,ExportInvoices,ViewInvoiceDocument,ListCreditNotes,ViewCreditNoteDocument,IssueCreditNote}, Customer/{ListOwnInvoices,ShowOwnInvoice,DownloadOwnDocument}; Support/Invoices/{IssueTaxInvoices,InvoiceQuery,InvoiceFigures,CreditNoteSplit,IssuerDetails,TaxDocumentRenderer (+MpdfRenderer)}; RenderTaxDocumentJob; invoices:render-pending; PayBalanceAction, ListCustomerWalletHistoryAction, CustomerOrderResource changed; 3 Requests, 4 Resources, 2 controllers; 3 error codes; 4 audit events; 1 notification; config/dahab-invoices.php; 2 Blade views; composer mpdf/mpdf; seeders, factories, tests, Postman
Database:         YES — tax_invoice (schema + invoice_no, vat_rate, lines, issuer, party, document_at), credit_note (+ sequence), ledger_event_kind + credit_note, DH012 triggers, forced RLS on both
API:              YES — 7 dashboard endpoints, 4 customer endpoints, 2 additive fields, 1 enum value
Dashboard:        YES — Invoices page (figures, invoices table, credit notes table, detail, Issue a credit note DModal); types/services/endpoints/permissions/errors; nav item by permission; Placeholder removed
Customer App:     YES — InvoicesScreen + InvoiceScreen live with PDF download, Account menu item live, order screen View invoice, wallet line Open the invoice, credit_note label; MOCK flags removed; EN/AR; fake backend + tests
Auth:             NO — sign-in, tokens, verified/trade gates unchanged
Permissions:      YES — invoice.view, invoice.correct (CEO + Finance)
API models/types: YES — Dashboard src/types/invoice.ts (+ permissions union); Flutter lib/models/invoice.dart, order.dart (+invoice), wallet.dart (+invoiceId)
```

**Classification**: non-breaking (new endpoints, optional fields). Potentially breaking: `credit_note` in the wallet movement `kind` enum (Flutter falls back to a generic label until updated — updated in this change), two new permission strings. Nothing renamed or removed.

## Technical Context

- **Language/Version**: PHP 8.3+ (8.4 locally) / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary dependencies**:
  - **Backend**: `OrderSettlement`/`SettlementFigures`, `PricingContext` (spec 012/005); `PostLedgerEntryAction`, `Account` (spec 008); `IdentityDocumentStorage::putAt/read` (encrypted private disk); `RecordAuditLogAction`; `idempotent` middleware; `DatabaseActor::order`/`elevate('system')`; the compensation/withdrawals export technique; `WalletNotification`; **new**: `mpdf/mpdf` ^8.2 (pure PHP; needs gd + mbstring — present).
  - **Dashboard**: TanStack Vue Query, `useIdempotencyKey`, `usePermissions`, `DModal`, the compensation page's table/filter/export patterns.
  - **Flutter**: `ApiClient.getBytes`, the web download helper (bytes variant), provider, `WalletLabels`.
- **Storage**: PostgreSQL 16; Redis (idempotency, queue); the private encrypted disk (`tax-documents/`).
- **Testing**: Pest through HTTP on `dahab_wt016` as `dahab`, sequential — every endpoint and refusal; schema tests (checks, DH012 guards, immutability, RLS); concurrency on two connections (R14); reconciliation; a real PDF render (header + Arabic text); performance (staff list with 10,000 invoices < 300 ms p95, export 10,000 rows < 10 s). A fake renderer is bound for the rest.
- **Constraints**: bcmath; one transaction per write, the money service's lock order (listing → order → accounts; credit note: invoice row → accounts); rendering outside every money transaction; Backend LF, Dashboard and Flutter CRLF; Pint and `dart format --line-length 180` on changed files only; nothing secret in the Flutter repo.
- **Scale/scope**: 11 endpoints, 1 Dashboard page (+1 modal), 2 Customer App screens + 2 links.

No open NEEDS CLARIFICATION: 9 answers in the spec; engineering choices R1–R16 in [research.md](./research.md).

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Invoices: the buyer's payment (actor on the entry and on `order.paid`); credit notes: `issued_by` + the entry's staff actor; document views and exports audited with the viewer; the document job runs as the system actor. | ✅ |
| II. Isolation by the engine; staff authz by data | Forced RLS on `tax_invoice` and `credit_note` (customer reads own; invoices written only in the buyer's `order` scope or elevated; credit notes elevated only); two catalogue codes, never role names. | ✅ |
| III. Docs first | Same change: `04_schema_market.sql` (`tax_invoice` as built, `credit_note`), `01_schema_core.sql` (event kind), `00_schema_full.sql`; Technical Spec Part 1 §4.2 (codes), Part 2 §7 (pay-balance issues invoices as built) + new §invoices, Part 3 §2.5 note (VAT on top, as built), Part 4 §4 (not integrated), §12 errors — "Changed by spec 016"; `api-contract.md`; `docs/features/invoices.md`; CLAUDE.md "Current state". | ✅ |
| IV. Foundation before modules | The migration mirrors the updated schema; each endpoint has Request, Resource, Action, Pest tests, `#[OA]`, Postman. | ✅ |
| V. Test the boundary and the ledger | Every money write (credit note) asserted through HTTP on balances and the whole-ledger zero; invoices asserted against the ledger; concurrency and reconciliation suites. | ✅ |
| Reversible migrations | `down()` refuses once any invoice or credit note exists; the enum value stays (R16). | ✅ (documented) |

No Constitution deviation.

**Recorded deviations from the schema / Technical Spec** (land in `docs/` in the same change; **approved by the user on 2026-10-05**):

- `tax_invoice` gains `invoice_no`, `vat_rate`, `lines`, `issuer`, `party`, `document_at`; `storage_ref`, `issuer`, `party` settable once (the schema has no immutability rule for it).
- New table `credit_note`, new ledger kind `credit_note`, SQLSTATE DH012.
- `tax_invoice.party_role` is TEXT + CHECK (the schema's `party_role` enum type was never created; the built `compensation.party` uses TEXT + CHECK).
- The schema comment "issued automatically at completion, filed with ETA" → issued at payment (Part 2 §7 already says so); not filed.
- Paths `/dashboard/*`, `/customer/me/*` (R1); new error codes (R13); `invoice.view` (not in the matrix — Clarification).
- Blueprint/prototype "commission VAT included" vs. the built "VAT on top" — the invoice follows the built ledger (reported in the spec).

## Project Structure

### Documentation

```text
specs/016-tax-invoices/{spec,plan,research,data-model,quickstart}.md, contracts/invoices-api.md, checklists/requirements.md, tasks.md (next)
docs/features/invoices.md (+ README index)
```

### Source Code

```text
backend (LF)
  docs/Database schema/{00,01,04}_*.sql · docs/Technical Spec/part{1,2,3,4}.md · docs/platform/api-contract.md · docs/features/{invoices.md,README.md} · CLAUDE.md
  composer.json/lock (+mpdf/mpdf)
  database/migrations/2026_10_09_000010_create_tax_invoices.php
  database/seeders/{LocalInvoiceSeeder.php (+DatabaseSeeder, local only), DashboardRolesAndPermissionsSeeder (+2)} · database/factories/{TaxInvoiceFactory,CreditNoteFactory}.php
  config/dahab-invoices.php
  app/Enums/{StaffPermission (+2), AuditEvent (+4), LedgerEventKind (+credit_note), PartyRole (new), InvoiceStatus (new)}.php
  app/Models/{TaxInvoice,CreditNote}.php · Order (invoices relation)
  app/Support/Invoices/{IssueTaxInvoices,InvoiceQuery,InvoiceCursor,InvoiceFigures,CreditNoteSplit,IssuerDetails,InvoiceLines}.php
  app/Support/Invoices/Documents/{TaxDocumentRenderer (interface),MpdfTaxDocumentRenderer,TaxDocumentStore}.php · resources/views/documents/{invoice,credit-note}.blade.php
  app/Jobs/RenderTaxDocumentJob.php · app/Console/Commands/RenderPendingTaxDocuments.php · routes/console.php (every five minutes)
  app/Actions/Orders/Customer/PayBalanceAction.php (issue) · app/Actions/Wallet/ListCustomerWalletHistoryAction.php (+invoice_id)
  app/Actions/Invoices/{ListInvoicesAction,ExportInvoicesAction,ShowInvoiceAction,ViewInvoiceDocumentAction,ListCreditNotesAction,ViewCreditNoteDocumentAction,IssueCreditNoteAction}.php
  app/Actions/Invoices/Customer/{ListOwnInvoicesAction,ShowOwnInvoiceAction,DownloadOwnTaxDocumentAction}.php
  app/Notifications/WalletNotification.php (credit_note_issued) · lang/{en,ar}
  app/Exceptions/DomainApiException.php (+3) · bootstrap/app.php (DH012)
  app/Http/Requests/Dashboard/Invoices/{ListInvoicesRequest,ListCreditNotesRequest,IssueCreditNoteRequest}.php · Customer/Invoices/ListOwnInvoicesRequest.php
  app/Http/Resources/Staff/{InvoiceResource,CreditNoteResource}.php · Customer/{CustomerInvoiceResource,CustomerCreditNoteResource}.php · Customer/CustomerOrderResource (+invoice)
  app/Http/Controllers/Api/V1/Dashboard/{InvoiceController,CreditNoteController}.php · Customer/InvoiceController.php · Customer/WalletController (OA: invoice_id, kind) · routes/api.php
  postman/Dahab-Backend.postman_collection.json (folder "Invoices"; customer invoices; order/wallet examples)
  tests/Feature/Invoices/{InvoiceSchemaTest,IssueInvoicesTest,InvoiceDocumentTest,CustomerInvoicesTest,StaffInvoicesTest,InvoiceExportTest,CreditNoteTest,InvoicePermissionsTest,InvoiceIsolationTest,InvoiceConcurrencyTest,InvoiceReconciliationTest}.php · tests/Feature/Performance/InvoicesPerformanceTest.php
  inventory: PrincipalIsolationTest (route count), OpenApiGenerationTest (paths/schemas), AuditCatalogueTest, StaffDashboardAccessTest, StaffAuthorizationMigrationTest, LedgerSchemaTest (rollback order); truncating suites truncate the two new tables (no keep-list change)
dashboard (CRLF)
  src/api/endpoints.ts · src/types/{invoice.ts, staff.ts (permissions)} · src/services/{invoice.service.ts, errors.ts}
  src/composables/useInvoices.ts · src/mock/nav.ts (Invoices shown with invoice.view) · src/router/index.ts (Placeholder → page)
  src/pages/invoices/index.vue · src/components/invoices/{InvoiceFigures,InvoicesTable,CreditNotesTable,InvoiceDetailModal,IssueCreditNoteModal,invoiceErrors}.{vue,ts}
flutter (worktree, public repo — nothing secret)
  lib/models/{invoice.dart (new), order.dart (+invoice), wallet.dart (+invoiceId; InvoiceSummary replaced), wallet_labels.dart (+credit_note)}
  lib/services/api/{invoices_api.dart (new), orders_api.dart, wallet_api.dart} · repositories.dart · mock_repositories.dart (invoice mock removed) · text_download*.dart (+bytes)
  lib/features/wallet/wallet_screens.dart (InvoicesScreen, InvoiceScreen live; wallet line link) · features/orders/order_screen.dart (View invoice) · features/account/account_screen.dart (menu live) · widgets/mock_flag.dart · routing (R.invoice takes an id)
  lib/core/i18n (strings + number patterns) · test/{test_app,flows_test,mock_flags_test}.dart
```

**Structure decision**: the existing layering — thin controllers, FormRequests, Actions with one transaction, money only through `PostLedgerEntryAction`, reads through Resources; documents outside the money transactions behind an interface.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R16)
- [data-model.md](./data-model.md)
- [contracts/invoices-api.md](./contracts/invoices-api.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no Constitution violation; the deviations above land in `docs/` in the same change.

## Follow-ups (not in this feature)

- Egyptian Tax Authority e-invoicing (Part 4 §4): filing, `eta_reference`, a registration status, resend.
- Dahab's real legal and tax details in `config/dahab-invoices.php` (no source gives them; empty until the user fills them).
- Market-maker and first-sale-advance invoices (those flows are not built).
- The customer's "Download all as one file" (no source defines it).
- Tax-record retention (open-questions §1).

## Complexity Tracking

| Addition / deviation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `lines` / `vat_rate` snapshots | An invoice never changes after issue | Recomputing from the order + listing would follow later edits of settings and descriptions |
| `issuer` / `party` set once | Missing configuration must not block payment; the buyer's scope cannot read the seller | Elevating a customer action breaks the RLS rule; blocking payment contradicts the Clarification |
| New ledger kind `credit_note` | Several partial credit notes per settlement, honest labels | `reversal` allows one per entry and reads as a wallet adjustment |
| Render job + healing command | Documents never roll back money | Rendering in the payment couples a PDF failure to the settlement |
| `mpdf/mpdf` dependency | Bilingual PDF with Arabic shaping | dompdf cannot shape Arabic; Chrome needs Node on every server |
