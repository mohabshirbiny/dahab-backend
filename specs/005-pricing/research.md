# Research: Pricing — Settings, Gold Prices, Price Feed, Price Math

**Feature**: [spec.md](./spec.md) · **Date**: 2026-09-27

## R1. Settings tables as the schema, with a reason column

**Decision**: Migrate `setting` and `setting_history` from `docs/Database schema/01_schema_core.sql` §3 verbatim. Additions, recorded in the schema doc first (Constitution III):
- `setting.updated_by REFERENCES staff(staff_id)` (the schema says "FK added in Part 4").
- `setting_history.reason TEXT NOT NULL` and `changed_by REFERENCES staff(staff_id)`: the screens show "who and why" from history without joining the audit log.
- `setting_history` is append-only (the same `BEFORE UPDATE OR DELETE` trigger pattern as `audit_log`).

**Catalogue**: a code enum `SettingKey` holds, per key: value type (numeric / bool), unit, permission group (`rates` / `operations`), and allowed range. The database holds the values. A key missing from the enum cannot be changed (404); a key missing from the table is a migration bug (a test compares the two lists).

**Seed**: the schema's INSERT list **without** `price_correction.buy_side` / `price_correction.sell_side` (they become per-karat adjustments, R3), plus:

| Key | Value | Unit | Group |
|---|---|---|---|
| `manualprice.confirmer_must_differ` | true | bool | rates |
| `manualprice.pending_expiry_hours` | 24 | hours | rates |
| `pricefeed.stale_after_minutes` | 5 | minutes | rates |

The design's "Total exposed to first-sale payouts" has no documented value; it is left to the first-sale feature (no invented number).

**Groups** (permission to change):
- `rates` (Finance + CEO; Part 1 §4.2 "Change commission or spread rates" and the money rows): `commission.*`, `vat.pct`, `manualprice.*`, `pricefeed.*`, `compensation.*`, `payout.first_sale_cap_egp`.
- `operations` (founders; not in the matrix → founders only): `deposit.*`, `deadline.*`, `withdrawal.*`, `inspection.*`, `marketmaker.*`, `suspension.*`, `flag.*`.

## R2. Gold price record: immutable prices, separate manual requests

**Decision**: Two new tables (schema doc first):
- `gold_price`: **append-only** facts. Columns: `gold_price_id BIGSERIAL`, `source TEXT CHECK (source IN ('feed','manual'))`, `bid_24k NUMERIC(18,4)`, `ask_24k NUMERIC(18,4)`, `manual_gold_price_id` (FK, required when `source = 'manual'`), `recorded_by UUID NOT NULL REFERENCES staff` (Constitution I: the system actor for feed rows, the confirmer or entrant for manual rows), `effective_at TIMESTAMPTZ`, `created_at`. Checks: `bid_24k > 0`, `ask_24k >= bid_24k`. An update/delete trigger refuses changes.
- `manual_gold_price`: the manual request and its life (it changes state, so it is not the price itself). Columns: bid, ask, `previous_gold_price_id`, `deviation_pct`, `reason NOT NULL`, `entered_by`, `status` (`pending`, `effective`, `superseded`, `lapsed`), `requires_confirmation`, `confirmed_by`, `confirmed_at`, `expires_at`, `created_at`. The state only moves forward (a trigger refuses any other change).

The spec's "status" of a price is the manual request's status; a `gold_price` row always means "took effect at `effective_at`". This mirrors the MySQL design (`gold_rate_snapshot` + `manual_gold_price`) on PostgreSQL.

**Current price**: the `gold_price` row with the latest `effective_at` (ties broken by id).

**Deviation**: `max(|bid − cur_bid| / cur_bid, |ask − cur_ask| / cur_ask) × 100`, 4 dp. With no current price, the deviation is null and no confirmation is needed.

**Concurrency**: manual entry, confirmation and the feed write take `pg_advisory_xact_lock(hashtext('dahab.gold_price'))`, so the "current" used for the deviation is the one at write time. When a new `gold_price` row takes effect, every older `pending` request becomes `superseded` in the same transaction.

**Alternatives considered**: one table with a mutable status (breaks "never edited"); snapshotting per karat (rejected by Clarification: karats derive from purity).

## R3. Per-karat adjustments

**Decision**: `karat_price_adjustment` (`karat_code` FK, `side` `buy`/`sell`, `kind` `fixed`/`percent`, `value NUMERIC(18,4)`, `updated_by`, `updated_at`, PK `(karat_code, side)`) and an append-only `karat_price_adjustment_history` (old/new kind and value, `changed_by`, `reason`, `changed_at`).

