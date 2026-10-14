# Wallet statement PDF (testing fixes, package B)

> File: `docs/features/wallet-statement-pdf.md` · Branch: `feature/wallet-statement-pdf` in dahab-backend and dahab-flutter (branched from `feature/testing-fixes`)
> Status: done · Date: 2026-10-14

## Goal

Testing issue #12: the app's "Download" made a `.txt` with the name "Mona Hassan Ibrahim" hard-coded, from a
list that silently stopped after 8 pages. Decision: the statement is a PDF made by the server, for the
signed-in customer only. Item 11: the app says when its history list shows only the latest movements.

## Impact Summary

```
Backend:      YES
Database:     NO
API:          YES (one new endpoint, non-breaking)
Dashboard:    NO  — staff statements are unchanged (and CSV); no new audit-code map in the Dashboard
Customer App: YES
Auth:         NO  (customer.gate:verified, like the other wallet reads)
Permissions:  NO
```

## Backend Impact

- `GET /customer/me/wallet/statement.pdf?from=YYYY-MM-DD&to=YYYY-MM-DD` — `WalletController::statementPdf`,
  `WalletStatementPdfRequest`, `DownloadCustomerStatementAction` (build → render → audit),
  `BuildCustomerStatementAction` (figures), `CustomerStatementLabels` (the app's EN/AR wording per kind),
  `StatementPdfRenderer` + `resources/views/documents/wallet-statement.blade.php`.
- `App\Support\Documents\MpdfDocument`: the mPDF setup (A4, DejaVu, Arabic shaping) moved out of
  `MpdfTaxDocumentRenderer`, which now calls it; invoices and the statement render the same way.
- Audit event `wallet.statement.downloaded` (money category): period, row count, truncated.
- Rate limiter `customer.statement_pdf`: 10 a minute per customer.

## Rules

- **Period:** Cairo days, inclusive. Default: the last **90 days** ending today. Maximum **366 days**. Never past
  today; the end may not be before the start.
- **Rows:** the customer's own ledger entries (forced RLS; no customer parameter), oldest first, with the same
  figures as `/customer/me/wallet/transactions` (available change, available and held after) and the app's
  labels. A withdrawal sent to the bank shows its held change as the amount, as in the app.
- **Header:** the customer's real name and number, the period, available at the start and end, money in, money out
  (holds included), held at the end. Dahab's legal details when configured (`config/dahab-invoices.php`).
- **Cap:** 5,000 rows; beyond that the PDF says to pick a shorter period (a year of activity is far below it).
- Unverified → 403 `verification_required`; a suspended customer may still download.

## API Changes

| Endpoint | Change | Class |
|---|---|---|
| `GET /customer/me/wallet/statement.pdf` | New, `application/pdf` attachment; 401 / 403 `verification_required` / 422 `validation_failed` / 429 | Non-breaking |

## Customer App Impact

- `WalletRepository.statementPdf(from, to)` and `historyCutAt` (`ApiWalletRepository`, mock).
- Wallet screen: *Download* opens a period choice (30 days / 90 days / 12 months) and *Download PDF*
  (loading, error, not-verified message); `_statement` and the hard-coded name are gone.
- Item 11: "Showing the latest N movements. Download the statement to see older ones." when the history stopped
  at `maxPages`.
- `ApiClient._mediaUri` keeps a query string (it used to fold `?…` into the path).
- Texts `wallet.statement.*` (Arabic AR-DRAFT); manifest regenerated and copied to the Backend.

## Testing

Pest `Wallet/CustomerStatementPdfTest`: own rows only (two customers), real name and balances, row-for-row
agreement with the transactions endpoint, 90-day default + audit, unverified 403, four invalid periods, one real
mPDF render. Flutter `flows_test.dart`: the download calls the endpoint with the chosen period; the "latest N" line.

## Migration / Compatibility

No migration. Deploy the Backend before the app; an older app keeps its text download until updated.
