# Quickstart: Ledger Core (spec 008)

Validation guide. For shapes see [contracts/wallet-api.md](./contracts/wallet-api.md) and [data-model.md](./data-model.md).

## Prerequisites

- PostgreSQL 16 running (Laragon). Migrations run **as the `dahab` user**, not `postgres` (see project memory).
- Backend: `composer install`, then `php artisan migrate:fresh --seed`.
- Dashboard: `npm install` in `../dahab-dashboard`. Flutter: `flutter pub get` in `../dahab-flutter`.

## 1. Engine guarantees (US1, US2)

```bash
composer test -- --filter=Ledger
```

Expected, all green:
- an unbalanced entry is refused (`DH002`) with nothing kept;
- an overdraft is refused (`insufficient_funds` / `DH001`);
- UPDATE and DELETE on `ledger_transaction` and `ledger_posting` are refused;
- an entry without an actor is refused;
- an amount with 5 decimal places, or zero, is refused;
- a double reversal is refused;
- two concurrent holds exceeding the balance: exactly one succeeds;
- every new customer has 2 accounts, the backfill is idempotent, and each internal kind exists once;
- `ledger_global_zero.must_be_zero = 0` after every scenario.

## 2. RLS (FR-014)

```bash
composer test -- --filter="CustomerTableIsolationTest|LedgerIsolationTest"
```

Expected: in customer A's scope, B's accounts, entries and lines are invisible and cannot be counted. A direct INSERT is refused. A customer-scope commit of an entry that touches internal accounts still passes the balance check (the trigger reads in `ledger` scope).

## 3. Customer wallet (US3)

Tinker, under the maintenance scope: post a top-up (bank −1000 / available +1000) and a hold (available −400 / held +400) for a verified customer. Then:

```bash
curl -H "Authorization: Bearer <customer access token>" http://127.0.0.1:8000/api/v1/customer/me/wallet
```

Expected: `available 600.0000`, `held 400.0000`, `total 1000.0000`.

On `/customer/me/wallet/transactions`: 2 rows, newest first. The hold row has `available_change -400`, `held_change 400`, `available_after 600`.

A pending customer gets 403 `verification_required`.

In the Flutter app (`flutter run -d chrome`), Wallet shows 600 / 400 / 1,000 and the two movements, labelled "Hold placed" and "Top-up".

## 4. Dashboard (US4)

- As Finance, open **Money → Wallet statement**, choose the customer and the month:
  - opening + in − out = closing;
  - each row's before and after chain;
  - the hold is an "out" with held beside it;
  - the export downloads a CSV.
- Switch the view to **all customer wallets**: the hold row shows `moved_to_held` and the balance is unchanged.
- Switch to **Dahab wallet**: it is empty until settlements exist, with VAT payable 0.
- Open the customer's file: the wallet panel shows 600 / 400.
- On the Overview, the safety figure and Customer wallets panels show live figures.
- As the COO (seed roles):
  - the menu item, the wallet panel and the two Overview panels are absent;
  - a direct `GET /dashboard/wallet-statement` returns 403 `permission_denied`, and the audit log shows the denial.
- The audit log shows "Wallet statement opened" for the one-customer view and "Wallet statement exported".

## 5. Gates

```bash
composer test
./vendor/bin/pint --test
composer swagger:generate
php artisan migrate:rollback --step=1
php artisan migrate
```

Dashboard: `npm run type-check`, `npm run lint`, `npm run build`. Flutter: `flutter analyze`, `flutter test`.

Optional, for SC-004: `php vendor/bin/pest --group=perf`.