- The migration seeds both sides for every existing karat from the schema's values: buy −15 fixed, sell +15 fixed.
- A karat created later (spec 004 `CreateKaratAction`) gets **fixed 0 on both sides**. It starts off, and Finance sets its adjustments before turning it on. Copying −15/+15 would put a literal in code (Constitution: data not code). The spec's edge case is updated to match.
- Apply: `fixed` → `price + value`; `percent` → `price × (1 + value / 100)`.
- A change is refused (422 `price_inverted`) if, at the current market price, it makes buyers-pay < sellers-get or any price ≤ 0 for that karat. With no current price, only the ranges are checked (fixed: any sign; percent: > −100).

## R4. Karat market prices and the Part 3 §2 amendment

**Decision**: `market_bid(k) = bid_24k × purity(k) ÷ 0.999`; `market_ask(k)` likewise. `sellers_get(k) = adjust(market_bid(k), buy)`; `buyers_pay(k) = adjust(market_ask(k), sell)`. All kept at 4 dp (half-up) per gram.

Part 3 §2 is rewritten on these terms:
- gold value (seller) = `sellers_get(k) × W`; gold value (buyer) = `buyers_pay(k) × W`;
- spread = `(buyers_pay(k) − sellers_get(k)) × W`;
- the gold protected inside **gold-with-diamond** = `mid(k) × W` with `mid = (market_bid + market_ask) / 2`, unadjusted: this replaces "the plain rate R", which no longer exists once the market gives two prices.

**Worked examples**: Part 3 §3.3/§3.4 are reproduced exactly with `bid_24k = ask_24k = 5,994` (= 6,000 per pure gram × 0.999) and 21K fixed adjustments −13.125 / +13.125 (= ±15 per pure gram × 0.875). The Part 3 doc gets a note that the old ±15 "per gram of pure gold" equals ±15 × purity per gram of the karat. New examples are added: percentage adjustments, a real bid/ask gap, a pure diamond, gold with diamond, a commission at the minimum, and a waiver.

## R5. The price feed

**Decision**: interface `App\Contracts\GoldPriceFeed::fetch(): GoldQuote` (bid, ask, fetched_at). Implementation `ProviderGoldPriceFeed` over Laravel HTTP, using what the previous platform's command did:
1. `POST {base}/v1/auth` `{username, password}` → `access_token`;
2. `GET {base}/v1/datafeed/METAL_PRICE_TYPE_EGY/xau/price` with Bearer → `bidPrice`, `askPrice`.

- **Credentials policy (product owner, 2026-09-27)**:
  - Config reads `config/services.php` → `gold_feed` (`base_url`, `username`, `password`, `timeout`) from `GOLD_FEED_BASE_URL`, `GOLD_FEED_USERNAME` and `GOLD_FEED_PASSWORD`, set in **`.env` only**. `.env.example` has the keys **empty**.
  - No credential, not even a staging one, appears in code, docs, tests, fixtures, Postman or Git. Tests use `Http::fake` with dummy values.
  - The staging check (quickstart 7) runs only after the product owner has put the credentials in `.env` themselves.
  - **TODO (product owner)**: rotate the provider password. The old one appeared in the previous platform's code and in chat.
- The token is cached for 10 minutes (its lifetime is unknown). A 401 re-authenticates once.
- Timeout 10 s; no retries inside a tick (the next minute is the retry).
- The answer is refused if a price is missing, non-numeric, ≤ 0, or ask < bid.
- **TODO — provider identity pending confirmation**: the Technical Spec names the provider "Evolve"; the old code's host is `exp-par-stg.mngm.com`. They are **not assumed to be the same**.
  - Code, config and docs say "the gold price provider" (`ProviderGoldPriceFeed`, `gold_feed`).
  - The integration's behaviour comes only from the two calls observed in the old code, never from the provider's name.
  - When the product owner confirms the provider (and supplies its API documentation), Part 4 is updated. Any difference from the observed calls is a new task, not an assumption.

**Command**: `pricing:pull-feed`, scheduled `everyMinute()->withoutOverlapping()` in `routes/console.php`. It runs inside `DatabaseActor::elevate('system')` and:
- takes the lock;
- writes a `feed` row only if bid or ask differ from the current price;
- marks older pending manual requests `superseded` (they belong to the "feed down" period);
- updates `price_feed_status`.

A failure writes nothing but `last_failure_at` / `last_error` (no credentials in the message).

**Health**: a one-row table `price_feed_status` (`provider PK`, `last_success_at`, `last_failure_at`, `last_error`). It is kept in the database, not the cache, because "feed down" gates a money action. Down = not configured, or `last_success_at` older than `pricefeed.stale_after_minutes`.

