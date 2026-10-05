# Tax invoices and credit notes (no ETA integration)

> File: `docs/features/invoices.md` · Branch: `feature/invoices` (backend, dashboard, flutter)
> Status: built, not committed · Date: 2026-10-05 · Spec Kit: [`specs/016-tax-invoices/`](../../specs/016-tax-invoices/spec.md)

## Goal

Issue the tax invoices the terms and the blueprint promise — automatically, at the moment the buyer pays the balance, one
to each side — and let Finance and the customers read and download them. A registered invoice is never edited: a
correction is a credit note that refunds Dahab's commission and VAT to the seller. Nothing is filed with the Egyptian Tax
Authority yet (Technical Spec Part 4 §4 is not specified), and no screen pretends otherwise.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (7 dashboard + 4 customer endpoints; invoice on orders, invoice_id on wallet movements; kind credit_note)
Dashboard:    YES (the Invoices page replaces its placeholder)
Customer App: YES (Transactions and invoices, the invoice screen, View invoice on the order, Open the invoice on the wallet line)
Auth:         NO
Permissions:  YES (invoice.view, invoice.correct — CEO + Finance)
```

**Classification**: non-breaking (new endpoints and optional fields). Potentially breaking: `credit_note` added to the wallet
movement `kind` enum; two new permission strings.

## Decisions (product owner, 2026-10-05 — spec Clarifications)

| Question | Decision |
|---|---|
| The buyer's invoice | The price paid, VAT 0 (net = gross = buyer total), with the gold value at the buyer's locked rate plus making charge, or the asking price. |
| Numbering | `DH-YYYY-NNNNNN-S` (seller) / `-B` (buyer) from the order reference; credit notes `CN-YYYY-NNNNNN`. |
| Backfill | None — only payments after installation issue invoices. |
| Credit notes | Seller invoices only; one balanced `credit_note` entry — `dahab_commission −net`, `vat_payable −vat`, seller available `+gross`; partial or full, never above what is left; the seller is told by SMS and email. |
| Dahab's details | A Backend PHP config file (`config/dahab-invoices.php`), copied onto each document; missing details never block a payment — the PDF waits. |
| Document | One bilingual (EN + AR) PDF per invoice / credit note, stored encrypted, generated after the payment commits. |
| Tax Authority | Status column *Issued / Partly credited / Credited*; *Net invoiced* replaces *Rejected by the Tax Authority*; no *Send again*; the page says e-invoicing is not connected yet. |
| Permissions | `invoice.view` (read, PDFs, export) and `invoice.correct` (credit notes), CEO + Finance, never the COO. |
| Audit | Staff PDF views and exports audited; a customer's own downloads are not. |

**Disagreement reported**: the blueprint's worked example and the customer prototype show commission *including* VAT
(336 = 294.74 + 41.26); Part 3 §2.5 and the built settlement add VAT on top (600 + 84). The invoice follows the built ledger.

## Backend Impact

- **Migration** `2026_10_09_000010_create_tax_invoices.php` — `tax_invoice` (as the schema + `invoice_no`, `vat_rate`,
  `lines`, `issuer`, `party`, `document_at`), `credit_note` (+ `credit_note_no_seq`), ledger kind `credit_note`, DH012 guards,
  forced RLS on both.
- **Issuing**: `Support/Invoices/IssueTaxInvoices` inside `PayBalanceAction`'s transaction.
- **Documents**: `TaxDocumentRenderer` (mPDF, `mpdf/mpdf` ^8.2), `RenderTaxDocumentJob` after commit, `invoices:render-pending`
  every five minutes.
- **Actions**: `Invoices/*` (list, figures, export, show, PDFs, credit notes, issue credit note), `Invoices/Customer/*`.
- **Notification**: `WalletNotification` `credit_note_issued`.

## Database Impact

`tax_invoice`, `credit_note`, `credit_note_no_seq`, `ledger_event_kind` + `credit_note`, triggers on SQLSTATE DH012
(append-only, one-time snapshots, reconciliation with the settlement, credit cap, credit-note entry check), forced RLS.

## API Changes

See [`specs/016-tax-invoices/contracts/invoices-api.md`](../../specs/016-tax-invoices/contracts/invoices-api.md).

| Method + path | Who |
|---|---|
| `GET /dashboard/invoices` · `/export` · `/{invoice}` · `/{invoice}/pdf` | `invoice.view` |
| `GET /dashboard/credit-notes` · `/{creditNote}/pdf` | `invoice.view` |
| `POST /dashboard/invoices/{invoice}/credit-notes` | `invoice.correct` (idempotent) |
| `GET /customer/me/invoices` · `/{invoice}` · `/{invoice}/pdf` · `GET /customer/me/credit-notes/{creditNote}/pdf` | customer, verified |

Changed: `invoice {id, number}` on customer orders; `invoice_id` on wallet movements; `credit_note` wallet kind.
New error codes: `invoice_not_creditable`, `credit_exceeds_invoice`, `document_not_ready`.

## Dashboard Impact

`src/pages/invoices/index.vue`, `src/components/invoices/*` (figures, invoices table, credit notes table, detail, *Issue a
credit note* `DModal`), types / services / composables / errors; the navigation item shown with `invoice.view`.

## Customer App Impact

*Transactions and invoices* and the invoice screen on the live API with the PDF download; *View invoice* on a paid order;
*Open the invoice* on the wallet line; the `credit_note` wallet label (*Invoice correction*); MOCK flags removed; EN/AR.
*Download all as one file* and the "filed with the Tax Authority" lines removed.

## Follow-ups

- Egyptian Tax Authority e-invoicing (filing, `eta_reference`, a registration status).
- Dahab's real legal and tax details in `config/dahab-invoices.php` (empty until filled).
- Market-maker and first-sale-advance invoices (those flows are not built); tax-record retention.
