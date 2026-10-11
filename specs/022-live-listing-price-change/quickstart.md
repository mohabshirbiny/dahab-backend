# Quickstart: Change the price of a live piece (spec 022)

## 0. Databases (owner first)

The owner creates `dahab_wt022` (test suite) and `dahab_wt022_dev` (dev data); the `dahab` user has no CREATEDB. Check with `psql -l`. If either is missing, stop and ask. **Never the primary `dahab`.** Pass the database on every command:

```bash
DB_DATABASE=dahab_wt022 DB_USERNAME=dahab DB_PASSWORD=… php artisan migrate:fresh --seed     # suite schema
DB_DATABASE=dahab_wt022_dev DB_USERNAME=dahab DB_PASSWORD=… php artisan migrate:fresh --seed # dev data
```

## 1. Tests (targeted, sequential)

```bash
DB_DATABASE=dahab_wt022 … ./vendor/bin/pest tests/Feature/Migrations/ListingPriceChangeMigrationTest.php
DB_DATABASE=dahab_wt022 … ./vendor/bin/pest tests/Feature/Listing/ChangeListingPriceTest.php
DB_DATABASE=dahab_wt022 … ./vendor/bin/pest tests/Feature/Listing/ListingPriceConcurrencyTest.php
DB_DATABASE=dahab_wt022 … ./vendor/bin/pest tests/Feature/Listing/ReviewQueueTest.php tests/Feature/Listing/OwnListingsTest.php
```

Expected: all green. One full run at the end only (in the background).

## 2. By hand (API on 127.0.0.1:8000 against `dahab_wt022_dev`; the owner starts it)

Postman folder *Customer → Listings*, request *Change price of a live piece*:

1. Sign in as a verified seller with a **live gold** piece. `POST /customer/me/listings/{id}/price` `{"making_charge_per_g":"220.00"}` with a new `Idempotency-Key` → `200`, `making_charge_per_g` `220.0000`, `current_price` moved, `can_change_price: true`.
2. Same body again with a new key → `422 validation_failed` (same figure).
3. Send `{"asking_price":"1000"}` on the gold piece → `422` (`prohibited`).
4. As a second customer, send a buy request on the piece → listing `reserved`; as the seller, change the price → `409 listing_has_requests`.
5. A diamond piece in `in_review` → `409 listing_not_live`.
6. Approve a live piece for market makers in the Dashboard (spec 019) → the seller's change → `409 listing_price_locked`; `can_change_price` is `false`.
7. Switches → *Stop everything* → `409 platform_stopped`; clear it → works again.
8. Dashboard → Listings → open the piece → *Price changes* lists 250 → 220 with the seller and time. Audit log → `Listing price changed`.

## 3. Customer app (Flutter)

```bash
flutter analyze
flutter test
flutter build web --release
```

By hand (`API_BASE_URL` default `http://127.0.0.1:8000/api/v1`): open your live piece → *Change fee* (gold) or *Change price* (stones) → type a figure → "You would receive" updates → *Save* → the piece page shows the new price. On a reserved piece the button is gone; a race shows "Someone has already asked to buy this piece…". The screen has no red MOCK flag.

## 4. Dashboard

```bash
npm run type-check
npm run lint -- <touched files>
npm run build
```
