# Quickstart: validating spec 015

## Prerequisites

- Databases owned by `dahab`: `dahab_wt015` (tests) and `dahab_wt015_dev` (seeded dev). **Never** run the suite, `migrate:fresh` or any wipe on the main `dahab` database.
- Pass the database explicitly on every artisan/test command:

```bash
DB_DATABASE=dahab_wt015 DB_USERNAME=dahab DB_PASSWORD=secret composer test
```

```bash
DB_DATABASE=dahab_wt015_dev DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
```

The local seeders add (through the real Actions) a direct compensation, a credit and a debit adjustment, a capital-in and a bank-charge movement with proof, and a closed (locked) and a saved (unlocked) day.

## Backend checks

1. `composer test` (sequential) — the spec 015 tests under `tests/Feature/Finance/`, `tests/Feature/Order/OrderExportTest.php`, `tests/Feature/Wallet/HeldPerRequestTest.php`, `tests/Feature/Reference/PublicPricesTest.php`, and the whole suite green.
2. `./vendor/bin/pint --test`.
3. `composer swagger:generate` — the new paths appear under *Dashboard Finance*, *Dashboard Overview*, *Reference*.
4. Reconciliation after the seeders: `SELECT must_be_zero FROM ledger_global_zero` = 0; every `wallet_adjustment`, `bank_movement` (not own transfer) and `compensation` row matches its entry.

## Manual walk-through (Dashboard on `:8000` API)

1. As Finance: *Compensation* → pay 800 *Wasted trip* to a verified customer → the row appears, *left today* drops by 800; try 1,500 over the remainder → refused with the figures.
2. As the CEO: Customer file → *Adjust wallet* → debit more than available → `insufficient_funds`; credit 500 → the customer's history shows *Correction*.
3. As Finance: *Bank movements* → record *Bank charge* 250 out with a PDF → the bank book shows it with the period's top-ups and withdrawals; open the proof (audited).
4. *Daily closing* → yesterday → type the statement balance equal to the books → locked; for another day type 250 less → *Save* (unlocked) → close again with an explanation → locked with −250. Today's *Close* is disabled.
5. *Overview* as CEO, Operations and Verification — each sees only its sections; no mock figure remains.
6. *Orders* → filter → *Export* → the CSV matches the list; the audit log shows `order.list_exported`.

## Customer App (`flutter run -d chrome --web-port 8765`, API `http://127.0.0.1:8000/api/v1`)

1. Signed out: the splash rate cards, the home rate strip and calculator show the Backend's prices (no MOCK flag); with no usable price they say *Prices are paused*.
2. Sell form: the estimate follows the quote endpoint as karat/weight change.
3. A buyer with two waiting requests and one accepted order: Wallet → *Held on open orders* lists three lines summing to the total; each opens its request/order; Arabic shows every line.
4. Wallet history shows *Compensation from Dahab* and *Correction* lines in EN/AR.
5. `flutter analyze`, `flutter test`, `flutter build web --release`.

## Dashboard

`npm run type-check`, `npm run lint`, `npm run build`.
