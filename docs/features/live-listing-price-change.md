# Change the price of a live piece

> File: `docs/features/live-listing-price-change.md` · Branch: `feature/live-listing-price-change` in Backend, Dashboard and Customer app
> Status: approved (planned, not built) · Date: 2026-10-11 · Spec: `specs/022-live-listing-price-change/`

## Goal

A seller changes the one figure they own on a **live** piece without sending it back to review: the making charge per gram for gold, or the asking price for diamond and gold with diamond. The piece keeps its place on the market, its views and its age. Staff can see every change on the listing detail. The prototype screen `R.editprice` with its sample piece becomes a real screen.

Owner decisions (2026-10-11):
- **D22-1**: no new review.
- **D22-2**: nobody is notified.
- **D22-3**: no limit on how often or how far the price moves.
- **C1**: a new endpoint.
- **C2**: refused only under "stop everything".
- **C3**: refused while the piece is approved for market makers.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES
Dashboard:    YES (read only)
Customer App: YES
Auth:         NO
Permissions:  NO
```

## Backend Impact

- New:
  - `ChangeListingPriceAction` (row lock, Switches, state, lock and figure checks, history row, audit);
  - `ChangeListingPriceRequest`;
  - `CustomerListingController@price` with `#[OA]`;
  - `ListingPriceChange` model + factory;
  - `ListingPriceField` enum.
- Changed:
  - `Listing` (`priceChanges()`, `priceField()`);
  - customer `ListingResource` (`can_change_price`);
  - `ListOwnListingsAction` (one `price_locked` column);
  - staff `ListingResource` + `Dashboard\ListingController` (`price_history` on the detail);
  - `AuditEvent::LISTING_PRICE_CHANGED`;
  - `DomainApiException` (3 new codes, plus `listing_not_live` reused);
  - `bootstrap/app.php` (DH023);
  - `routes/api.php`;
  - Postman.
- No notification, job or schedule.

## Database Impact

One migration, `2026_10_15_000010_listing_price_change.php`, mirrored in `04_schema_market.sql` / `00_schema_full.sql`:

- **New table `listing_price_change`**:
  - append-only;
  - forced RLS: the seller's own rows plus staff;
  - CHECKs on field, figures, `new <> old`, and exactly one actor;
  - `txid`.
- **New function `listing_price_locked(uuid)`**: does a market-maker approval exist? It reads in a system scope switch for its one `EXISTS`. Spec 023 extends it for the first-sale advance.
- **New triggers on `listing` (SQLSTATE DH023)**:
  - the price columns may change only in draft / changes requested / live;
  - a live change may not move the state in the same update;
  - a live change is refused while the piece is locked;
  - a live change needs its history row in the same transaction.
- No backfill, setting, permission or seed. `down()` refuses once history exists.

## API Changes

| Endpoint | Change | Class |
|---|---|---|
| `POST /customer/me/listings/{listing}/price` (customer, trade gate, `Idempotency-Key`, `throttle:customer.listings`) | New. Body: `making_charge_per_g` (gold) or `asking_price` (other categories), the creation rules, and it must differ from the current figure. `200` returns `CustomerListing`. Errors: `409 listing_has_requests` · `listing_not_live` · `listing_price_locked` · `platform_stopped`, `422 validation_failed`, `403` trade gate, `404` | Non-breaking |
| `GET /customer/me/listings`, `GET /customer/me/listings/{id}` | `+ can_change_price` | Non-breaking |
| `GET /dashboard/listings/{listing}` | `+ price_history[]` (detail only) | Non-breaking |
| `PATCH /customer/me/listings/{id}` | Unchanged; still `listing_not_editable` for a live piece | — |

Full contract: `specs/022-live-listing-price-change/contracts/listing-price-api.md`.

## Dashboard Impact

- `src/types/listing.ts`: `ListingPriceChange`, `ListingDetail.priceHistory`.
- `src/services/listing.service.ts`: maps `price_history`.
- New `src/components/listings/ListingPriceHistory.vue`, shown as *Price changes* under *History* in `ListingReviewPanel.vue`, hidden when empty.
- No action, permission or navigation change.

