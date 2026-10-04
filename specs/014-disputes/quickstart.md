# Quickstart: validate spec 014

## Prerequisites

- Databases `dahab_wt014` (tests) and `dahab_wt014_dev` (seeding), owned by `dahab`. **Never** the main `dahab` database.
- Every artisan/test command passes the database explicitly, as the `dahab` user.

```bash
DB_DATABASE=dahab_wt014 DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
```

## Backend checks

```bash
DB_DATABASE=dahab_wt014 DB_USERNAME=dahab DB_PASSWORD=secret composer test
```

Run sequentially (no `--parallel`). Must include: `tests/Feature/Dispute/*` (open, freeze vs every order action, sweep skip, pass-on, resolve resume with deadline give-back, against-sale before payment, refusal after payment, compensation caps and `compensation.uncapped`, suspend-on-resolve, isolation, leaks, notifications, concurrency, reconciliation), `tests/Feature/Order/ProxyCollectionTest.php`, `tests/Feature/Order/ExtensionRequestTest.php`, the updated keep lists in the four truncating tests.

```bash
./vendor/bin/pint --test
composer swagger:generate
DB_DATABASE=dahab_wt014 DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:rollback --step=1 && DB_DATABASE=dahab_wt014 DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate
```

## Seed a demo

```bash
DB_DATABASE=dahab_wt014_dev DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
```

`LocalDisputeSeeder` (local only) creates, through the real Actions: a frozen order with a buyer dispute, a passed-on dispute, one resolved *resume* with compensation, one resolved *against the sale*, a ready-to-collect order with a named proxy, and extension requests waiting / accepted / refused.

## End-to-end scenarios (expected outcomes)

1. **Freeze** — a buyer opens a dispute on an order awaiting balance → order `disputed`; `POST …/pay-balance` → `409 order_frozen`; `php artisan orders:sweep` after the balance deadline → order unchanged.
2. **Resume** — staff resolve *resume* after N minutes → order `awaiting_balance`, `balance_due_deadline` later by N minutes, an extension row with the dispute, the buyer's order shows the reply.
3. **Against the sale** — on an order frozen at inspection → `cancelled_inspection`, one `deposit_release` by the staff member, a seller return open; the same on a paid order → `409 dispute_outcome_not_allowed`.
4. **Compensation** — Finance pays 2,500 → `403 compensation_cap_exceeded`; 1,500 then 4,000 the same day → second refused; the CEO pays 10,000 → allowed; the ledger stays balanced.
5. **Second dispute** — the buyer again → `409 dispute_already_raised`; the seller (first time) → allowed.
6. **Proxy** — the buyer names a proxy on a ready-to-collect order → one SMS to the proxy without the code; handover `collector=proxy` without `proxy_id_checked` → `422 proxy_details_missing`; with it → `completed`, `collected_by_proxy = true`.
7. **More time** — the seller asks; staff accept 12 working hours → `reach_branch_deadline` moved by the resolver, request `accepted` with its extension; another request while one waits → `409 extension_request_pending`; staff receive the piece while a request waits → request `lapsed`.

## Dashboard

```bash
npm run type-check && npm run lint && npm run build
```

Manual: *Disputes and reports* lists and resolves; the order detail shows the dispute, the waiting request and the proxy; buttons follow the permission strings (sign in as COO: no refund/compensation controls).

## Customer App

```bash
flutter analyze
flutter test
flutter build web --release
flutter run -d chrome --dart-define=API_BASE_URL=http://127.0.0.1:8000/api/v1
```

Manual: Report a problem (with photos) → the order shows *On hold* and later Dahab's reply; Someone else collects; Ask for more time; Arabic layout.
