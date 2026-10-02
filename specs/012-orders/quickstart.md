# Quickstart: validate Orders (spec 012)

## Prerequisites

- Run artisan as the `dahab` database user. **Never** run tests, `migrate:fresh` or seeders against the primary `dahab` database.
- Create an isolated test database owned by `dahab`: `DB_DATABASE=dahab_wt012`. Set it in `.env.testing` or the shell environment for this worktree only.
- Spec 011 is in the branch (`feature/orders` is based on `feature/buy-requests`).

## Backend

```bash
DB_DATABASE=dahab_wt012 php artisan migrate:fresh --seed
DB_DATABASE=dahab_wt012 composer test
./vendor/bin/pint --test
composer swagger:generate
```

The suite runs sequentially (no `--parallel`). The local seeder `LocalOrderSeeder` leaves one order in each state (research R23). List them with `GET /dashboard/orders?group=all` as the seeded CEO.

## Scenarios (each maps to Pest tests; HTTP is the boundary)

1. **Delivery**: accept a request (spec 011), then `POST /dashboard/orders/{id}/receive` as Operations with no branch → `at_inspection`. As an IGI user of another branch → 403 `wrong_branch`.
2. **Seller cancel and suspension**: the seller cancels → buyer refunded (`GET /customer/me/wallet`), listing withdrawn. A second order passes its deadline. Run `php artisan orders:sweep` → `cancelled_seller` and the seller is suspended (`repeated_cancellations`).
3. **Pass and pay (Part 3 §3.3)**: set the price to bid = ask = 5,994 and the 21K adjustments to ∓13.125 fixed; list a 10.000 g, 300/g ring; request, accept, receive, result 21K 10.000 g → pay. The order detail's `settlement` shows 55,631.2500 / 54,684.7500 / 600 / 84 / 262.5. The seller's wallet rises by 54,684.7500.
4. **§3.4**: the same with a measured 9.900 g → total 55,074.9375, balance 43,948.6875.
5. **Weight adjust**: 9.700 g → `weight_adjust_pending`; the buyer accepts → `awaiting_balance`. Another buyer declines → refunded, a return with a code; the seller relists with 9.700 g.
6. **Karat cancel**: 18K on a 21K listing → refunded, the seller suspended (`piece_misrepresented`), return opened.
7. **No-pay**: move the clock past `balance_due_deadline` and run `orders:sweep` → forfeit 50/50 (seller +half, `dahab_commission` +half), return opened, listing `awaiting_seller_return`.
8. **Collection**: the buyer's detail shows the code; `POST /dashboard/orders/{id}/handover` with a wrong code 5× → 429 `handover_locked`; the right code after 15 min → `completed`.
9. **Staff pages**: `GET /dashboard/orders?past_deadline=1`, `GET /dashboard/inspections`, `GET /dashboard/buy-requests?near_expiry=1`.
10. **Reconciliation**: `OrderReconciliationTest` over every seeded order; `ledger_global_zero = 0`.

## Dashboard

```bash
npm run type-check && npm run lint && npm run build
```

Manual check: Orders (chips, the past-deadline filter, the detail timeline, each action in `DModal`), Inspections (results; the work list and result form as `igi_branch`), Buy requests (near-expiry highlight).

## Customer App

```bash
flutter analyze && flutter test && flutter build web --release
```

Manual check: the seller's "Bring the piece" countdown → cancel; the buyer's result → Accept → Pay → the code; the returned-piece code → relist.
