# Quickstart: validating spec 016

Never against the main `dahab` database. Tests: `dahab_wt016`; local seeding: `dahab_wt016_dev`; both owned by `dahab`, credentials passed explicitly.

## Backend

```bash
composer require mpdf/mpdf:^8.2
DB_DATABASE=dahab_wt016_dev DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
DB_DATABASE=dahab_wt016 DB_USERNAME=dahab DB_PASSWORD=secret composer test
DB_DATABASE=dahab_wt016 DB_USERNAME=dahab DB_PASSWORD=secret ./vendor/bin/pest tests/Feature/Invoices
./vendor/bin/pint --test <changed files>
composer swagger:generate
```

Expected:
1. Settle an order (LocalInvoiceSeeder does it through the real Actions): `tax_invoice` has `DH-…-S` (net = commission, VAT = VAT) and `DH-…-B` (net = gross = buyer total, VAT 0).
2. With `config/dahab-invoices.php` empty, `GET /customer/me/invoices/{id}/pdf` → `409 document_not_ready`. Fill the six issuer values, run `php artisan invoices:render-pending` → both PDFs stored; the download opens a bilingual PDF.
3. As Finance: `GET /dashboard/invoices` lists both with `status: issued` and month figures; `POST /dashboard/invoices/{seller}/credit-notes` (Idempotency-Key, amount 300, reason) → `CN-YYYY-000001`; the seller's wallet +300, `dahab_commission` and `vat_payable` reduced by the split; status `partly_credited`. A credit above the remainder → `422 credit_exceeds_invoice`; on the buyer invoice → `409 invoice_not_creditable`. As COO → 403.
4. The audit log shows `invoices.exported`, `invoice.document_viewed`, `credit_note.issued`; customer downloads are absent.

Existing databases (incl. main `dahab`, by the user): `php artisan migrate` then `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder`.

## Dashboard (`.claude/worktrees/invoices`, CRLF)

```bash
npm run type-check && npm run lint && npm run build
```
Sign in as Finance → *Invoices* in the navigation: four figures (Issued this month, Net invoiced, VAT collected, Credit notes), invoices with period filter, search and Export, Status column, credit notes list, detail with PDF, *Issue a credit note* (only with `invoice.correct`). COO: no navigation item.

## Customer App (`.claude/worktrees/invoices`, CRLF)

```bash
flutter analyze && flutter test
flutter run -d chrome --web-port <port in CORS_ALLOWED_ORIGINS>
```
Account → *Transactions and invoices* (no MOCK flag): All / Sold / Bought; open an invoice → figures, credit notes, *Download this invoice* (PDF); the paid order shows *View invoice*; the *Sale settled* wallet line opens the invoice; a credit note appears in wallet history as *Invoice correction*. Arabic: every new string translated.