## Customer App Impact

- `lib/models/listing.dart`: `canChangePrice`.
- `ListingsRepository.changePrice` in `lib/services/repositories.dart` and `lib/services/api/listings_api.dart`.
- `EditPriceScreen` in `lib/features/orders/order_flows.dart`, rebuilt:
  - opened as `R.editprice?id=…`;
  - shows the real listing, one field and the live "You would receive" from `GET /reference/quote`;
  - the slider and the sample piece are removed.
- `lib/routing/app_router.dart`.
- `lib/features/catalog/detail_screen.dart`:
  - *Change fee* / *Change price* appears when `canChangePrice` is true;
  - the `MockMark` is removed;
  - a reserved piece shows why its price can't change.
- `lib/widgets/mock_flag.dart`: `R.editprice` is no longer mock.
- New keys `lib/core/text/keys/listing_price.dart` (`listing.price.*`, Arabic AR-DRAFT). The manifest is copied to the Backend `database/data/app_text_keys.json`.
- Tests: the fake backend `test/test_app.dart`, `flows_test.dart`, `mock_flags_test.dart`, `routes_smoke_test.dart`.

## Authentication / Authorization

The customer token and `customer.gate:trade` (verified, not suspended, not closed). Ownership is enforced by RLS plus the `seller_id` filter, and another customer's listing answers 404. Staff read the history with the existing listing permissions.

## Permissions

None new. `listing.review | listing.request_changes | listing.takedown` read the detail, as today.

## Validation

- **Backend (authoritative)**: `ListingRules::MONEY`, with `>= 0` for the making charge and `> 0` for the asking price. The field must belong to the category, and the figure must differ from the current one. The state is checked before the fields. The database CHECKs `listing_price_shape` and `listing_amounts` back this up.
- **App (UX only)**: a 2-decimal number field, and *Save* disabled while the figure is empty or unchanged.

## Error Handling

| Code | App shows |
|---|---|
| `listing_has_requests` | "Someone has already asked to buy this piece, so its price can't change." |
| `listing_price_locked` | "This piece's price is fixed for now. Contact us if you need to change it." (market makers are not named) |
| `listing_not_live` | "Only a live piece's price can be changed." |
| `platform_stopped` | the control's message (as elsewhere) |
| `validation_failed` | the field error, e.g. "This is already the price." |

`listing_price_history_immutable` is a database backstop only and is not reachable through the API.

## UI States

- **App, edit screen**:
  - loading the listing;
  - quote loading or unavailable ("Price not available right now"; *Save* still works, because the Backend computes the price);
  - saving;
  - success (toast, then back to the piece page);
  - each error above.
- **App, piece page**: the button only when allowed; on a reserved piece, the line explaining why.
- **Dashboard**: the *Price changes* block is hidden when empty. The detail's existing loading and error states cover it.

## Testing

- **Backend**:
  - `ListingPriceChangeMigrationTest`, `ChangeListingPriceTest`, `ListingPriceConcurrencyTest`;
  - the detail case in `ReviewQueueTest`;
  - the inventory tests (`OpenApiGenerationTest`, `PrincipalIsolationTest`, `AuditCatalogueTest`);
  - Postman;
  - one full run on `dahab_wt022`.
- **Dashboard**: type-check, lint (touched files), build.
- **Flutter**: analyze, test, build web --release.
- **Manual**: `quickstart.md`.

## Breaking Changes

None. Inside the database, DH023 now refuses a price-column write after a buyer joined. No endpoint did that, but test factories that wrote prices after moving the state must set the price first (task T006).

## Migration / Compatibility

- **Deploy order**: Backend (migration plus endpoint) first. The Dashboard and the app can follow in any order:
  - an older app never sees `can_change_price` and keeps the mock flag;
  - an older Dashboard ignores `price_history`.
- **Text keys**: sync after the Backend deploy (`php artisan app-texts:sync`).
- **Rollback**: `down()` works only before any price change is recorded.
- **Spec 023 must extend `listing_price_locked()`**: once a first-sale advance is paid on a piece, its price must not change.