**Unit assumption**: EGP per gram of 24K (the old command multiplied it by karat ÷ 24). It is recorded in the new Technical Spec Part 4 and checked against staging with the product owner's go-ahead before release.

**Audit**: feed rows are not audit-logged each minute. The `gold_price` row is the record, attributed to the system actor through `recorded_by`. Manual entries, confirmations and adjustment/setting changes are.

## R6. Calculator

**Decision**: a pure `App\Support\Pricing\PriceCalculator`, using bcmath strings at scale 8 and rounding half-up to 4 dp at each published amount (Part 3 §3.5). Inputs are value objects:
- `MarketPrice` (bid24, ask24);
- `KaratPricing` (code, purity, buy adjustment, sell adjustment);
- `PricingRates` (gold %, stone %, minimum, VAT %);
- `Piece` (category, karat, weight, making per g, asking price, commission waived).

It returns `KaratPrices` and `PriceBreakdown`. `spread` and `seller_proceeds` are derived by subtraction from the rounded totals, so the Part 3 §3.5 residue goes to the spread and the identity holds exactly (data-model). A thin `PricingContext` loads the current price, adjustments and settings from the database for callers.

It refuses with `no_gold_price` (409) when there is no price, and `price_inverted` (422) when a karat's published prices are inverted.

**Alternatives**: brick/money (a new dependency, not needed at this size); floats (rejected, money).

## R7. Permissions (catalogue codes, spec 002)

| Code | Label | Seed roles (+ ceo) |
|---|---|---|
| `pricing.view` | View prices, adjustments and settings | coo, finance, operations |
| `pricing.rates.manage` | Change commission, VAT, adjustments and price rules | finance |
| `settings.manage` | Change deadlines, deposits and thresholds | coo |
| `gold_price.enter` | Enter a gold price manually | finance |
| `gold_price.confirm` | Confirm a manual gold price | finance |

The Karats page reads prices through `GET /dashboard/gold-prices/current` when the viewer holds `pricing.view`. The 004 karat contract is unchanged.

## R8. Endpoints (Dashboard, `auth:staff` + `staff.standing`)

| Method | Path | Permission |
|---|---|---|
| GET | `/dashboard/settings` | `pricing.view` |
| PATCH | `/dashboard/settings/{key}` `{value, reason}` | by group: `pricing.rates.manage` or `settings.manage` |
| GET | `/dashboard/settings/history?key=&page=` | `pricing.view` |
| GET | `/dashboard/gold-prices/current` | `pricing.view` |
| GET | `/dashboard/gold-prices?page=` | `pricing.view` |
| POST | `/dashboard/gold-prices/preview` `{bid, ask}` or `{change_pct}`, optional adjustments | `pricing.view` |
| POST | `/dashboard/gold-prices/manual` `{bid, ask}` or `{change_pct}`, `reason` | `gold_price.enter` |
| POST | `/dashboard/gold-prices/manual/{id}/confirm` | `gold_price.confirm` |
| PUT | `/dashboard/karats/{code}/adjustments` `{buy:{kind,value}, sell:{kind,value}, reason}` | `pricing.rates.manage` |
| GET | `/dashboard/price-adjustments/history?karat=&page=` | `pricing.view` |

**Manual entry answers**:
- `201` when the price takes effect;
- `202` with `data.status = "pending"` and `code: "manual_price_confirm_required"` when it needs confirmation. Approved by the product owner, 2026-09-27: the request is recorded, so it was accepted. Part 2 §10 is updated;
- `409 price_feed_healthy` while the feed is up.

**Confirm answers**:
- `403 confirmer_must_differ`;
- `409 manual_price_not_pending` (superseded, lapsed, already effective).

The preview is server-side, so the Dashboard never re-implements the price maths (CLAUDE.md "don't duplicate Backend business rules").

## R9. Documentation changes (before code)

- **Schema**: `01_schema_core.sql` and `00_schema_full.sql` get:
  - the setting FKs and the `reason` column;
  - the four new tables;
  - the `price_correction.*` seed replaced by a note pointing to `karat_price_adjustment`;
  - the new setting keys.
- **Technical Spec Part 3 §2**: amended for bid/ask, per-karat adjustments and the gold-with-diamond mid price.
- **Technical Spec Part 4**: a new file, `dahab-spec-part4-integrations.md`. Only the price-feed section is written; the other integrations are left as open items.
- **Part 2 §10**: as-built routes.
- `docs/features/pricing.md`, Postman **Dashboard → Pricing**, and `.env.example`.
