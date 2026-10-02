# Quickstart: Buy requests (spec 011)

Validation guide. Contracts: [contracts/buy-requests-api.md](./contracts/buy-requests-api.md); data:
[data-model.md](./data-model.md).

## Prerequisites

- Run artisan as the `dahab` database user. **Never** run Pest, `migrate:fresh` or any wipe against the primary
  `dahab` database: use an isolated database owned by `dahab`, e.g. `DB_DATABASE=dahab_wt011`.
- Redis running (idempotency, queue, throttles); a queue worker or `QUEUE_CONNECTION=sync` for notifications.
- Seeded (`migrate:fresh --seed`, local only): staff (`<role>@dahab.test`); Hoda (`+201000000006`) and Karim
  (`+201000000007`), both verified with money in the wallet (`LocalLedgerSeeder`) and live pieces
  (`LocalListingSeeder`); `LocalBuyRequestSeeder` then, through the real Actions, puts Hoda first in line on Karim's
  gold earrings with diamonds (Karim can accept or decline) and opens order `DH-2026-000001` on Hoda's 21K ring
  (Karim's request, accepted — for the Dashboard's Cancel acceptance); Hoda's 18K ring stays live. It records a demo
  gold price only when there is none. Password `seeded-password-1`.

```bash
php artisan migrate:fresh --seed --database=pgsql
php artisan test --group=buy-requests
```

## Scenarios

1. **Send** — as buyer A (20,000 available) read `GET /market/listings/{id}` (note `current_price`,
   `deposit_amount`) and `GET /reference/legal-documents/deposit_agreement`, then `POST /customer/me/buy-requests`.
   Expect 201, `place_in_line` 1; `GET /customer/me/wallet` shows the deposit held; the market shows the listing
   `reserved`, `queue_count` 1. Replay with the same key: same response, one hold.
2. **Refusals** — buyer B with 1,000 available → `insufficient_funds` with `shortfall`; the seller on their own piece
   → `cannot_buy_own_listing`; buyer A again → `already_in_queue`; a stale `confirm_locked_price` (−2%) →
   `price_moved` with the fresh figures; an old `deposit_legal_doc_id` → `deposit_agreement_required`.
3. **Queue** — buyer C (funded) joins → place 2. Seller `GET /customer/me/listings/{id}/buy-requests` → two rows,
   buyer `display_ref` only.
4. **Decline** — seller declines A → A refunded (wallet available back), C becomes the head (place 1).
5. **Accept** — seller accepts C at an enabled branch option → 201 with `order_ref` `DH-2026-…` and a
   `reach_branch_deadline` in the branch's working hours; the listing is `accepted` and gone from the market; C's
   deposit is still held; `GET /customer/me/buy-requests/{C}` shows the order.
6. **Not chosen** — repeat with three buyers; accept the head; the other two are `released_not_chosen` and refunded.
7. **Leave** — a buyer leaves with `notify_when_free: true`; when the last request ends the listing is `live` and the
   buyer receives one "free again" message.
8. **Expiry** — set `deadline.seller_reply_hours` to 1 (or travel the clock in a test); run
   `php artisan buy-requests:expire`; the request is `released_expired`, refunded, the buyer told.
9. **Take-down / withdraw** — staff take down a reserved listing (reason) → all queued refunded, listing `withdrawn`,
   audit row; the seller withdraws another reserved listing → same, no audit row.
10. **Suspension** — suspend a seller with a reserved listing → `suspended_hold`, queue released; reinstate → `live`,
    empty queue. Suspend a buyer at the head → the seller's accept is refused `buyer_suspended`; decline works.
10a. **Staff cancel** — as Operations, open the accepted listing → order box → *Cancel acceptance* (reason, *Put it
    back on the market*) → order `cancelled_staff`, the buyer's deposit back in available, listing `live` with an
    empty queue, audit row `order.cancelled`, both parties told. Repeat with *Withdraw the piece* → `withdrawn`. As
    Finance (no `order.cancel`) → 403, audited.
11. **Ledger reconciliation** — for every request: holds − releases = deposit if queued/accepted, else 0; every
    ledger transaction balances (the spec 008 statement for "Dahab" shows no change: holds are customer-internal).
12. **Dashboard** — as Operations, Listings → *Reserved* → open the listing: the queue table (buyer refs, deposits,
    deadlines); *Accepted*: the order box; take-down from reserved shows the "buyers get their deposits back" text in
    the modal.
13. **Customer App** (also time scenario 1 by hand: under 30 seconds from the piece page to *Request sent*, SC-001) — as a buyer: piece page shows deposit and queue; *Send buy request* → terms sheet → *Request
    sent*; short balance → *You need a little more* → *Add funds*; *Leave the queue*. As the seller: Orders → Selling →
    *Decide today* → Accept (branch pick) / Decline.

## Commands (verification)

```bash
composer test
./vendor/bin/pint --test
composer swagger:generate
# dashboard
npm run type-check && npm run lint && npm run build
# flutter
flutter analyze && flutter test && flutter build web --release
```
