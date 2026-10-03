# Quickstart: validate spec 013 end to end

## Prerequisites

- Isolated databases owned by `dahab` (never the primary `dahab` DB): as postgres, `CREATE DATABASE dahab_wt013 OWNER dahab;` (tests) and `CREATE DATABASE dahab_wt013_dev OWNER dahab;` (seeded app).
- Every artisan call passes `DB_DATABASE=… DB_USERNAME=dahab DB_PASSWORD=secret` explicitly.

## Backend checks

```bash
DB_DATABASE=dahab_wt013 DB_USERNAME=dahab DB_PASSWORD=secret ./vendor/bin/pest            # sequential, never --parallel
./vendor/bin/pint --test
composer swagger:generate
DB_DATABASE=dahab_wt013_dev DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed   # runs LocalWithdrawalSeeder in local
DB_DATABASE=dahab_wt013_dev DB_USERNAME=dahab DB_PASSWORD=secret php artisan serve
```

Expected: all tests green, including `Withdrawal*`, `PayoutAccount*`, `WithdrawalReconciliationTest`, `WithdrawalConcurrencyTest`, and the build tests (isolation, route gate, permission catalogue).

## Manual walk (Postman folder "Withdrawals", or the apps)

1. Sign in as a seeded verified customer with money (LocalWithdrawalSeeder prints them). `GET /customer/me/payout-accounts` → one verified account in use, recent changes.
2. `POST /customer/me/withdrawals/confirmations` `{amount: "1000.00", payout_account_id}` → `sent`; the email lands in the log mailer (`storage/logs`). Open the link's token: `POST /withdrawal-confirmations/read` (no change), then `/confirm` → `confirmed`.
3. `POST /customer/me/withdrawals` with the confirmation → `requested`; `GET /customer/me/wallet` → available −1000, `pending_withdrawals` 1000.
4. As `finance@dahab.test`: Dashboard → Withdrawals → take for review → hold → unhold → release with a bank transaction number. `GET /dashboard/wallets/overview` → bank cash −1000, pending withdrawals back to 0.
5. As `coo@dahab.test`: the Withdrawals page is hidden; `POST /dashboard/withdrawals/{id}/release` → 403 and an audit row.
6. Customer adds a second account → `pending_review`; `verification@dahab.test` verifies it from *Payout accounts to check*; customer taps *Use this one* → a pause until now + 48 h, any open withdrawal cancelled and refunded, SMS + email in the log; a new confirmation request → `409 withdrawals_paused`.
7. `php artisan withdrawals:sweep` after `pause_until` (set the clock or the row) → the customer is told withdrawals are open again; a new request succeeds.

## Dashboard and Customer App

```bash
# dashboard worktree
npm run type-check && npm run lint && npm run build
# flutter
flutter analyze && flutter test && flutter build web --release
flutter run -d chrome --web-port 8765 --dart-define=API_BASE_URL=http://127.0.0.1:8000/api/v1
```

Walk: Account → Bank accounts (tags, in use, recent changes, remove/keep), Add a bank account, Your details → Payout account, Withdraw (Waiting → Confirmed after opening `#/withdraw-confirm?token=…` from the log mail), the wallet's "On its way to your bank"; switch to Arabic and repeat.
