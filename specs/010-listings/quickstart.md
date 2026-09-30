# Quickstart: validating Listings (spec 010)

Contract: [contracts/listings-api.md](./contracts/listings-api.md) · Data: [data-model.md](./data-model.md).

## Prerequisites

- PostgreSQL 16 and Redis running; run every artisan command and the tests **as the `dahab` database user**, never `postgres` (migrating as `postgres` breaks table ownership and every test).
- Upload limits for video (research R7): PHP `upload_max_filesize = 64M`, `post_max_size = 70M` (in the image: `docker/php/uploads.ini`; on Laragon: the local `php.ini`), nginx `client_max_body_size 70m`. No raised `memory_limit` is needed: listing media is encrypted and served in chunks.
- A current gold price (run `php artisan pricing:pull-feed`, or enter one by hand from the Dashboard) — without one, gold listings show no price.

## Setup

```bash
php artisan migrate:fresh --seed
composer swagger:generate
```

Seeded for local use: the ownership declaration v1; a few listings per state for the seeded verified customer; `listing.*` permissions on `ceo`, `coo`, `operations`.

## Automated checks

```bash
composer test -- --filter=Listing
composer test -- --filter=Market
composer test
./vendor/bin/pint --test
```

Expected: all green; `CustomerTableIsolationTest`, `CustomerRouteGateTest`, `MarketScopeTest`, `MarketLeakTest`, `PermissionCatalogueTest` and `ListingVideoStreamingTest` (a real 50 MB upload and playback within the memory bound) included.

## Manual walk (Postman folder "Listings")

1. **Reference** — `GET /reference/piece-types?category=gold`, `/reference/karats`, `/reference/branches`, `/reference/legal-documents/ownership_declaration` with no token → 200.
2. **Upload** — as a verified customer, `POST /customer/me/uploads` twice with `purpose=listing_photo` → two tokens. As an unverified customer → 403 `verification_required`.
3. **Create** — `POST /customer/me/listings` (gold, 21K, 8.000 g, making 250, one branch, the two tokens, declaration id) → 201 `draft`. Same key again → same body, `Idempotent-Replayed: true`. Without `karat_code` → 422 `gold_needs_karat_weight`.
4. **Submit** — `POST …/submit` → `in_review`. `GET /market/listings` does not show it.
5. **Review** — as Operations: `GET /dashboard/listings` shows it with `meta.counts.in_review`; open it, open a photo; `POST …/request-changes` with a message → the seller's `GET /customer/me/listings/{id}` shows `staff_message`; the queued notification is visible in the log/Horizon.
6. **Fix and resend** — `PATCH` the description, `POST …/submit`; as Operations `POST …/approve` → `live`, `listed_at` set; the audit log shows "Listing approved".
7. **Market** — with no token: `GET /market/listings` shows it with `current_price`; the response has no seller field; `GET /market/listings/{id}/media/{photo}` returns the image with `Cache-Control: no-store`; the invoice id → 404.
8. **Withdraw / take down** — seller `POST …/withdraw` → `withdrawn`, gone from the market, media → 404. On another live listing, Operations `POST …/takedown` with a reason.
9. **Illegal move** — approve the withdrawn listing → 409 `illegal_listing_transition`.
10. **Suspension** — suspend a customer with a live listing → it is `suspended_hold` and off the market; reinstate → `live` again with the same `listed_at`.
11. **Finance** (default roles) on any `/dashboard/listings*` → 403 `permission_denied`.

## Apps

- **Dashboard**: `npm run type-check && npm run lint && npm run build`; sign in as Operations → *Listings to review* shows the queue; approve / ask for changes / reject / take down each open a `DModal`.
- **Customer App**: `flutter analyze && flutter test && flutter build web --release`; signed out, Browse loads from `/market/listings`; signed in and verified, *Sell your piece* ends in "Sent for approval" and the piece is in *My listings*.
