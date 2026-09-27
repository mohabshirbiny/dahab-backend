# Pricing — settings, gold prices, price feed, price math

> File: `docs/features/pricing.md` · Branch: `feature/pricing` (backend, dashboard; Flutter has no repo yet)
> Status: in progress · Date: 2026-09-27

## Goal

Make every money number data, and give the platform a live gold price (Backend spec 005,
[`specs/005-pricing/`](../../specs/005-pricing/spec.md)):

- **Finance / CEO** change commission, VAT and each karat's buy/sell adjustments; enter a gold price by hand while the
  price feed is down; confirm a large manual change. **Founders** change deadlines, deposits and thresholds.
- The **price feed** reads the provider's 24K bid and ask every minute; every karat follows by purity.
- One **price calculator** (Technical Spec Part 3 §2, amended) that listings, buy requests and settlement will use.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES
Dashboard:    YES
Customer App: NO
Auth:         NO
Permissions:  YES
```

## Backend Impact

- `app/Support/Pricing`: `PriceCalculator` (pure, bcmath), value objects, `PricingContext` (loads price, adjustments,
  settings), `Settings` (the only way to read a tunable number), `FeedHealth`.
- `app/Actions/Pricing`: `EnterManualPriceAction`, `ConfirmManualPriceAction`, `RecordFeedPriceAction`,
  `ChangeSettingAction`, `ChangeKaratAdjustmentsAction`; `CreateKaratAction` (004) now gives a new karat zero adjustments.
- Price feed: `App\Contracts\GoldPriceFeed` → `App\Services\PriceFeed\ProviderGoldPriceFeed` (the two calls observed in the
  previous platform), command `pricing:pull-feed` scheduled every minute. Technical Spec Part 4 §1.
- Timezone: the app and the PostgreSQL session both run on `Africa/Cairo` (product-owner decision; previously the app was
  UTC and the local DB session +03, which shifted Eloquent timestamps).

## Database Impact

Migrations `2026_09_28_0000{10,20,30}`: `setting` + `setting_history` (schema §3, with reason and staff FKs),
`gold_price` (append-only, `recorded_by`), `manual_gold_price` (forward-only status), `price_feed_status`,
`karat_price_adjustment` + history (seeded −15 / +15 fixed per karat). Schema docs updated first (§3, §3b, staff FKs in 02).

## API Changes

All `/api/v1/dashboard`, non-breaking (new endpoints). See Part 2 §10 "Pricing" and the `#[OA]` attributes.
Error codes added: `no_gold_price` (409), `price_feed_healthy` (409), `manual_price_not_pending` (409),
`confirmer_must_differ` (403), `price_inverted` (422); `manual_price_confirm_required` in a `202` body.

## Dashboard Impact

- New: `types/pricing.ts`, `services/pricing.service.ts`, `composables/usePricing.ts`, `components/pricing/*`,
  `pages/pricing/index.vue` (Gold pricing), `pages/rates/index.vue` (Commission rates).
- Changed: `types/api.ts`, `api/endpoints.ts`, `PERMISSIONS` (+5), `services/errors.ts`, router, nav (Gold pricing and
  Commission rates unhidden, gated on `pricing.view`), Karats page ("Price today, sellers get" column).
- The Dashboard never computes prices: previews come from `POST /gold-prices/preview`.
- Left out: the Rapaport panel (not built); an "Other rules" panel lists settings the design has no place for.

## Customer App Impact

Not affected — decided 2026-09-27. Its prototype prices (`lib/services/pricing.dart`, `live_rates.dart`) stay until the
listings feature. **Backend requirement for that feature:** a `/customer/*` read endpoint for each enabled karat's
published prices (and a manual-price banner flag).

## Authentication / Authorization

Staff surface only. `pricing.view` · `pricing.rates.manage` · `settings.manage` · `gold_price.enter` ·
`gold_price.confirm`. Setting changes check the key's group permission. Every change needs a reason and is audited.

## Permissions

| Code | Seed roles (+ ceo) |
|---|---|
| `pricing.view` | coo, finance, operations |
| `pricing.rates.manage` | finance |
| `settings.manage` | coo |
| `gold_price.enter` | finance |
| `gold_price.confirm` | finance |

## Validation

Backend (authoritative): setting type and range from the catalogue; prices > 0 with ask ≥ bid, ≤ 4 decimals;
adjustments fixed or percent (> −100) and never inverting a karat at the current price. The Dashboard mirrors the
simple format rules only.

## Error Handling

Each new code has one sentence in `components/pricing/pricingErrors.ts`; the rest read as on the access-control screens.

## UI States

Gold pricing: loading, error with retry, "No gold price yet", pending banner with confirm (disabled for the entrant
when the setting says so), feed state (healthy / down / not connected). Commission rates: loading, error, per-panel
save with reason, discard. Karats: "No price yet" per karat.

## Testing

- Backend: `tests/Unit/Pricing/PriceCalculatorTest.php` (Part 3 §3.3/§3.4 to the piastre, percent, diamond,
  gold-with-diamond, minimum, waiver, rounding), `tests/Feature/Pricing/*` (settings catalogue, context, manual price,
  feed with `Http::fake`, rates, adjustments, current prices); OpenAPI and principal-isolation lists updated.
- Dashboard: type-check, lint (changed files), build; browser check of Gold pricing, Commission rates and Karats.
- Postman: **Dashboard → Pricing**.

## Breaking Changes

None.

## Migration / Compatibility

Deploy the Backend first:
1. `php artisan migrate`.
2. `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder` (adds the 5 permissions to `ceo` and their seed roles).
3. Set `APP_TIMEZONE=Africa/Cairo` and the `GOLD_FEED_*` keys in `.env` (credentials never committed).
4. Make sure `php artisan schedule:run` runs every minute.

Then deploy the Dashboard. Open items before production (Part 4 §1.5): confirm the provider's identity, verify the unit on
staging, rotate the provider password.
