# Contract: Dashboard pricing endpoints (planning artefact — code and `#[OA]` win)

All under `/api/v1/dashboard`, guard `auth:staff`, ability `staff:access`, `staff.standing`, scheme `dashboardBearer`. Envelope `{data}`; errors `{message, code, errors?}`. Money is a decimal **string** with 4 places (e.g. `"6951.0000"`).

## Settings
- `GET /settings` (`pricing.view`) → `data: Setting[]`, ordered by group then key
  - `Setting = { key, group: "rates"|"operations", type: "numeric"|"bool", value: string|bool, unit, description, min?: string, max?: string, updated_by: {id, full_name}|null, updated_at }`
- `PATCH /settings/{key}` `{ value, reason }` → `200 Setting`
  - the group permission is `pricing.rates.manage` (rates) or `settings.manage` (operations): `403 permission_denied` without it
  - `404 not_found` for an unknown key
  - `422 validation_failed` (range, type) · `422 reason_required`
- `GET /settings/history?key=&page=&per_page=` (`pricing.view`) → paginated `SettingChange[] { id, key, old_value, new_value, changed_by: {id, full_name}, reason, changed_at }`, newest first

## Gold prices
- `GET /gold-prices/current` (`pricing.view`) →
  ```
  data: {
    price: { id, source: "feed"|"manual", bid_24k, ask_24k, effective_at,
             entered_by: {id, full_name}|null, reason: string|null } | null,
    feed: { state: "healthy"|"down"|"not_configured", last_success_at, last_failure_at },
    pending: ManualPrice | null,
    karats: [{ code, purity, is_enabled, market_bid, market_ask, sellers_get, buyers_pay, difference,
               adjustments: { buy: {kind, value}, sell: {kind, value} }, inverted }]
  }
  ```
  `karats` is empty when there is no price.
- `GET /gold-prices?page=&per_page=` (`pricing.view`) → paginated history of `price` objects, newest first
- `POST /gold-prices/preview` (`pricing.view`) `{ bid_24k, ask_24k }` or `{ change_pct }`, optional `adjustments: { <code>: { buy?, sell? } }` → `200 { karats: [...] as above, deviation_pct }`. Writes nothing. `409 no_gold_price` for a `change_pct` with no current price.
- `POST /gold-prices/manual` (`gold_price.enter`) `{ bid_24k, ask_24k }` or `{ change_pct }`, plus `reason` →
  - `201 { data: ManualPrice }` with status `effective`
  - `202 { data: ManualPrice, code: "manual_price_confirm_required" }` with status `pending`
  - `409 price_feed_healthy` · `422 validation_failed` (ask < bid, ≤ 0) · `422 reason_required`
  - `ManualPrice = { id, bid_24k, ask_24k, deviation_pct, requires_confirmation, status, reason, entered_by, confirmed_by, confirmed_at, expires_at, created_at }`
- `POST /gold-prices/manual/{id}/confirm` (`gold_price.confirm`) → `200 ManualPrice` (effective)
  - `403 confirmer_must_differ` · `409 manual_price_not_pending` · `404 not_found`

## Adjustments
- `PUT /karats/{code}/adjustments` (`pricing.rates.manage`) `{ buy: {kind: "fixed"|"percent", value}, sell: {...}, reason }` → `200 { code, adjustments, sellers_get, buyers_pay, difference }`
  - `422 price_inverted` · `422 validation_failed` · `422 reason_required` · `404 not_found`
- `GET /price-adjustments/history?karat=&page=` (`pricing.view`) → paginated `{ id, karat_code, side, old: {kind,value}, new: {kind,value}, changed_by, reason, changed_at }`

## Error codes added
`no_gold_price` (409), `price_inverted` (422), `price_feed_healthy` (409), `manual_price_not_pending` (409), `confirmer_must_differ` (403), and `manual_price_confirm_required` (in a 202 body).
