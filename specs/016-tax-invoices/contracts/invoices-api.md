# API contract: Tax invoices and credit notes (spec 016)

Planning artefact — the code's `#[OA]` attributes win on disagreement. Envelope, errors and keyset paging as in `docs/platform/api-contract.md`. Amounts are strings with 4 dp.

## Shared shapes

**InvoiceSummary**
```json
{ "id": "uuid", "number": "DH-2026-000123-S", "party": "seller", "order_id": "uuid", "order_ref": "DH-2026-000123",
  "issued_at": "2026-10-05T14:05:00+03:00", "net": "600.0000", "vat": "84.0000", "gross": "684.0000",
  "credited": "0.0000", "remaining": "684.0000", "status": "issued", "document_ready": true }
```
`status`: `issued | partly_credited | credited`. Buyer invoices: `vat` `"0.0000"`, `remaining` = gross but not creditable (`creditable: false` on the detail).

**InvoiceDetail** = InvoiceSummary + `vat_rate`, `lines` (R3 snapshot), `issuer` (object or null), `party_details` (`{full_name, display_ref}` or null — staff only; customers see their own name), `creditable` (bool), `credit_notes: [CreditNote]`, and for staff `customer: {id, display_ref, full_name}`.

**CreditNote**
```json
{ "id": "uuid", "number": "CN-2026-000001", "invoice_id": "uuid", "invoice_number": "DH-2026-000123-S",
  "reason": "Commission was overstated after a weight correction.", "net": "263.1579", "vat": "36.8421", "gross": "300.0000",
  "issued_at": "…", "document_ready": true }
```
Staff also get `issued_by: {id, name}`.

## Customer (`auth:customer`, `abilities:customer:access`, `customer.gate:verified`; a suspended customer may read; not audited)

| Method | Path | Notes |
|---|---|---|
| GET | `/customer/me/invoices` | `role` (`seller|buyer`, optional), `cursor`, `per_page` (≤ 50). Newest first. `data: [InvoiceSummary]`, `meta: {per_page, next_cursor}` |
| GET | `/customer/me/invoices/{invoice}` | `data: InvoiceDetail`; another customer's id → 404 |
| GET | `/customer/me/invoices/{invoice}/pdf` | `200 application/pdf`, `Content-Disposition: attachment; filename="DH-…-S.pdf"`; `409 document_not_ready` |
| GET | `/customer/me/credit-notes/{creditNote}/pdf` | as above |

Changed resources (additive):
- `CustomerOrder` (`GET /customer/me/orders/{id}`, list rows): `invoice: {id, number} | null` — the caller's own invoice for the order.
- `CustomerWalletMovement`: `invoice_id: uuid | null` (on `balance_payment` and `credit_note` rows: the caller's own invoice for that order); `kind` enum + `credit_note`.

## Staff (`auth:staff`, `abilities:staff:access`, `staff.standing`)

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/dashboard/invoices` | `invoice.view` | `from`, `to` (Cairo `YYYY-MM-DD`; default last 30 days; `to ≥ from`, span ≤ 366 days), `party`, `status`, `q` (≤ 100), `cursor`, `per_page` (≤ 100). `data: [InvoiceSummary + customer {id, display_ref, full_name}]`, `meta: {per_page, next_cursor, figures: {month: F, period: F}}` where F = `{issued_count, net_invoiced, vat_collected, credit_notes_count, credit_notes_amount}` |
| GET | `/dashboard/invoices/export` | `invoice.view` | same filters; `text/csv` (Number, Date, Order, Party, Customer, Net, VAT, Gross, Credited, Status); stops at `export_cap` rows with a closing line and `X-Export-Truncated: true` (the platform's CSV convention); audited `invoices.exported` |
| GET | `/dashboard/invoices/{invoice}` | `invoice.view` | `data: InvoiceDetail` |
| GET | `/dashboard/invoices/{invoice}/pdf` | `invoice.view` | PDF; audited `invoice.document_viewed`; `409 document_not_ready` |
| GET | `/dashboard/credit-notes` | `invoice.view` | `from`, `to`, `q`, `cursor`, `per_page`; `data: [CreditNote + issued_by + customer]` |
| GET | `/dashboard/credit-notes/{creditNote}/pdf` | `invoice.view` | PDF; audited `credit_note.document_viewed` |
| POST | `/dashboard/invoices/{invoice}/credit-notes` | `invoice.view` + `invoice.correct` | `Idempotency-Key` required. Body `{ "amount": "300.00", "reason": "10–1000 chars" }` (amount > 0, ≤ 2 dp). `201 data: CreditNote`. Errors: `409 invoice_not_creditable` (buyer invoice), `422 credit_exceeds_invoice` (`details.remaining`), `422 validation_failed`, `403 forbidden`, `404`. Audited `credit_note.issued` (reason, amounts, invoice number, ledger txn). The seller is told (`credit_note_issued`). |

## Errors (new)

| Code | HTTP | When |
|---|---|---|
| `invoice_not_creditable` | 409 | credit note on a buyer invoice |
| `credit_exceeds_invoice` | 422 | amount > remaining (`details.remaining`); also the DH012 backstop under concurrency |
| `document_not_ready` | 409 | the PDF is not generated yet (configuration incomplete or job pending) |

## Classification

Non-breaking: new endpoints; optional `invoice` on orders and `invoice_id` on wallet movements. Potentially breaking: `credit_note` added to the wallet movement `kind` enum (Flutter falls back to a generic label; Dashboard statement labels come from the backend); two new permission strings in the catalogue.
