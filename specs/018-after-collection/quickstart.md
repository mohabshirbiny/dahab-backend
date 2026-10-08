# Quickstart: validating after-collection end to end

Prerequisites: dedicated databases `dahab_wt018` (tests) and `dahab_wt018_dev` (development), owned by `dahab`, created by the owner; `.env` / `.env.testing` point at them; **never** the main `dahab` database.

```bash
# Backend (dahab-backend, branch feature/after-collection)
php artisan migrate:fresh --seed          # on dahab_wt018_dev only
composer test                             # Pest, dahab_wt018
./vendor/bin/pint --test
composer swagger:generate
php artisan migrate:rollback --step=1 && php artisan migrate   # the new migration is reversible

# Dashboard (dahab-dashboard)
npm run type-check && npm run lint && npm run build

# Customer App (dahab-flutter)
flutter analyze && flutter test && flutter build web --release
```

## Scenario 1 — the offer appears at handover
1. Seed a settled order `ready_to_collect` at a branch with hours (use `tests/Support/Orders.php`).
2. Staff with `order.handover` hand over (buyer, then a second run with a named proxy).
3. Expect: order `completed`; `collection.free_relist_until` = handover + 12 working hours (branch calendar); customer order read shows `free_relist.status = open` and `ends_at`; the buyer's "collected" notice mentions the time; the seller's does not.
4. Variants: Thursday evening → Sunday; a closure day; `deadline.free_relist_working_hours = 0` → `none`; no branch hours → handover succeeds, `none`, audit says why.

## Scenario 2 — the free relist
1. As the buyer: `POST /customer/me/orders/{id}/free-relist` with a price, description and the current declaration (Idempotency-Key).
2. Expect `201`; one new listing, `live`, `seller = buyer`, `relisted_from_order_id` set, karat/weight = IGI measured, photos/video/stone certificate copied, no private invoice, history rows ("free relist of order …"), audit `order.free_relisted`, confirmation SMS/email/inbox; the origin listing/order unchanged; offer `used` with the listing id.
3. Replay with the same key → same answer; another key → `409 already_relisted`; two parallel requests → exactly one listing.
4. After `ends_at` → `409 free_relist_expired`, offer `expired`. Suspended buyer → `403 account_suspended`, offer still visible.

## Scenario 3 — selling it, 0% commission
Take the relisted listing through buy request → accept → receive → inspection pass → pay-balance. Expect `commission_amount = 0`, `vat_amount = 0`, spread as usual, `seller_proceeds = seller_gross`, the ledger entry balanced with no commission/VAT line, **only** the `-B` invoice, seller order shows `no_fee: true`; the new order's offer is `none`. A failed inspection follows spec 012 and does not restore the offer.

## Scenario 4 — rating
Seller rates after pay-balance (window opens), buyer after `completed`; second submission → replay / `409 already_rated`; after 30 days → `409 rating_closed`; cancelled order → `rating_not_available`; UPDATE/DELETE on `order_rating` in SQL → DH016. The other party and a staff member without `rating.view` cannot read it; CEO/COO can (order detail, customer file).

## Scenario 5 — Dashboard and Customer App
Dashboard: order detail shows the Free relist panel (read-only) and the Ratings panel only with `rating.view`; the Orders list filter works; Listings shows "Free relist of order …"; customer-file history lists the events. Customer App: the order screen shows the live offer with a countdown tied to `ends_at`, the relist form, success → My listings; `RateScreen` has no MOCK banner and no invite card; EN and AR; fake-backend flow tests pass.
